"""TDD tests for contiguity bugs in constraint functions.

Tests 1, 3 verify fixes for contiguity and late-start gap bugs.
Test 4 is marked xfail (LOISIR_ADULTE not yet in _ADULT_LEVELS — Task 3).
Tests 2, 5 are positive tests that should pass immediately.
"""

from __future__ import annotations

from ortools.sat.python import cp_model

from app.solver.constraints import (
    add_adult_weekday_time_constraints,
    add_min_session_duration_constraints,
    add_no_late_start_constraints,
)


# ---------------------------------------------------------------------------
# Test 1: min_session_duration enforces contiguity (no-gap triple constraint)
# ---------------------------------------------------------------------------


def test_min_session_duration_fragments_are_forbidden() -> None:
    """A session with minSessionMinutes=90 (6 slots) must be one contiguous run.

    The no-gap triple constraint (x[t+1] + x[t] >= x[t+2]) prevents any
    hole in the active block. Combined with sum >= N * use_here, this forces
    exactly one contiguous run of at least N slots.

    We verify this by giving higher weight to non-contiguous slots (even indices)
    to tempt the solver into picking a fragmented assignment. Without the triple
    constraint, the solver would prefer slots 0,2,4,6 (weight 10 each). With
    the triple constraint, the solver must pick a contiguous block regardless.
    """
    model = cp_model.CpModel()

    team_id = "team-1"
    venue_id = "venue-1"
    day = "1"  # Monday

    times = ["18:00", "18:15", "18:30", "18:45", "19:00", "19:15", "19:30", "19:45"]
    slots = [(f"{day}:{t}", model.NewBoolVar(f"x_{i}")) for i, t in enumerate(times)]

    assignments = [
        {"team_id": team_id, "venue_id": venue_id, "slot_id": slot_id, "var": var}
        for slot_id, var in slots
    ]

    teams = [{"id": team_id, "minSessionMinutes": 90}]

    add_min_session_duration_constraints(model, assignments, teams=teams)

    # Give higher weight to even-indexed slots to tempt non-contiguous selection.
    # Without the triple constraint, the solver would prefer 0,2,4,6 + 2 more.
    # With the triple constraint, the solver must pick a contiguous block.
    model.Maximize(
        10 * slots[0][1] + 1 * slots[1][1] + 10 * slots[2][1] + 1 * slots[3][1]
        + 10 * slots[4][1] + 1 * slots[5][1] + 10 * slots[6][1] + 1 * slots[7][1]
    )

    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = 5
    status = solver.Solve(model)

    assert status in (cp_model.OPTIMAL, cp_model.FEASIBLE), f"Solver status: {status}"

    # Collect active slot times in order
    active_times = [t for t, var in slots if solver.Value(var) == 1]

    # There must be at least 6 active slots (minSessionMinutes=90 / 15 = 6)
    assert len(active_times) >= 6, f"Expected >= 6 active slots, got {len(active_times)}"

    # The active slots must form ONE contiguous run (no gaps).
    def to_minutes(t: str) -> int:
        h, m = t.split(":")[-2], t.split(":")[-1]
        return int(h) * 60 + int(m)

    minute_offsets = [to_minutes(t) for t in active_times]
    for i in range(1, len(minute_offsets)):
        gap = minute_offsets[i] - minute_offsets[i - 1]
        assert gap == 15, (
            f"Non-contiguous session: gap of {gap} min between "
            f"{active_times[i - 1]} and {active_times[i]}"
        )


# ---------------------------------------------------------------------------
# Test 2: min_session_duration positive — minimum count satisfied
# ---------------------------------------------------------------------------


def test_min_session_duration_single_contiguous_block() -> None:
    """Positive test: minSessionMinutes=90 requires at least 6 active slots.

    This should pass immediately — the use_here formulation does enforce the
    minimum count even though it doesn't enforce contiguity.
    """
    model = cp_model.CpModel()

    team_id = "team-1"
    venue_id = "venue-1"
    day = "1"

    times = ["18:00", "18:15", "18:30", "18:45", "19:00", "19:15", "19:30", "19:45"]
    slots = [(f"{day}:{t}", model.NewBoolVar(f"x_{i}")) for i, t in enumerate(times)]

    assignments = [
        {"team_id": team_id, "venue_id": venue_id, "slot_id": slot_id, "var": var}
        for slot_id, var in slots
    ]

    teams = [{"id": team_id, "minSessionMinutes": 90}]

    add_min_session_duration_constraints(model, assignments, teams=teams)

    model.Maximize(sum(var for _, var in slots))

    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = 5
    status = solver.Solve(model)

    assert status in (cp_model.OPTIMAL, cp_model.FEASIBLE), f"Solver status: {status}"

    active_slots = sum(1 for _, var in slots if solver.Value(var) == 1)
    assert active_slots >= 6, f"Expected >= 6 active slots, got {active_slots}"


# ---------------------------------------------------------------------------
# Test 3: no_late_start forbids late slot after gap (adjacency chain)
# ---------------------------------------------------------------------------


