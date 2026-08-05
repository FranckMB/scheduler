"""NR — un terrain divisible en 3 (retour fondateur 2026-08-05, cas ADN).

La chaîne capacité était déjà générique (schéma `ge=1`, modèle CP-SAT) — seul
le sélecteur UI plafonnait à 2. Ce test épingle la sémantique côté solveur :
capacité 3 ⇒ trois équipes peuvent partager le créneau, jamais quatre.
"""

from __future__ import annotations

from typing import Any

from tests.support.pipeline import make_payload, make_team, make_venue, solve_payload


def _count_on(result: dict[str, Any], day: int, start: str) -> int:
    return sum(1 for slot in result["slots"] if slot["dayOfWeek"] == day and str(slot["startTime"]).startswith(start))


def test_capacity_three_hosts_three_teams_never_four() -> None:
    # Un SEUL créneau, capacité 3, quatre équipes à 1 séance : trois s'y posent,
    # la quatrième reste dehors (le sur-remplissage serait une violation dure).
    payload = make_payload(
        teams=[make_team(f"T{i}", sessions_per_week=1) for i in range(1, 5)],
        venues=[make_venue("gym", [(6, "18:00")], capacity=3)],
    )

    result = solve_payload(payload)

    assert result["status"] != "failed", f"solve failed: {result['status']}"
    assert _count_on(result, 6, "18:00") == 3, "capacité 3 = trois équipes sur le créneau, jamais quatre"
