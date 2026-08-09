"""ALIGN-07 — a HARD reservation locks the WHOLE training slot, divisible or not.

Founder ruling (2026-07-25): divisibility is a property of the SLOT
(`trainingSlots[].capacity`), not of the venue. A HARD reservation is a manager
decision: pinning SM1 on a capacity-2 slot means SM1 is ALONE on it — the solver
never fills the other half on its own. Sharing is EXPLICIT: the manager pins the
N teams themselves (the wizard modal bounds the picker to `capacity`).

These are non-regression tests for the `constraint semantics` structuring axis
(CLAUDE.md §7.1): they freeze that behaviour at the solver level so a future
change to `blocked_venue_slots` (model.py) or the over-capacity diagnostic
(result_builder.py) can't silently reopen ALIGN-07.
"""

from __future__ import annotations

from typing import Any

from tests.support.pipeline import make_payload, make_team, make_venue, solve_payload


def _hard_lock(team_id: str, venue_id: str, day: int, start: str, *, duration: int = 90) -> dict[str, Any]:
    """A durable HARD reservation pin, as the backend feeds it into slotTemplates."""
    return {
        "id": f"lock-{team_id}-{venue_id}-{day}-{start}",
        "teamId": team_id,
        "venueId": venue_id,
        "dayOfWeek": day,
        "startTime": start,
        "durationMinutes": duration,
        "lockLevel": "HARD",
    }


def _placed_on(result: dict[str, Any], team_id: str, day: int, start: str) -> bool:
    """True if `team_id` has a result slot on (day, start) — start matched HH:MM."""
    return any(
        slot["teamId"] == team_id and slot["dayOfWeek"] == day and str(slot["startTime"]).startswith(start)
        for slot in result["slots"]
    )


def test_hard_lock_takes_whole_divisible_slot_T1() -> None:
    """T1 (D1): SM1 pinned HARD on a capacity-2 slot → SM1 alone; SM2 cannot slip into the other half.

    Single slot on purpose: the ONLY way SM2 could land here is if the lock left the
    other capacity half open. It must not — SM2 ends up unplaced.
    """
    payload = make_payload(
        teams=[make_team("SM1", sessions_per_week=1), make_team("SM2", sessions_per_week=1)],
        venues=[make_venue("gym", [(6, "18:00")], capacity=2)],
        slot_templates=[_hard_lock("SM1", "gym", 6, "18:00")],
    )

    result = solve_payload(payload)

    assert result["status"] != "failed", f"solve failed: {result['status']}"
    assert _placed_on(result, "SM1", 6, "18:00"), "SM1 HARD lock must be honoured"
    assert not _placed_on(result, "SM2", 6, "18:00"), (
        "SM2 must NOT share the locked slot — a HARD lock takes the whole slot (ALIGN-07 D1)"
    )
    assert "SM2" in result["unplaced"], "SM2 has no other slot → unplaced, not silently co-located"


def test_explicit_double_pin_shares_slot_no_conflict_T2() -> None:
    """T2 (D2): pin SM1 AND SM2 HARD on the same capacity-2 slot → both placed, zero over-capacity diagnostic."""
    payload = make_payload(
        teams=[make_team("SM1", sessions_per_week=1), make_team("SM2", sessions_per_week=1)],
        venues=[make_venue("gym", [(6, "18:00")], capacity=2)],
        slot_templates=[
            _hard_lock("SM1", "gym", 6, "18:00"),
            _hard_lock("SM2", "gym", 6, "18:00"),
        ],
    )

    result = solve_payload(payload)

    assert result["status"] != "failed", f"solve failed: {result['status']}"
    assert _placed_on(result, "SM1", 6, "18:00"), "SM1 explicit pin must be placed"
    assert _placed_on(result, "SM2", 6, "18:00"), "SM2 explicit pin must be placed (2 <= capacity 2)"
    over_capacity = [
        diag for diag in result["diagnostics"] if diag.get("type") == "conflict" and diag.get("venueId") == "gym"
    ]
    assert not over_capacity, (
        f"two explicit pins within capacity must NOT raise an over-capacity conflict: {over_capacity}"
    )


def test_hard_lock_on_indivisible_slot_unchanged_T3() -> None:
    """T3 (baseline): capacity=1 slot + HARD lock → historical behaviour unchanged (SM1 alone, SM2 out)."""
    payload = make_payload(
        teams=[make_team("SM1", sessions_per_week=1), make_team("SM2", sessions_per_week=1)],
        venues=[make_venue("gym", [(6, "18:00")], capacity=1)],
        slot_templates=[_hard_lock("SM1", "gym", 6, "18:00")],
    )

    result = solve_payload(payload)

    assert result["status"] != "failed", f"solve failed: {result['status']}"
    assert _placed_on(result, "SM1", 6, "18:00"), "SM1 HARD lock must be honoured at capacity 1"
    assert not _placed_on(result, "SM2", 6, "18:00"), "capacity-1 slot never shares under a lock"
    assert "SM2" in result["unplaced"], "SM2 unplaced — no other slot"
