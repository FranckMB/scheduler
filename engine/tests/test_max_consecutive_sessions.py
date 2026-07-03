"""Tests for the max-consecutive-sessions hard constraint (TDD: RED -> GREEN).

A coach (coaching or playing) may not be assigned to all 3 slots in a
consecutive triple (A, B, C) where A.end == B.start and B.end == C.start.
The constraint applies both within the same venue and cross-venue (grouped
by person_id + day). At most 2 of the 3 are allowed.
"""

from __future__ import annotations

from ortools.sat.python import cp_model

from app.solver.constraints import (
    AssignmentVariable,
    HardConstraintStats,
    add_level_1_hard_constraints,
)


def _solve(model: cp_model.CpModel) -> int:
    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = 5
    return solver.Solve(model)


def _assignment(
    model: cp_model.CpModel,
    name: str,
    *,
    team_id: str = "team-1",
    slot_id: str = "1:18:00",
    venue_id: str = "venue-1",
    coach_id: str | None = "coach-1",
    player_ids: tuple[str, ...] = (),
    start: int | None = None,
    end: int | None = None,
) -> AssignmentVariable:
    return AssignmentVariable(
        var=model.NewBoolVar(name),
        team_id=team_id,
        slot_id=slot_id,
        venue_id=venue_id,
        coach_id=coach_id,
        player_ids=player_ids,
        start=start,
        end=end,
    )


