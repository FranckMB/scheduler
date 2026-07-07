"""Tests for the coach rest-day hard constraint (TDD: RED -> GREEN).

Every coach must have at least one rest day from Monday to Friday
(at most 4 working days among days 1-5). Both coaching assignments
(coach_id) and coach-player playing assignments (player_ids) count
as working days.
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
) -> AssignmentVariable:
    return AssignmentVariable(
        var=model.NewBoolVar(name),
        team_id=team_id,
        slot_id=slot_id,
        venue_id=venue_id,
        coach_id=coach_id,
        player_ids=player_ids,
    )


class TestCoachRestDay:
    def test_stats_has_coach_rest_day_counter(self) -> None:
        """HardConstraintStats must expose a coach_rest_day counter."""
        stats = HardConstraintStats()
        assert hasattr(stats, "coach_rest_day"), (
            "HardConstraintStats must have a coach_rest_day field"
        )
        assert stats.coach_rest_day == 0, (
            f"coach_rest_day should default to 0, got {stats.coach_rest_day}"
        )

    def test_five_coaching_days_is_infeasible(self) -> None:
        """Coach working all 5 days (Mon-Fri) as coach must be rejected."""
        model = cp_model.CpModel()
        assignments = [
            _assignment(model, f"coach_day_{d}", slot_id=f"{d}:18:00", team_id=f"team-{d}")
            for d in range(1, 6)
        ]

        stats = add_level_1_hard_constraints(
            model, assignments, coaches=[{"id": "coach-1"}]
        )

        for a in assignments:
            model.Add(a.var == 1)

        status = _solve(model)
        assert stats.coach_rest_day > 0, (
            f"Expected coach_rest_day > 0, got {stats.coach_rest_day}"
        )
        assert status == cp_model.INFEASIBLE, (
            f"Coach working 5 days should be INFEASIBLE, got {status}"
        )

    def test_four_coaching_days_is_feasible(self) -> None:
        """Coach working 4 days (Mon-Thu) as coach must be allowed."""
        model = cp_model.CpModel()
        assignments = [
            _assignment(model, f"coach_day_{d}", slot_id=f"{d}:18:00", team_id=f"team-{d}")
            for d in range(1, 5)  # days 1-4 only
        ]

        add_level_1_hard_constraints(model, assignments, coaches=[{"id": "coach-1"}])

        for a in assignments:
            model.Add(a.var == 1)

        status = _solve(model)
        assert status in (cp_model.FEASIBLE, cp_model.OPTIMAL), (
            f"Coach working 4 days should be feasible, got {status}"
        )

    def test_playing_assignments_count_as_working(self) -> None:
        """Coach-player playing assignments (player_ids) must count as working days.

        Coach-1 is coaching on days 1-4 and PLAYING on day 5.
        Without counting player_ids, day 5 would be a 'rest day' and the
        constraint would be satisfied. With counting, all 5 days are working
        and the model must be INFEASIBLE.
        """
        model = cp_model.CpModel()
        # Coaching assignments on days 1-4
        coaching = [
            _assignment(
                model, f"coach_day_{d}",
                slot_id=f"{d}:18:00",
                team_id=f"team-{d}",
                coach_id="coach-1",
            )
            for d in range(1, 5)
        ]
        # Playing assignment on day 5 (coach-1 is a player)
        playing = _assignment(
            model, "playing_day_5",
            slot_id="5:18:00",
            team_id="team-5",
            coach_id="coach-other",
            player_ids=("coach-1",),
        )

        all_assignments = [*coaching, playing]
        add_level_1_hard_constraints(
            model, all_assignments, coaches=[{"id": "coach-1"}, {"id": "coach-other"}]
        )

        for a in all_assignments:
            model.Add(a.var == 1)

        status = _solve(model)
        assert status == cp_model.INFEASIBLE, (
            f"Coach-1 coaching 4 days + playing 1 day = 5 working days; "
            f"should be INFEASIBLE, got {status}"
        )

    def test_max_days_override_le_4_skips_constraint(self) -> None:
        """Coaches with max_days_override <= 4 are skipped (rest day already guaranteed)."""
        model = cp_model.CpModel()
        assignments = [
            _assignment(model, f"coach_day_{d}", slot_id=f"{d}:18:00", team_id=f"team-{d}")
            for d in range(1, 6)
        ]

        stats = add_level_1_hard_constraints(
            model, assignments, coaches=[{"id": "coach-1", "maxDaysOverride": 3}]
        )

        for a in assignments:
            model.Add(a.var == 1)

        status = _solve(model)
        assert stats.coach_rest_day == 0, (
            f"Coach with maxDaysOverride=3 should be skipped, "
            f"coach_rest_day={stats.coach_rest_day}"
        )
        assert status in (cp_model.FEASIBLE, cp_model.OPTIMAL), (
            f"Skipped coach should allow 5 days, got {status}"
        )

    def test_weekend_days_not_constrained(self) -> None:
        """Saturday (6) and Sunday (7) must NOT count toward the rest-day constraint.

        Coach works days 1-4 (Mon-Thu) + day 6 (Saturday) = 5 total days,
        but only 4 are Mon-Fri, so the constraint must be satisfied.
        """
        model = cp_model.CpModel()
        assignments = [
            _assignment(model, f"day_{d}", slot_id=f"{d}:18:00", team_id=f"team-{d}")
            for d in (1, 2, 3, 4, 6)  # Mon-Thu + Saturday
        ]

        add_level_1_hard_constraints(model, assignments, coaches=[{"id": "coach-1"}])

        for a in assignments:
            model.Add(a.var == 1)

        status = _solve(model)
        assert status in (cp_model.FEASIBLE, cp_model.OPTIMAL), (
            f"4 Mon-Fri days + Saturday should be feasible, got {status}"
        )

    def test_no_coaches_means_zero_constraints(self) -> None:
        """When no coaches are passed, coach_rest_day must be 0."""
        model = cp_model.CpModel()
        assignments = [
            _assignment(model, f"day_{d}", slot_id=f"{d}:18:00", team_id=f"team-{d}")
            for d in range(1, 6)
        ]

        stats = add_level_1_hard_constraints(model, assignments, coaches=[])

        assert stats.coach_rest_day == 0, (
            f"No coaches => coach_rest_day should be 0, got {stats.coach_rest_day}"
        )

    def test_total_constraints_includes_coach_rest_day(self) -> None:
        """total_constraints_added must include coach_rest_day in the sum."""
        model = cp_model.CpModel()
        assignments = [
            _assignment(model, f"day_{d}", slot_id=f"{d}:18:00", team_id=f"team-{d}")
            for d in range(1, 6)
        ]

        stats = add_level_1_hard_constraints(
            model, assignments, coaches=[{"id": "coach-1"}]
        )

        if stats.coach_rest_day > 0:
            expected_total = (
                stats.room_at_most_one
                + stats.coach_at_most_one
                + stats.coach_player_non_overlap
                + stats.team_no_overlap
                + stats.travel_feasibility_stub
                + stats.fixed_slots
                + stats.forbidden_assignments
                + stats.coach_unavailability
                + stats.required_bridge_stub
                + stats.min_sessions
                + stats.forced_venues
                + stats.one_session_per_day
                + stats.age_ascending
                + stats.coach_rest_day
            )
            assert stats.total_constraints_added == expected_total, (
                f"total_constraints_added={stats.total_constraints_added} "
                f"!= expected={expected_total}"
            )
