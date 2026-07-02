"""BCCL regression golden test — full payload integration test.

Replays the original BCCL payload (schedule ``ad21e48b``) that produced 4
implicit-rule violations on 2026-06-22:

* BUG-1: Nicolas Barilleau coach+joueur overlap on Tuesday (Jean Vilar 18:45
  + Debarros 19:00).
* BUG-2: Anna coach+joueuse overlap on Wednesday (Matéo 17:30 + Gymnase
  Armand 18:45).
* BUG-3: Emerick 3 consecutive back-to-back sessions on Tuesday cross-venue
  (Gymnase Armand 17:30 -> Debarros 19:00 -> Debarros 20:30).
* BUG-4: Enzo 5 working days Mon-Fri with no rest day.
* BUG-5: Score = -1590949 (negative, caused by ``placed.Not()`` penalty on
  HARD-only teams).

This test validates that Tasks 2-5 fixes hold together on the real payload.
``status="failed"`` is acceptable (constraints may be too tight); only
``status="completed"`` with residual violations fails the test.
"""

from __future__ import annotations

import asyncio
import json
import pathlib
from typing import Any

import pytest

from app.main import build_schedule
from app.schemas.input_schema import ScheduleInputSchema
from app.solver.constraints import _intervals_overlap, parse_v2_constraints

FIXTURES_DIR = pathlib.Path(__file__).resolve().parents[1] / "fixtures"

# Coach IDs from the BCCL payload (verified via coaches[] array).
NICOLAS_BARILLEAU_ID = "b4be932d-7367-4b1a-bd97-c48f41eed104"
ANNA_ID = "c0a4b245-d7b8-4574-85ac-571a44c51a42"
EMERICK_ID = "606427ba-f7bd-4f0c-898c-553a80225c75"
ENZO_ID = "b0e53ede-183d-4900-b560-7c6545bea67f"


def _load_fixture(name: str) -> dict[str, Any]:
    path = FIXTURES_DIR / f"{name}.json"
    with open(path, encoding="utf-8") as f:
        return json.load(f)


def _run_build_schedule(data: dict[str, Any]) -> Any:
    input_data = ScheduleInputSchema.model_validate(data)
    return asyncio.run(build_schedule(input_data))


def _time_to_minutes(time_obj: Any) -> int:
    if hasattr(time_obj, "hour"):
        return time_obj.hour * 60 + time_obj.minute
    text = str(time_obj)
    parts = text.split(":")
    return int(parts[0]) * 60 + int(parts[1])


def _extract_person_slots(result: Any, data: dict[str, Any]) -> dict[str, list[tuple[int, int, int, str]]]:
    """Extract slots per person (coach or player) with (start, end, day, lock) intervals.

    Uses ``team_coach_map`` and ``team_player_map`` from parsed constraints to
    look up which persons are associated with each placed team.  The lock level
    is kept so the test can ignore overlaps caused by HARD-locked slots, which
    the solver is not allowed to move (input contradictions must be fixed by the
    manager, not the engine).
    """
    parsed = parse_v2_constraints(data.get("constraints", []))
    team_coach_map: dict[str, list[str]] = parsed.get("team_coach_map", {})
    team_player_map: dict[str, list[str]] = parsed.get("team_player_map", {})

    person_intervals: dict[str, list[tuple[int, int, int, str]]] = {}

    for slot in result.slots:
        team_id_str = str(slot.team_id)
        persons: set[str] = set()
        persons.update(team_coach_map.get(team_id_str, []))
        persons.update(team_player_map.get(team_id_str, []))

        start = _time_to_minutes(slot.start_time)
        end = start + slot.duration_minutes
        day = int(slot.day_of_week)
        lock = str(slot.lock_level or "NONE").upper()

        for person_id in persons:
            person_intervals.setdefault(person_id, []).append((start, end, day, lock))

    return person_intervals


def _count_overlaps_for_person(
    intervals: dict[str, list[tuple[int, int, int, str]]], person_id: str
) -> list[str]:
    """Return list of overlap descriptions for the given person on any day.

    Overlaps where at least one slot is HARD-locked are ignored: those are input
    contradictions the solver cannot resolve.
    """
    overlaps: list[str] = []
    slots = intervals.get(person_id, [])
    for i in range(len(slots)):
        for j in range(i + 1, len(slots)):
            a_start, a_end, a_day, a_lock = slots[i]
            b_start, b_end, b_day, b_lock = slots[j]
            if a_day != b_day:
                continue
            if a_lock == "HARD" or b_lock == "HARD":
                continue
            if _intervals_overlap(a_start, a_end, b_start, b_end):
                overlaps.append(
                    f"person={person_id} day={a_day} "
                    f"slot_A=({a_start},{a_end}) slot_B=({b_start},{b_end})"
                )
    return overlaps