def test_no_late_start_gap_creates_new_session() -> None:
    """A late slot (>=21:00) cannot be active after a gap in the schedule.

    With the adjacency-based late start constraint, each late slot can only be
    active if the immediately preceding slot is also active. When intermediate
    slots are unavailable (forced to 0), the chain prevents late slots from
    being activated — they cannot start a new session after a gap.
    """
    model = cp_model.CpModel()

    team_id = "team-1"
    venue_id = "venue-1"
    day = "1"

    # All slots from 19:00 to 21:15 (consecutive 15-min slots)
    slot_times = [
        "19:00", "19:15", "19:30", "19:45", "20:00",
        "20:15", "20:30", "20:45", "21:00", "21:15",
    ]
    slots = [(f"{day}:{t}", model.NewBoolVar(f"x_{i}")) for i, t in enumerate(slot_times)]

    # Force middle slots to 0 — simulates venue unavailability creating a gap.
    # Available: 19:00, 19:15 (early) and 21:00, 21:15 (late)
    # Unavailable: 19:30-20:45 (forced to 0)
    for i in range(2, 8):
        model.Add(slots[i][1] == 0)

    assignments = [
        {"team_id": team_id, "venue_id": venue_id, "slot_id": slot_id, "var": var}
        for slot_id, var in slots
    ]

    teams = [{"id": team_id, "minSessionMinutes": 30}]  # 2 slots minimum

    add_min_session_duration_constraints(model, assignments, teams=teams)
    add_no_late_start_constraints(model, assignments)

    # Maximize to try to activate the late slot
    model.Maximize(sum(var for _, var in slots))

    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = 5
    status = solver.Solve(model)

    assert status in (cp_model.OPTIMAL, cp_model.FEASIBLE), f"Solver status: {status}"

    # The 21:00 and 21:15 slots must be 0 — the adjacency chain prevents
    # them from being active after a gap (slots 19:30-20:45 are unavailable)
    assert solver.Value(slots[8][1]) == 0, "Late slot at 21:00 should be forbidden after gap"
    assert solver.Value(slots[9][1]) == 0, "Late slot at 21:15 should be forbidden after gap"


# ---------------------------------------------------------------------------
# Test 4: LOISIR_ADULTE not yet in _ADULT_LEVELS (xfail until Task 3)
# ---------------------------------------------------------------------------


def test_loisir_adulte_weekday_min_19h() -> None:
    """A LOISIR_ADULTE team on a weekday must not have slots before 19:00.

    LOISIR_ADULTE is an adult level that should be in _ADULT_LEVELS but isn't
    yet.  Once Task 3 adds it, this test will XPASS and the xfail marker can
    be removed.
    """
    model = cp_model.CpModel()

    team_id = "team-loisir"
    venue_id = "venue-1"
    day = "1"  # Monday (weekday)

    # Slot at 18:00 — before the 19:00 minimum for adult levels
    slot_18 = model.NewBoolVar("x_18")
    # Slot at 19:00 — at or after the minimum
    slot_19 = model.NewBoolVar("x_19")

    assignments = [
        {"team_id": team_id, "venue_id": venue_id, "slot_id": f"{day}:18:00", "var": slot_18, "level": "LOISIR_ADULTE"},
        {"team_id": team_id, "venue_id": venue_id, "slot_id": f"{day}:19:00", "var": slot_19, "level": "LOISIR_ADULTE"},
    ]

    teams = [{"id": team_id, "level": "LOISIR_ADULTE"}]

    add_adult_weekday_time_constraints(model, assignments, teams=teams)

    # Maximize to try to activate the 18:00 slot
    model.Maximize(slot_18 + slot_19)

    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = 5
    status = solver.Solve(model)

    assert status in (cp_model.OPTIMAL, cp_model.FEASIBLE), f"Solver status: {status}"

    # The 18:00 slot must be 0 for LOISIR_ADULTE on a weekday
    assert solver.Value(slot_18) == 0, (
        "LOISIR_ADULTE should not be allowed to train before 19:00 on weekdays"
    )


# ---------------------------------------------------------------------------
# Test 5: LOISIR_JEUNE is NOT an adult level — weekday slots allowed before 19h
# ---------------------------------------------------------------------------


def test_loisir_jeune_weekday_allowed_before_19h() -> None:
    """A LOISIR_JEUNE team on a weekday CAN have slots before 19:00.

    LOISIR_JEUNE is not an adult competitive level, so the adult weekday
    time constraint should NOT forbid its early slots.
    """
    model = cp_model.CpModel()

    team_id = "team-jeune"
    venue_id = "venue-1"
    day = "1"  # Monday (weekday)

    # Slot at 17:00 — well before 19:00
    slot_17 = model.NewBoolVar("x_17")

    assignments = [
        {"team_id": team_id, "venue_id": venue_id, "slot_id": f"{day}:17:00", "var": slot_17, "level": "LOISIR_JEUNE"},
    ]

    teams = [{"id": team_id, "level": "LOISIR_JEUNE"}]

    add_adult_weekday_time_constraints(model, assignments, teams=teams)

    # The constraint should NOT force slot_17 to 0, so we can set it to 1
    model.Add(slot_17 == 1)

    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = 5
    status = solver.Solve(model)

    assert status in (cp_model.OPTIMAL, cp_model.FEASIBLE), (
        f"Solver should find LOISIR_JEUNE at 17:00 feasible, got status: {status}"
    )
    assert solver.Value(slot_17) == 1, "LOISIR_JEUNE should be allowed at 17:00 on weekdays"