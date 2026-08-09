"""ENG-25 — l'ordre des termes soft ne doit pas dépendre du hash du processus.

`add_preferred_day_bonus` agrège une fenêtre synthétique PAR ÉQUIPE, en itérant
sur une union d'ensembles dont les clés sont des `str`. Le hash des `str` est
randomisé PAR PROCESSUS (PYTHONHASHSEED) : sans tri, l'ordre d'itération changeait
d'un run à l'autre, donc l'ordre d'ajout des termes à l'objectif, donc le chemin
de recherche de CP-SAT. Deux générations du MÊME payload avec le MÊME `solverSeed`
pouvaient rendre deux affectations différentes — de valeur d'objectif identique,
mais un gestionnaire qui régénère à l'identique voyait son planning bouger.

Le test ne compare pas deux processus (ce serait faux-vert la moitié du temps :
rien ne garantit que deux seeds donnent des ordres différents). Il vérifie la
PROPRIÉTÉ qui rend le résultat reproductible : les termes sortent dans l'ordre
TRIÉ des équipes. Avec vingt équipes, la probabilité que l'ordre de hash coïncide
avec l'ordre trié est de 1/20! — autrement dit, retirer le `sorted()` fait rougir.
"""

from __future__ import annotations

from typing import Any

from app.solver.objective import add_preferred_day_bonus


class _RecordingVar:
    """Faux BoolVar : ne sert qu'à être reconnaissable dans les termes rendus."""

    def __init__(self, key: tuple[Any, ...]) -> None:
        self.key = key


def _team_id(index: int) -> str:
    # Des identifiants dont l'ordre lexicographique est stable et connu, mais
    # dont l'ordre de hash ne l'est pas.
    return f"team-{index:02d}-{'a' * (index % 5 + 1)}"


def test_preferred_day_terms_come_out_in_sorted_team_order() -> None:
    team_ids = [_team_id(i) for i in range(20)]

    # Une variable par (équipe, jour) : le terme porte la variable, donc l'ordre
    # des termes révèle l'ordre dans lequel les équipes ont été traitées.
    x = {(team_id, "venue-1", day, "18:00"): _RecordingVar((team_id, day)) for team_id in team_ids for day in (1, 2)}

    # Chaque équipe reçoit une règle PREFERRED/DAY — c'est l'agrégation par équipe
    # qui itère sur l'union d'ensembles incriminée.
    time_windows = [
        {
            "ruleType": "PREFERRED",
            "family": "DAY",
            "scopeTargetId": team_id,
            "config": {"preferredDays": [1]},
        }
        # Insertion volontairement DÉSORDONNÉE : l'ordre d'entrée ne doit rien
        # décider — seul le tri le fait.
        for team_id in reversed(team_ids)
    ]

    terms = add_preferred_day_bonus(None, x, time_windows, {"preferred_day": 5})

    seen: list[str] = []
    for variable, _weight_name in terms:
        team_id = variable.key[0]
        if team_id not in seen:
            seen.append(team_id)

    assert seen == sorted(team_ids), (
        "les termes soft sortent dans l'ordre du HASH des identifiants d'équipe, pas dans un "
        "ordre trié : cet ordre change à chaque processus (PYTHONHASHSEED), donc deux "
        "générations du même payload avec le même solverSeed peuvent rendre deux plannings "
        "différents. Rétablir le `sorted()` dans add_preferred_day_bonus (ENG-25)."
    )
