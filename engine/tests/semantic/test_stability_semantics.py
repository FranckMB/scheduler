"""NR sémantique — P3-21 PR A (axe §7.1 constraint semantics) : la stabilité DÉPARTAGE,
elle n'ARBITRE jamais.

Elle ne prime NI une contrainte HARD (un créneau précédent devenu interdit BOUGE), NI un
écart de score réel (un meilleur confort BOUGE malgré la stabilité perdue). Chaque cas est
falsifié dans les deux sens : le contrôle POSITIF (sans l'obstacle, la stabilité restitue
bien le créneau précédent) prouve que le terme est ACTIF, pas inerte — sans quoi « le
créneau a bougé » ne prouverait rien.
"""

from __future__ import annotations

from typing import Any

from tests.support import make_payload, make_team, make_venue, solve_payload, team_constraint

TEAM = "team-d"
VENUE = "venue-1"
DAY_A = 1
DAY_B = 3


def _placed_day(result: dict[str, Any]) -> int:
    days = [int(s["dayOfWeek"]) for s in result["slots"] if str(s["teamId"]) == TEAM]
    assert len(days) == 1, f"exactement une séance attendue, obtenu {days}"
    return days[0]


def _prev_a() -> list[dict[str, Any]]:
    return [{"teamId": TEAM, "venueId": VENUE, "dayOfWeek": DAY_A, "startTime": "18:00"}]


# --- Cas 1 : un créneau précédent devenu HARD-interdit BOUGE ---------------------------


def _payload_two_days(constraints: list[dict[str, Any]] | None = None) -> dict[str, Any]:
    return make_payload(
        teams=[make_team(TEAM, sessions_per_week=1, priority_tier_id=5)],
        venues=[make_venue(VENUE, [(DAY_A, "18:00"), (DAY_B, "18:00")])],
        constraints=constraints,
    )


def test_hard_forbidden_beats_stability() -> None:
    """previous = jour A ; interdire A (HARD) → la séance passe au jour B."""
    # Contrôle positif : sans l'interdit, la stabilité restitue bien A.
    control = _payload_two_days()
    control["previousAssignments"] = _prev_a()
    assert _placed_day(solve_payload(control, timeout=10)) == DAY_A, "contrôle : la stabilité fixe bien A"

    forbid_a = [
        team_constraint(
            constraint_id="forbid-a",
            team_id=TEAM,
            family="DAY",
            rule_type="HARD",
            config={"forbiddenDays": [DAY_A]},
        )
    ]
    payload = _payload_two_days(constraints=forbid_a)
    payload["previousAssignments"] = _prev_a()
    result = solve_payload(payload, timeout=10)
    assert result["status"] == "completed"
    assert _placed_day(result) == DAY_B, "une contrainte HARD prime la stabilité : le créneau bouge"


# --- Cas 2 : un écart de SCORE réel BOUGE malgré la stabilité perdue --------------------


def test_real_score_gap_beats_stability() -> None:
    """previous = jour A (18:00) ; une PREFERRED TIME favorise le jour B (20:00) →
    le placement optimal est B (confort +5), verrouillé en phase 1 ; la stabilité (+1)
    sur A ne peut pas le renverser."""
    venues = [make_venue(VENUE, [(DAY_A, "18:00"), (DAY_B, "20:00")])]

    # Contrôle positif : sans préférence, les deux créneaux sont symétriques → A restitué.
    control = make_payload(
        teams=[make_team(TEAM, sessions_per_week=1, priority_tier_id=5)],
        venues=venues,
    )
    control["previousAssignments"] = _prev_a()
    assert _placed_day(solve_payload(control, timeout=10)) == DAY_A, "contrôle : symétrie → stabilité fixe A"

    prefer_late = [
        team_constraint(
            constraint_id="prefer-late",
            team_id=TEAM,
            family="TIME",
            rule_type="PREFERRED",
            config={"minStartTime": "19:00"},
        )
    ]
    payload = make_payload(
        teams=[make_team(TEAM, sessions_per_week=1, priority_tier_id=5)],
        venues=venues,
        constraints=prefer_late,
    )
    payload["previousAssignments"] = _prev_a()
    result = solve_payload(payload, timeout=10)
    assert result["status"] == "completed"
    assert _placed_day(result) == DAY_B, "un écart de score réel prime la stabilité : le créneau bouge"
