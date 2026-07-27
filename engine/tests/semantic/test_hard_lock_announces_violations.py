"""P2-9 — a HARD lock stays sovereign, but no longer annuls constraints in SILENCE.

A HARD lock is pre-placed OUTSIDE the solver: `model.py` never creates the
`x[team, venue, day, start]` variable for it (`model.py`, the `continue` on
`hard_slot_keys`). Every constraint works by forcing that variable to 0, so with
no variable there is nothing to force — the constraint is not *beaten*, it is
unreachable.

Measured before this behaviour existed, on the very payload of `test_control`
below: without the lock SM1 landed on Tuesday (the coach is off on Saturday, and
the constraint was honoured); WITH the lock it landed on Saturday, `diagnostics`
empty, `warnings` None, status `completed`. The product asserted it had honoured
a constraint it had silently dropped.

Founder ruling (2026-07-27): the lock REMAINS sovereign — that is ALIGN-07 and it
is not reopened here. What must change is the silence, so the manager can see
what his pin overrode and decide. Hence INFO warnings, not errors and not a
placement change.

Structuring axis `constraint semantics` (CLAUDE.md §7.1).
"""

from __future__ import annotations

from typing import Any

from tests.support.pipeline import make_payload, make_team, make_venue, solve_payload

SATURDAY = 6
TUESDAY = 2


def _coach_link(team_id: str, coach_id: str) -> dict[str, Any]:
    """A MAIN team↔coach link. `teamId` rides at the ROOT — the parser reads it there."""
    return {
        "id": f"tc-{team_id}-{coach_id}",
        "isActive": True,
        "type": "TEAM_COACH",
        "teamId": team_id,
        "config": {"coachId": coach_id},
    }


def _coach_off(coach_id: str, day: int) -> dict[str, Any]:
    return {
        "id": f"off-{coach_id}-{day}",
        "isActive": True,
        "family": "COACH_AVAILABILITY",
        "scopeTargetId": coach_id,
        "config": {"unavailableDays": [day]},
    }


def _hard_lock(team_id: str, venue_id: str, day: int, start: str) -> dict[str, Any]:
    return {
        "id": f"lock-{team_id}-{day}-{start}",
        "teamId": team_id,
        "venueId": venue_id,
        "dayOfWeek": day,
        "startTime": start,
        "durationMinutes": 90,
        "lockLevel": "HARD",
    }


def _days_of(result: dict[str, Any], team_id: str) -> list[int]:
    return [s["dayOfWeek"] for s in result["slots"] if s["teamId"] == team_id]


def _violation_messages(result: dict[str, Any]) -> list[str]:
    return [
        str(d.get("message", ""))
        for d in result.get("diagnostics") or []
        if d.get("type") == "constraint_not_honored"
    ]


def _payload(*, locked: bool) -> dict[str, Any]:
    """Two slots, one coach off on Saturday. The ONLY variable is the lock."""
    return make_payload(
        teams=[make_team("SM1", sessions_per_week=1)],
        venues=[make_venue("gym", [(SATURDAY, "18:00"), (TUESDAY, "18:00")])],
        coaches=[{"id": "c1", "firstName": "Maxime", "lastName": "Durand", "isActive": True}],
        constraints=[_coach_link("SM1", "c1"), _coach_off("c1", SATURDAY)],
        slot_templates=[_hard_lock("SM1", "gym", SATURDAY, "18:00")] if locked else [],
    )


def test_control_the_coach_constraint_really_works_without_a_lock() -> None:
    """TÉMOIN — indispensable : sans lui, le test suivant ne prouverait rien.

    Si la contrainte coach ne mordait pas non plus SANS verrou, constater que SM1
    joue le samedi AVEC le verrou n'accuserait pas le verrou. Ce témoin établit
    que la contrainte est bien active dans ce payload.
    """
    result = solve_payload(_payload(locked=False))

    assert result["status"] == "completed"
    assert _days_of(result, "SM1") == [TUESDAY], "sans verrou, la contrainte coach doit écarter le samedi"
    assert _violation_messages(result) == [], "rien n'est violé, donc rien à signaler"


def test_a_lock_on_the_coach_day_off_is_kept_but_announced() -> None:
    """LE CAS — le verrou gagne (souveraineté), et le solveur le DIT."""
    result = solve_payload(_payload(locked=True))

    # 1. Souveraineté : le placement du gestionnaire est respecté.
    assert _days_of(result, "SM1") == [SATURDAY], "le verrou reste souverain — ALIGN-07"

    # 2. Fin du silence : c'est CE point qui échouait avant P2-9.
    messages = _violation_messages(result)
    assert len(messages) == 1, f"une violation attendue, obtenu : {messages}"

    # Le message doit permettre d'AGIR : quelle équipe, quel coach, où et quand.
    # Assertions séparées — un seul `in` global serait satisfait par n'importe
    # quelle partie du texte, y compris le libellé de contrainte ajouté en
    # suffixe (ce qui a laissé passer une mutation lors de la mise au point).
    phrase = messages[0].split("(contrainte")[0]
    for expected, why in (
        ("SM1", "l'équipe"),
        ("Maxime", "le coach"),
        ("gym", "le gymnase"),
        ("18:00", "l'heure"),
    ):
        assert expected in phrase, f"{why} doit être nommé dans la phrase : {phrase!r}"

    # 3. Un avertissement, jamais une erreur : le gestionnaire tranche.
    severities = {d.get("severity") for d in result["diagnostics"] if d.get("type") == "constraint_not_honored"}
    assert severities == {"INFO"}


def test_a_lock_that_violates_nothing_stays_quiet() -> None:
    """L'autre bord : ne pas crier au loup.

    Un avertissement permanent devient du papier peint et cesse d'être lu — donc
    un verrou posé un jour où le coach est disponible ne doit RIEN produire.
    """
    payload = make_payload(
        teams=[make_team("SM1", sessions_per_week=1)],
        venues=[make_venue("gym", [(SATURDAY, "18:00"), (TUESDAY, "18:00")])],
        coaches=[{"id": "c1", "firstName": "Maxime", "lastName": "Durand", "isActive": True}],
        constraints=[_coach_link("SM1", "c1"), _coach_off("c1", SATURDAY)],
        slot_templates=[_hard_lock("SM1", "gym", TUESDAY, "18:00")],
    )

    result = solve_payload(payload)

    assert _days_of(result, "SM1") == [TUESDAY]
    assert _violation_messages(result) == []
