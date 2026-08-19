"""P2-42 / ALIGN-08 — « cette ÉQUIPE ne s'entraîne pas N jours d'affilée » est-elle HONORÉE ?

Test SÉMANTIQUE (axe §7.1 « sémantique de contrainte ») : on ne vérifie pas qu'un champ est
lu, on vérifie que le planning rendu obéit. Une contrainte saisie qui n'agit pas est le motif
récurrent du produit (ENG-10/11/12/13/16) — celle-ci naît gardée.

Le montage : une équipe à 3 séances/semaine, et un gymnase qui n'offre QUE trois jours
consécutifs (lundi, mardi, mercredi). Sans la règle, le solveur les prend tous les trois —
c'est le seul moyen d'honorer le quota. Avec la règle en HARD, il ne PEUT plus, et doit
sacrifier une séance : c'est précisément le prix que la règle fait payer, et le test l'exige
plutôt que de l'ignorer.
"""

from __future__ import annotations

from typing import Any

from tests.support.pipeline import make_payload, make_team, make_venue, solve_payload


def _days_used(result: dict[str, Any], team_id: str) -> list[int]:
    return sorted(int(s["dayOfWeek"]) for s in result["slots"] if s["teamId"] == team_id)


def _solve(implicit_rules: dict[str, Any] | None) -> dict[str, Any]:
    payload = make_payload(
        teams=[make_team("sm1", sessions_per_week=3, priority_tier_id=1)],
        # Trois jours CONSÉCUTIFS et rien d'autre : le seul planning à 3 séances est la suite.
        venues=[make_venue("gym", [(1, "18:00"), (2, "18:00"), (3, "18:00")])],
        implicit_rules=implicit_rules,
    )
    result = solve_payload(payload, timeout=10)
    assert result["status"] == "completed", result.get("status")
    return result


def test_without_the_rule_the_solver_uses_the_three_consecutive_days() -> None:
    """Le témoin. Sans lui, un test vert ne prouverait rien : il faut d'abord établir que le
    solveur PREND bien les trois jours quand rien ne l'en empêche."""
    days = _days_used(_solve(None), "sm1")

    assert days == [1, 2, 3], f"le témoin doit occuper les 3 jours consécutifs, obtenu {days}"


def test_hard_the_team_never_trains_three_days_in_a_row() -> None:
    """HARD : la suite devient impossible. L'équipe perd une séance — c'est le prix de la
    garantie, et il est assumé : un gestionnaire qui active cette règle demande explicitement
    du repos plutôt que du volume."""
    days = _days_used(_solve({"maxConsecutiveDays": {"intensity": "HARD", "maxConsecutiveDays": 3}}), "sm1")

    assert len(days) <= 2, f"3 séances sur 3 jours consécutifs restent possibles : {days}"
    for first in days:
        assert not {first, first + 1, first + 2}.issubset(set(days)), f"suite de 3 trouvée : {days}"


def test_a_threshold_of_two_forbids_even_two_days_in_a_row() -> None:
    """Le seuil est un vrai réglage, pas un décor : à 2, deux jours de suite suffisent à
    violer. Falsifie l'hypothèse « le seuil est ignoré et la règle tape toujours à 3 »."""
    days = _days_used(_solve({"maxConsecutiveDays": {"intensity": "HARD", "maxConsecutiveDays": 2}}), "sm1")

    for first in days:
        assert first + 1 not in days, f"deux jours consécutifs malgré un seuil de 2 : {days}"


def test_preferred_keeps_the_sessions_and_pays_the_malus_instead() -> None:
    """PREFERRED ne garantit rien — et c'est la différence qu'un gestionnaire doit pouvoir
    choisir. Le quota (≥ 21 par séance) bat le malus (−6), donc les 3 séances SURVIVENT là où
    HARD en supprimait une. Si ce test devenait identique au HARD, c'est que l'intensité
    n'aurait plus d'effet."""
    days = _days_used(_solve({"maxConsecutiveDays": {"intensity": "PREFERRED", "maxConsecutiveDays": 3}}), "sm1")

    assert days == [1, 2, 3], f"PREFERRED ne doit pas supprimer une séance (≥21 bat −6), obtenu {days}"
