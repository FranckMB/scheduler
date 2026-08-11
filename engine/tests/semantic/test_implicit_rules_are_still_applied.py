"""NR — P4-55. L'écran ANNONCE six règles structurelles : elles doivent rester appliquées.

Le wizard porte désormais un encart « ce que le système applique déjà, sans que vous le
saisissiez » (`frontend/src/features/wizard/steps/ImplicitRulesPanel.tsx`). Il affirme six
comportements du solveur que le gestionnaire ne saisit nulle part.

⚠ **Le risque que ce test couvre est celui d'une AFFIRMATION devenue fausse**, pas celui d'un
solveur cassé. Si l'une de ces six règles disparaît du moteur — retirée, ou passée derrière
une condition — rien ne rougirait côté frontend : son gel Vitest continuerait de figer un
texte que plus personne n'honore. Le produit annoncerait une garantie qu'il ne tient plus,
et c'est exactement le motif « contrainte saisie ≠ contrainte honorée » qui a coûté ENG-10 à
ENG-13, ici retourné du côté de l'implicite.

On vérifie donc que `add_level_1_hard_constraints` appelle les six, **sans condition**, dans
son appel par défaut. Le mock est délibéré : la sémantique de chaque règle a déjà ses propres
tests (`test_coach_rest_day.py`, `test_capacity_slots.py`, la matrice sémantique…). Ce qu'AUCUN
d'eux ne garde, c'est le fait qu'elles soient toujours BRANCHÉES — une fonction parfaitement
testée mais plus appelée passe tous les tests du monde.

⚠ Les trois règles délibérément TUES par l'encart (`age_ascending`, `salarie_distribution`,
`max_consecutive_sessions`) ne sont pas listées ici : ce test garde l'accord entre l'écran et
le moteur, pas l'inventaire du moteur. Les ajouter ferait rougir le jour où on les retirerait
du solveur alors que l'écran n'en parle pas — un faux positif.
"""

from __future__ import annotations

from pathlib import Path
from typing import Any
from unittest.mock import DEFAULT, patch

import pytest

from app.solver import constraints as C

# Les six fonctions correspondant, dans l'ordre, aux six entrées de `IMPLICIT_RULES`.
# Le libellé est celui de l'encart : il rend la panne lisible sans ouvrir le frontend.
ANNOUNCED_BY_THE_UI = [
    ("add_room_at_most_one", "Un gymnase ne dépasse jamais sa capacité"),
    ("add_coach_at_most_one", "Un coach n'est jamais dans deux gymnases à la fois"),
    ("add_coach_player_non_overlap", "Une personne ne peut pas encadrer et jouer en même temps"),
    ("add_team_no_overlap", "Une équipe n'a jamais deux séances en même temps"),
    ("add_one_session_per_day_constraints", "Au plus une séance par jour et par équipe"),
    ("add_coach_rest_day_constraints", "Chaque coach garde un jour de repos"),
]


@pytest.mark.parametrize(("function_name", "ui_label"), ANNOUNCED_BY_THE_UI)
def test_the_rule_the_ui_announces_is_still_wired(function_name: str, ui_label: str) -> None:
    with patch.object(C, function_name) as spied:
        C.add_level_1_hard_constraints(object(), assignments=[])

    assert spied.called, (
        f"`{function_name}` n'est plus appelée par add_level_1_hard_constraints, "
        f"alors que le wizard affiche toujours « {ui_label} ». "
        "Soit on la remet, soit on retire la ligne de IMPLICIT_RULES "
        "(frontend/src/features/wizard/steps/ImplicitRulesPanel.tsx) — jamais le silence."
    )


def test_the_rest_day_rule_is_on_by_default() -> None:
    """Le repos du coach est le seul des six à vivre derrière un drapeau.

    ``skip_rest_day_and_distribution`` existe encore dans la signature. Aucun appelant
    applicatif ne le passe à ``True`` — mais l'encart affirme la règle sans réserve, donc
    c'est le DÉFAUT qui doit rester « appliquée ». Basculer ce défaut rendrait l'écran faux
    sans casser aucun autre test.
    """
    with patch.object(C, "add_coach_rest_day_constraints") as spied:
        C.add_level_1_hard_constraints(object(), assignments=[])

    assert spied.called, (
        "le défaut de skip_rest_day_and_distribution doit rester False — l'encart annonce le jour de repos sans condition"
    )


def test_no_applicative_caller_relaxes_the_rest_day_rule() -> None:
    """ADR-0001 : single-pass, AUCUN fallback de relaxation — et on le vérifie sur le CODE.

    Le docstring de ``add_level_1_hard_constraints`` a longtemps décrit un « two-pass
    fallback » qui abandonnait repos-coach et distribution-salariés quand la passe 1 rendait
    INFEASIBLE. Ce chemin n'existe pas, et c'est cette doc fausse qui a failli faire publier
    au gestionnaire une règle présentée comme abandonnable.

    ⚠ La première version de ce test cherchait la CHAÎNE « two-pass fallback » dans le
    docstring. Elle rougissait sur le docstring CORRIGÉ, qui cite l'expression pour la
    démentir — un test qui interdit de nommer l'erreur qu'on répare. On garde donc
    l'invariant réel, vérifiable et stable : **aucun appelant applicatif ne relaxe la règle**.
    Le jour où un vrai fallback est réintroduit, c'est ici que ça rougit, et c'est l'ADR
    qu'il faut rouvrir d'abord.
    """
    # Le module qui DÉFINIT le paramètre est exclu : il le nomme forcément (signature,
    # branches, et le docstring qui explique justement que le fallback n'existe pas).
    # Ce sont les APPELANTS qui décideraient de relaxer.
    definition_site = Path(C.__file__).resolve()
    app_dir = definition_site.parent.parent
    culprits = sorted(
        path.relative_to(app_dir).as_posix()
        for path in app_dir.rglob("*.py")
        if path.resolve() != definition_site
        and "skip_rest_day_and_distribution=True" in path.read_text(encoding="utf-8").replace(" ", "")
    )

    assert culprits == [], (
        f"ces modules applicatifs relaxent le repos du coach : {culprits}. "
        "ADR-0001 pose un solve single-pass SANS relaxation (INFEASIBLE → failed + diagnostics), "
        "et depuis P4-55 l'encart du wizard annonce cette règle sans réserve."
    )


def test_the_six_are_wired_together_not_one_by_one() -> None:
    """Le filet d'ensemble : un seul appel par défaut doit les déclencher TOUTES.

    Les cas paramétrés ci-dessus mockent une fonction à la fois. Si quelqu'un rendait
    l'appel de l'une conditionnel à l'absence d'une autre, chaque cas isolé resterait vert.
    """
    patches: dict[str, Any] = {}
    with patch.multiple(C, **{name: DEFAULT for name, _ in ANNOUNCED_BY_THE_UI}) as mocks:
        patches = mocks
        C.add_level_1_hard_constraints(object(), assignments=[])

    silent = sorted(name for name, _ in ANNOUNCED_BY_THE_UI if not patches[name].called)
    assert silent == [], f"ces règles annoncées par le wizard n'ont pas été appliquées : {silent}"
