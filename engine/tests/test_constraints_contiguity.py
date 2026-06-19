"""TDD tests for contiguity bugs in constraint functions.

RED phase — these tests expose known bugs and will fail before fixes are applied.
Tests 1, 3, 4 are marked xfail (known bugs).
Tests 2, 5 should pass immediately.
"""

from __future__ import annotations

from ortools.sat.python import cp_model

from app.solver.constraints import (
    add_adult_weekday_time_constraints,
    add_min_session_duration_constraints,
    add_no_late_start_constraints,
)


# ---------------------------------------------------------------------------
# Test 1: min_session_duration allows non-contiguous slots (BUG)
# ---------------------------------------------------------------------------


def test_min_session_duration_fragments_are_forbidden() -> None:
    """A session with minSessionMinutes=90 (6 slots) must be one contiguous run.

    The current implementation uses a ``use_here`` indicator that only enforces
    ``sum(slots) >= N`` when the team trains at that venue-day.  It does NOT
    enforce adjacency, so the solver can pick a fragmented assignment that
    satisfies the count but has gaps.

    We expose this by forcing two middle slots to 0 (simulating venue
    unavailability), creating a gap.  The solver must still meet the minimum
    of 6 active slots, so it activates the 6 remaining slots — which are NOT
    contiguous (gap between 18:15 and 19:00).

    This test MUST fail before the contiguity fix is applied.
    """
    model = cp_model.CpModel()

    # One team, one venue, one weekday, 8 consecutive 15-min slots (18:00-19:45)
    team_id = "team-1"
    venue_id = "venue-1"
    day = "1"  # Monday

    times = ["18:00", "18:15", "18:30", "18:45", "19:00", "19:15", "19:30", "19:45"]
    slots = [(f"{day}:{t}", model.NewBoolVar(f"x_{i}")) for i, t in enumerate(times)]

    # Force slots at 18:30 and 18:45 to 0 — simulates venue unavailability
    # This creates a gap: 18:00, 18:15, [GAP], 19:00, 19:15, 19:30, 19:45
    model.Add(slots[2][1] == 0)
    model.Add(slots[3][1] == 0)

    assignments = [
        {"team_id": team_id, "venue_id": venue_id, "slot_id": slot_id, "var": var}
        for slot_id, var in slots
    ]

    teams = [{"id": team_id, "minSessionMinutes": 90}]

    add_min_session_duration_constraints(model, assignments, teams=teams)

    # Maximize total active slots so the solver activates as many as possible
    model.Maximize(sum(var for _, var in slots))

    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = 5
    status = solver.Solve(model)

    assert status in (cp_model.OPTIMAL, cp_model.FEASIBLE), f"Solver status: {status}"

    # Collect active slot times in order
    active_times = [t for t, var in slots if solver.Value(var) == 1]

    # There must be at least 6 active slots (minSessionMinutes=90 / 15 = 6)
    assert len(active_times) >= 6, f"Expected >= 6 active slots, got {len(active_times)}"

    # The active slots must form ONE contiguous run (no gaps).
    # Convert times to minute offsets for gap detection.
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
# Test 3: no_late_start allows late slot after gap (BUG)
# ---------------------------------------------------------------------------


def test_no_late_start_gap_creates_new_session() -> None:
    """A late slot (>=21:00) after a gap should be forbidden as a new session start.

    Setup: slots at 19:00, 19:15 (early), then a gap, then 21:15 (late).
    The current implementation checks ``late_var <= sum(early_vars)``.
    If the 19:00 and 19:15 slots are active, sum(early_vars) >= 1, so the
    21:15 slot is allowed — even though the gap means it's a NEW session,
    not a continuation.

    This test MUST fail before the gap-detection fix is applied.
    """
    model = cp_model.CpModel()

    team_id = "team-1"
    venue_id = "venue-1"
    day = "1"

    # Early slots: 19:00, 19:15
    # Gap: 19:30 .. 21:00 (not in model)
    # Late slot: 21:15
    slot_times = ["19:00", "19:15", "21:15"]
    slots = [(f"{day}:{t}", model.NewBoolVar(f"x_{i}")) for i, t in enumerate(slot_times)]

    assignments = [
        {"team_id": team_id, "venue_id": venue_id, "slot_id": slot_id, "var": var}
        for slot_id, var in slots
    ]

    add_no_late_start_constraints(model, assignments)

    # Maximize to try to activate the late slot
    model.Maximize(sum(var for _, var in slots))

    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = 5
    status = solver.Solve(model)

    assert status in (cp_model.OPTIMAL, cp_model.FEASIBLE), f"Solver status: {status}"

    # The 21:15 slot must be 0 — it's a new session start after a gap
    late_var = slots[2][1]
    assert solver.Value(late_var) == 0, (
        "Late slot at 21:15 should be forbidden (gap makes it a new session)"
    )


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