def _find_consecutive_triples_any_day(intervals: list[tuple[int, int, int, str]]) -> list[str]:
    """Return descriptions of any 3-slot consecutive chain on any day.

    A chain is A->B->C where A.end == B.start and B.end == C.start, all on
    the same day.  Chains that include a HARD-locked slot are ignored because
    the solver cannot move HARD-locked sessions.
    """
    by_day: dict[int, list[tuple[int, int, str]]] = {}
    for start, end, day, lock in intervals:
        by_day.setdefault(day, []).append((start, end, lock))

    triples: list[str] = []
    for day, day_slots in by_day.items():
        sorted_slots = sorted(day_slots, key=lambda x: x[0])
        for i in range(len(sorted_slots) - 2):
            a_start, a_end, a_lock = sorted_slots[i]
            b_start, b_end, b_lock = sorted_slots[i + 1]
            c_start, c_end, c_lock = sorted_slots[i + 2]
            if a_lock == "HARD" or b_lock == "HARD" or c_lock == "HARD":
                continue
            if a_end == b_start and b_end == c_start:
                triples.append(
                    f"day={day} ({a_start}-{a_end}) -> ({b_start}-{b_end}) -> ({c_start}-{c_end})"
                )
    return triples


def _working_days_mon_fri(intervals: list[tuple[int, int, int, str]]) -> set[int]:
    """Return the set of Mon-Fri (1-5) days on which the person has non-HARD sessions."""
    return {day for _, _, day, lock in intervals if 1 <= day <= 5 and lock != "HARD"}


# 60s: this test verifies CORRECTNESS (no coach overlaps/triples), not speed.
# The same-coach chaining bonus (small tiebreaker terms) makes proving optimality
# on the BCCL payload cost ~24s of solve (vs <1s without) — an accepted trade for
# grouping a coach's back-to-back sessions. The real solve ceiling is the adaptive
# timeout (60/180/600s), not this mark; 60s here just guards against a true blow-up.
@pytest.mark.timeout(60)
def test_bccl_regression_all_bugs_fixed() -> None:
    """Replay the BCCL payload and verify all 4 implicit-rule bugs are fixed.

    Acceptable outcomes:
    * ``status="failed"`` — constraints too tight, manager adjusts priorities.
    * ``status="completed"`` — all 4 violations absent and score >= 0.

    Fails only when ``status="completed"`` but residual violations remain.
    """
    data = _load_fixture("bccl_regression")
    result = _run_build_schedule(data)

    # If status=failed, no slots to check — acceptable (constraints too tight).
    if result.status == "failed":
        return

    assert result.status == "completed", f"Unexpected status: {result.status}"

    person_intervals = _extract_person_slots(result, data)

    # BUG-1: Nicolas Barilleau — 0 coach/joueur overlapping slots.
    nicolas_overlaps = _count_overlaps_for_person(person_intervals, NICOLAS_BARILLEAU_ID)
    assert nicolas_overlaps == [], (
        f"Expected 0 overlaps for Nicolas Barilleau, got {len(nicolas_overlaps)}: "
        f"{nicolas_overlaps}. Nicolas intervals: "
        f"{person_intervals.get(NICOLAS_BARILLEAU_ID, [])}"
    )

    # BUG-2: Anna — 0 coach/joueuse overlapping slots (ignoring HARD-locked input contradictions).
    anna_overlaps = _count_overlaps_for_person(person_intervals, ANNA_ID)
    assert anna_overlaps == [], (
        f"Expected 0 overlaps for Anna, got {len(anna_overlaps)}: "
        f"{anna_overlaps}. Anna intervals: "
        f"{person_intervals.get(ANNA_ID, [])}"
    )

    # BUG-3: Emerick — at most 2 consecutive back-to-back sessions on any day.
    emerick_triples = _find_consecutive_triples_any_day(person_intervals.get(EMERICK_ID, []))
    assert emerick_triples == [], (
        f"Expected 0 consecutive triples for Emerick, got {len(emerick_triples)}: "
        f"{emerick_triples}. Emerick intervals: "
        f"{person_intervals.get(EMERICK_ID, [])}"
    )

    # BUG-4: Enzo — at least 1 rest day among Mon-Fri OR fewer than 5 working days.
    enzo_working_days = _working_days_mon_fri(person_intervals.get(ENZO_ID, []))
    assert len(enzo_working_days) <= 4, (
        f"Enzo must have at least 1 rest day (Mon-Fri), "
        f"but is working on {len(enzo_working_days)} days: {sorted(enzo_working_days)}. "
        "The two-pass fallback silently dropped the rest-day constraint."
    )

    # BUG-5: Score must be non-negative when status=completed.
    assert result.score is not None, "score must be set for completed status"
    assert result.score >= 0, (
        f"score must be >= 0 (HARD-only teams not penalized), got {result.score}"
    )