class TestMaxConsecutiveSessions:
    def test_stats_has_max_consecutive_sessions_counter(self) -> None:
        """HardConstraintStats must expose a max_consecutive_sessions counter."""
        stats = HardConstraintStats()
        assert hasattr(stats, "max_consecutive_sessions"), (
            "HardConstraintStats must have a max_consecutive_sessions field"
        )
        assert stats.max_consecutive_sessions == 0, (
            f"max_consecutive_sessions should default to 0, got {stats.max_consecutive_sessions}"
        )

    def test_three_consecutive_coaching_slots_is_infeasible(self) -> None:
        """Coach coaching 3 consecutive slots (back-to-back) must be rejected.

        Venue-1 has slots at 18:00-19:30, 19:30-21:00, 21:00-22:30.
        Coach-1 is assigned to all 3 (different teams). This must be INFEASIBLE.
        """
        model = cp_model.CpModel()
        a = _assignment(
            model, "slot_a",
            team_id="team-1",
            slot_id="1:18:00", venue_id="venue-1", coach_id="coach-1",
            start=18 * 60, end=19 * 60 + 30,
        )
        b = _assignment(
            model, "slot_b",
            team_id="team-2",
            slot_id="1:19:30", venue_id="venue-1", coach_id="coach-1",
            start=19 * 60 + 30, end=21 * 60,
        )
        c = _assignment(
            model, "slot_c",
            team_id="team-3",
            slot_id="1:21:00", venue_id="venue-1", coach_id="coach-1",
            start=21 * 60, end=22 * 60 + 30,
        )

        stats = add_level_1_hard_constraints(
            model, [a, b, c], coaches=[{"id": "coach-1"}]
        )

        model.Add(a.var == 1)
        model.Add(b.var == 1)
        model.Add(c.var == 1)

        status = _solve(model)
        assert stats.max_consecutive_sessions > 0, (
            f"Expected max_consecutive_sessions > 0, got {stats.max_consecutive_sessions}"
        )
        assert status == cp_model.INFEASIBLE, (
            f"Coach in 3 consecutive slots should be INFEASIBLE, got {status}"
        )

    def test_two_consecutive_slots_is_feasible(self) -> None:
        """Coach coaching 2 of 3 consecutive slots must be allowed.

        Venue-1 has slots at 18:00-19:30, 19:30-21:00, 21:00-22:30.
        Coach-1 is assigned to slots A and B only. This must be FEASIBLE.
        """
        model = cp_model.CpModel()
        a = _assignment(
            model, "slot_a",
            team_id="team-1",
            slot_id="1:18:00", venue_id="venue-1", coach_id="coach-1",
            start=18 * 60, end=19 * 60 + 30,
        )
        b = _assignment(
            model, "slot_b",
            team_id="team-2",
            slot_id="1:19:30", venue_id="venue-1", coach_id="coach-1",
            start=19 * 60 + 30, end=21 * 60,
        )
        c = _assignment(
            model, "slot_c",
            team_id="team-3",
            slot_id="1:21:00", venue_id="venue-1", coach_id="coach-2",
            start=21 * 60, end=22 * 60 + 30,
        )

        add_level_1_hard_constraints(
            model, [a, b, c],
            coaches=[{"id": "coach-1"}, {"id": "coach-2"}],
        )

        model.Add(a.var == 1)
        model.Add(b.var == 1)
        model.Add(c.var == 0)

        status = _solve(model)
        assert status in (cp_model.FEASIBLE, cp_model.OPTIMAL), (
            f"Coach in 2 of 3 consecutive slots should be feasible, got {status}"
        )

    def test_playing_assignments_count_toward_limit(self) -> None:
        """Coach-player playing assignments (player_ids) must count toward the limit.

        Coach-1 coaches in slots A and B, and plays in slot C.
        All 3 are consecutive, so this must be INFEASIBLE.
        """
        model = cp_model.CpModel()
        a = _assignment(
            model, "slot_a",
            team_id="team-1",
            slot_id="1:18:00", venue_id="venue-1", coach_id="coach-1",
            start=18 * 60, end=19 * 60 + 30,
        )
        b = _assignment(
            model, "slot_b",
            team_id="team-2",
            slot_id="1:19:30", venue_id="venue-1", coach_id="coach-1",
            start=19 * 60 + 30, end=21 * 60,
        )
        c = _assignment(
            model, "slot_c",
            team_id="team-3",
            slot_id="1:21:00", venue_id="venue-1", coach_id="coach-2",
            player_ids=("coach-1",),
            start=21 * 60, end=22 * 60 + 30,
        )

        add_level_1_hard_constraints(
            model, [a, b, c],
            coaches=[{"id": "coach-1"}, {"id": "coach-2"}],
        )

        model.Add(a.var == 1)
        model.Add(b.var == 1)
        model.Add(c.var == 1)

        status = _solve(model)
        assert status == cp_model.INFEASIBLE, (
            f"Coach-1 coaching 2 + playing 1 in 3 consecutive slots "
            f"should be INFEASIBLE, got {status}"
        )

    def test_non_consecutive_slots_not_constrained(self) -> None:
        """Slots with gaps between them are NOT constrained.

        Venue-1 has slots at 18:00-19:30, 20:00-21:30, 22:00-23:30.
        These are NOT consecutive (gaps between them), so coach-1
        can be in all 3.
        """
        model = cp_model.CpModel()
        a = _assignment(
            model, "slot_a",
            team_id="team-1",
            slot_id="1:18:00", venue_id="venue-1", coach_id="coach-1",
            start=18 * 60, end=19 * 60 + 30,
        )
        b = _assignment(
            model, "slot_b",
            team_id="team-2",
            slot_id="1:20:00", venue_id="venue-1", coach_id="coach-1",
            start=20 * 60, end=21 * 60 + 30,
        )
        c = _assignment(
            model, "slot_c",
            team_id="team-3",
            slot_id="1:22:00", venue_id="venue-1", coach_id="coach-1",
            start=22 * 60, end=23 * 60 + 30,
        )

        add_level_1_hard_constraints(
            model, [a, b, c], coaches=[{"id": "coach-1"}]
        )

        model.Add(a.var == 1)
        model.Add(b.var == 1)
        model.Add(c.var == 1)

        status = _solve(model)
        assert status in (cp_model.FEASIBLE, cp_model.OPTIMAL), (
            f"Non-consecutive slots should be feasible, got {status}"
        )

    def test_different_venues_are_constrained_cross_venue(self) -> None:
        """Consecutive slots in different venues ARE constrained as a triple (BUG-3 fix).

        Coach-1 in 3 consecutive time slots across 2 different venues.
        The cross-venue grouping by (person_id, day) detects this chain
        and adds sum(varA + varB + varC) <= 2, making it INFEASIBLE.
        """
        model = cp_model.CpModel()
        a = _assignment(
            model, "slot_a",
            team_id="team-1",
            slot_id="1:18:00", venue_id="venue-1", coach_id="coach-1",
            start=18 * 60, end=19 * 60 + 30,
        )
        b = _assignment(
            model, "slot_b",
            team_id="team-2",
            slot_id="1:19:30", venue_id="venue-2", coach_id="coach-1",
            start=19 * 60 + 30, end=21 * 60,
        )
        c = _assignment(
            model, "slot_c",
            team_id="team-3",
            slot_id="1:21:00", venue_id="venue-1", coach_id="coach-1",
            start=21 * 60, end=22 * 60 + 30,
        )

        add_level_1_hard_constraints(
            model, [a, b, c], coaches=[{"id": "coach-1"}]
        )

        model.Add(a.var == 1)
        model.Add(b.var == 1)
        model.Add(c.var == 1)

        status = _solve(model)
        assert status == cp_model.INFEASIBLE, (
            f"Coach in 3 consecutive cross-venue slots should be INFEASIBLE, got {status}"
        )

    def test_no_coaches_means_zero_constraints(self) -> None:
        """When no coaches are passed, max_consecutive_sessions must be 0."""
        model = cp_model.CpModel()
        a = _assignment(
            model, "slot_a",
            team_id="team-1",
            slot_id="1:18:00", venue_id="venue-1", coach_id="coach-1",
            start=18 * 60, end=19 * 60 + 30,
        )
        b = _assignment(
            model, "slot_b",
            team_id="team-2",
            slot_id="1:19:30", venue_id="venue-1", coach_id="coach-1",
            start=19 * 60 + 30, end=21 * 60,
        )
        c = _assignment(
            model, "slot_c",
            team_id="team-3",
            slot_id="1:21:00", venue_id="venue-1", coach_id="coach-1",
            start=21 * 60, end=22 * 60 + 30,
        )

        stats = add_level_1_hard_constraints(model, [a, b, c], coaches=[])

        assert stats.max_consecutive_sessions == 0, (
            f"No coaches => max_consecutive_sessions should be 0, "
            f"got {stats.max_consecutive_sessions}"
        )

    def test_total_constraints_includes_max_consecutive_sessions(self) -> None:
        """total_constraints_added must include max_consecutive_sessions in the sum."""
        model = cp_model.CpModel()
        a = _assignment(
            model, "slot_a",
            team_id="team-1",
            slot_id="1:18:00", venue_id="venue-1", coach_id="coach-1",
            start=18 * 60, end=19 * 60 + 30,
        )
        b = _assignment(
            model, "slot_b",
            team_id="team-2",
            slot_id="1:19:30", venue_id="venue-1", coach_id="coach-1",
            start=19 * 60 + 30, end=21 * 60,
        )
        c = _assignment(
            model, "slot_c",
            team_id="team-3",
            slot_id="1:21:00", venue_id="venue-1", coach_id="coach-1",
            start=21 * 60, end=22 * 60 + 30,
        )

        stats = add_level_1_hard_constraints(
            model, [a, b, c], coaches=[{"id": "coach-1"}]
        )

        if stats.max_consecutive_sessions > 0:
            expected_total = (
                stats.room_at_most_one
                + stats.coach_at_most_one
                + stats.coach_player_non_overlap
                + stats.team_no_overlap
                + stats.travel_feasibility_stub
                + stats.fixed_slots
                + stats.forbidden_assignments
                + stats.coach_unavailability
                + stats.venue_closures
                + stats.required_bridge_stub
                + stats.min_sessions
                + stats.forced_venues
                + stats.one_session_per_day
                + stats.age_ascending
                + stats.coach_rest_day
                + stats.salarie_distribution
                + stats.max_consecutive_sessions
            )
            assert stats.total_constraints_added == expected_total, (
                f"total_constraints_added={stats.total_constraints_added} "
                f"!= expected={expected_total}"
            )

    def test_coach_in_first_and_third_only_is_feasible(self) -> None:
        """Coach in slots A and C (skipping B) of a consecutive triple is feasible.

        This is only 2 of 3, which satisfies the <= 2 constraint.
        """
        model = cp_model.CpModel()
        a = _assignment(
            model, "slot_a",
            team_id="team-1",
            slot_id="1:18:00", venue_id="venue-1", coach_id="coach-1",
            start=18 * 60, end=19 * 60 + 30,
        )
        b = _assignment(
            model, "slot_b",
            team_id="team-2",
            slot_id="1:19:30", venue_id="venue-1", coach_id="coach-2",
            start=19 * 60 + 30, end=21 * 60,
        )
        c = _assignment(
            model, "slot_c",
            team_id="team-3",
            slot_id="1:21:00", venue_id="venue-1", coach_id="coach-1",
            start=21 * 60, end=22 * 60 + 30,
        )

        add_level_1_hard_constraints(
            model, [a, b, c],
            coaches=[{"id": "coach-1"}, {"id": "coach-2"}],
        )

        model.Add(a.var == 1)
        model.Add(b.var == 0)
        model.Add(c.var == 1)

        status = _solve(model)
        assert status in (cp_model.FEASIBLE, cp_model.OPTIMAL), (
            f"Coach in A and C only (2 of 3) should be feasible, got {status}"
        )
