from __future__ import annotations

import asyncio
from typing import Any

import pytest

from app.main import build_schedule
from app.schemas.input_schema import ScheduleInputSchema
from app.schemas.output_schema import ScheduleOutputSchema


class TestGenerateContract:
    """Verify the engine /generate endpoint returns a valid ScheduleOutputSchema.

    Covers the solver contract fix (Task 1.1 + 1.2):
    - status, score, slots, diagnostics, metrics, unplaced are all present.
    - metrics fields are non-null when the solver completes.
    - unplaced is a list (empty or populated).
    """

    def test_build_schedule_minimal_input_returns_valid_contract(self) -> None:
        """Minimal valid input should still produce all contract fields."""
        input_data = ScheduleInputSchema.model_validate(
            {
                "clubId": "club-minimal",
                "seasonId": "season-minimal",
                "slotTemplates": [],
            }
        )

        result = asyncio.run(build_schedule(input_data))

        assert result.status == "completed", f"Expected status 'completed', got {result.status}"
        assert result.score is not None, "score should not be None"
        assert result.score == 0, f"Expected score 0 for empty input, got {result.score}"
        assert result.slots is not None, "slots should not be None"
        assert isinstance(result.slots, list), "slots should be a list"
        assert result.metrics is not None, "metrics should not be None"
        assert result.metrics.solver_version, "solver_version should be non-empty"
        assert result.metrics.nb_variables >= 0, "nb_variables should be >= 0"
        assert result.metrics.nb_constraints >= 0, "nb_constraints should be >= 0"
        assert result.metrics.wall_time_ms >= 0, "wall_time_ms should be >= 0"
        assert result.unplaced is not None, "unplaced should not be None"
        assert isinstance(result.unplaced, list), "unplaced should be a list"
        assert result.diagnostics is not None, "diagnostics should not be None"
        assert isinstance(result.diagnostics, list), "diagnostics should be a list"

        dumped = result.model_dump(by_alias=True)
        revalidated = ScheduleOutputSchema.model_validate(dumped)
        assert revalidated.status == result.status

    def test_build_schedule_with_teams_and_no_venues_generates_unplaced(self) -> None:
        """Teams without venue availability should appear in unplaced + diagnostics."""
        input_data = ScheduleInputSchema.model_validate(
            {
                "clubId": "club-unplaced",
                "seasonId": "season-unplaced",
                "teams": [
                    {"id": "team-a", "sportCategoryId": "sc-1", "priorityTierId": 1, "name": "Team A", "sessionsPerWeek": 2, "isActive": True},
                ],
                "slotTemplates": [],
            }
        )

        result = asyncio.run(build_schedule(input_data))

        assert result.status == "completed"
        assert result.score == 0
        assert len(result.slots) == 0
        assert "team-a" in result.unplaced, f"Expected team-a in unplaced, got {result.unplaced}"

        unplaced_diags = [d for d in result.diagnostics if d.type == "unplaced"]
        assert len(unplaced_diags) > 0, "Expected at least one unplaced diagnostic"
        assert unplaced_diags[0].team_id == "team-a"
        assert result.metrics is not None
        assert result.metrics.solver_version
        assert result.metrics.nb_variables >= 0
        assert result.metrics.nb_constraints >= 0
        assert result.metrics.wall_time_ms >= 0

    def test_build_schedule_hard_locked_slots_are_preserved(self) -> None:
        """HARD locked slots must appear in output even with no solver variables."""
        input_data = ScheduleInputSchema.model_validate(
            {
                "clubId": "club-locked",
                "seasonId": "season-locked",
                "teams": [
                    {"id": "team-a", "sportCategoryId": "sc-1", "priorityTierId": 1, "name": "Team A", "sessionsPerWeek": 1, "isActive": True},
                ],
                "venues": [
                    {"id": "venue-1", "name": "Court A", "isActive": True, "availability": [{"dayOfWeek": 2, "startTime": "14:00", "endTime": "15:00"}]},
                ],
                "slotTemplates": [
                    {
                        "id": "locked-1",
                        "teamId": "team-a",
                        "venueId": "venue-1",
                        "dayOfWeek": 2,
                        "startTime": "14:00",
                        "durationMinutes": 60,
                        "lockLevel": "HARD",
                    },
                ],
            }
        )

        result = asyncio.run(build_schedule(input_data))

        assert result.status == "completed"
        assert len(result.slots) == 1, f"Expected 1 hard-locked slot, got {len(result.slots)}"

        slot = result.slots[0]
        assert slot.team_id == "team-a"
        assert slot.venue_id == "venue-1"
        assert slot.day_of_week == 2
        assert str(slot.start_time) == "14:00:00"
        assert slot.duration_minutes == 60
        assert slot.lock_level == "HARD"
        assert result.metrics is not None
        assert result.metrics.nb_variables >= 0
        assert result.metrics.nb_constraints >= 0

    def test_build_schedule_with_venue_availabilities_produces_slots(self) -> None:
        """When venue availability is provided, solver should produce slots."""
        input_data = ScheduleInputSchema.model_validate(
            {
                "clubId": "club-slots",
                "seasonId": "season-slots",
                "teams": [
                    {"id": "team-a", "sportCategoryId": "sc-1", "priorityTierId": 1, "name": "Team A", "sessionsPerWeek": 1, "isActive": True},
                ],
                "venues": [
                    {"id": "venue-1", "name": "Court A", "isActive": True, "availability": [{"dayOfWeek": 1, "startTime": "18:00", "endTime": "19:00"}]},
                ],
                "slotTemplates": [
                    {
                        "id": "tpl-1",
                        "teamId": "team-a",
                        "venueId": "venue-1",
                        "dayOfWeek": 1,
                        "startTime": "18:00",
                        "durationMinutes": 60,
                        "lockLevel": "NONE",
                    },
                ],
            }
        )

        data: dict[str, Any] = input_data.model_dump(by_alias=True)

        from app.solver.model import build_model
        from app.solver.constraints import add_level_1_hard_constraints
        from app.solver.objective import add_level_2_objective
        from ortools.sat.python import cp_model
        from app.solver.result_builder import build_result

        model = build_model(data)
        add_level_1_hard_constraints(model, model.x, teams=data.get("teams", []))
        add_level_2_objective(model, model.x, teams=data.get("teams", []))
        solver = cp_model.CpSolver()
        solver.parameters.max_time_in_seconds = 2
        status = solver.Solve(model)
        result_dict = build_result(data, solver, model, status=status)
        result = ScheduleOutputSchema.model_validate(result_dict)

        assert result.status == "completed"
        assert result.score is not None
        assert result.score > 0, f"Expected positive score, got {result.score}"
        assert len(result.slots) > 0, "Expected at least one slot"
        assert result.metrics is not None
        assert result.metrics.nb_variables > 0
        assert result.metrics.nb_constraints >= 0
        assert result.metrics.wall_time_ms >= 0
        assert result.metrics.solver_version
        assert result.unplaced is not None
        assert isinstance(result.unplaced, list)

    @pytest.mark.timeout(60)
    def test_build_schedule_medium_club_fixture_produces_non_trivial_output(self) -> None:
        """Load the medium_club fixture and verify contract."""
        import json
        import pathlib

        fixtures_dir = pathlib.Path(__file__).resolve().parent / "fixtures"
        with open(fixtures_dir / "medium_club.json", encoding="utf-8") as f:
            data: dict[str, Any] = json.load(f)

        from app.solver.model import build_model
        from app.solver.constraints import add_level_1_hard_constraints
        from app.solver.objective import add_level_2_objective
        from ortools.sat.python import cp_model
        from app.solver.result_builder import build_result

        for team in data.get("teams", []):
            if "sessionsPerWeek" in team and "sessions_per_week" not in team:
                team["sessions_per_week"] = team["sessionsPerWeek"]
            if "minSessionsOverride" in team and "min_sessions_override" not in team:
                team["min_sessions_override"] = team["minSessionsOverride"]
            if "forcedVenueId" in team and "forced_venue_id" not in team:
                team["forced_venue_id"] = team["forcedVenueId"]

        model = build_model(data)

        team_coaches: dict[str, str] = {}
        for tpl in data.get("slotTemplates", []):
            tid = tpl.get("teamId")
            cid = tpl.get("coachId")
            if tid and cid:
                team_coaches[tid] = cid

        assignments = []
        for slot_key, var in model.x.items():
            team_id, venue_id, day_of_week, slot_start = slot_key
            assignments.append({
                "var": var,
                "team_id": team_id,
                "venue_id": venue_id,
                "slot_id": f"{day_of_week}:{slot_start}",
                "coach_id": team_coaches.get(team_id),
            })

        add_level_1_hard_constraints(model, assignments, teams=data.get("teams", []))

        assignments_by_team: dict[str, list[Any]] = {}
        for assignment in assignments:
            tid = assignment["team_id"]
            if tid:
                assignments_by_team.setdefault(tid, []).append(assignment["var"])

        for team in data.get("teams", []):
            tid = team.get("id")
            max_sessions = team.get("sessions_per_week") or team.get("sessionsPerWeek")
            if tid and max_sessions:
                team_vars = assignments_by_team.get(tid, [])
                if team_vars:
                    model.Add(sum(team_vars) <= int(max_sessions))

        add_level_2_objective(model, assignments, teams=data.get("teams", []))
        solver = cp_model.CpSolver()
        solver.parameters.max_time_in_seconds = 10
        status = solver.Solve(model)
        result_dict = build_result(data, solver, model, status=status)
        result = ScheduleOutputSchema.model_validate(result_dict)

        assert result.status == "completed"
        assert result.score is not None
        assert result.score > 0
        assert len(result.slots) > 0
        assert result.metrics.nb_variables > 10, f"Expected >10 variables, got {result.metrics.nb_variables}"
        assert result.metrics.nb_constraints > 10, f"Expected >10 constraints, got {result.metrics.nb_constraints}"
        assert result.metrics.wall_time_ms >= 0
        assert result.metrics.solver_version
        assert result.unplaced is not None
        assert isinstance(result.unplaced, list)
        assert result.diagnostics is not None
        assert isinstance(result.diagnostics, list)

    def test_build_schedule_with_min_session_minutes_does_not_fail(self) -> None:
        """Solver must not return FAILED when teams have minSessionMinutes set.

        Regression guard for the bug where 'not min_session_minutes' in main.py
        excluded those teams from max-cap, causing INFEASIBLE.
        """
        input_data = ScheduleInputSchema.model_validate(
            {
                "clubId": "club-min-session-minutes",
                "seasonId": "season-min-session-minutes",
                "teams": [
                    {
                        "id": "team-competitive",
                        "sportCategoryId": "sc-1",
                        "priorityTierId": 1,
                        "name": "Team Competitive",
                        "sessionsPerWeek": 3,
                        "minSessionMinutes": 90,
                        "isActive": True,
                    },
                ],
                "venues": [
                    {
                        "id": "venue-1",
                        "name": "Court A",
                        "isActive": True,
                        "availability": [
                            {"dayOfWeek": 1, "startTime": "18:00", "endTime": "21:00"},
                            {"dayOfWeek": 3, "startTime": "18:00", "endTime": "21:00"},
                            {"dayOfWeek": 5, "startTime": "18:00", "endTime": "21:00"},
                        ],
                    }
                ],
                "slotTemplates": [],
            }
        )

        result = asyncio.run(build_schedule(input_data))

        assert result.status != "failed", (
            f"Solver returned 'failed' for team with minSessionMinutes=90; "
            f"status={result.status}, unplaced={result.unplaced}"
        )
        # Max-cap check: each session = 90 min = 6 slots of 15-min → max 3*6=18 slots
        assert len(result.slots) <= 3 * 6, (
            f"Expected at most 18 slot assignments (3 sessions × 6 slots), got {len(result.slots)}"
        )

    def test_build_schedule_fully_locked_team_does_not_fail(self) -> None:
        """Solver must not return FAILED when a team has all sessions already hard-locked.

        Regression guard: team with sessionsPerWeek=1 and 1 HARD-locked slot has
        remaining=0. Without adjusted_min_by_team, max_cap adds sum(vars)<=0 while
        min_sessions adds sum(vars)>=1 → immediate INFEASIBLE.
        With the fix, adjusted_min=0 for this team → no conflict.
        """
        input_data = ScheduleInputSchema.model_validate(
            {
                "clubId": "club-fully-locked",
                "seasonId": "season-fully-locked",
                "teams": [
                    {
                        "id": "team-locked",
                        "sportCategoryId": "sc-1",
                        "priorityTierId": 1,
                        "name": "Team Fully Locked",
                        "sessionsPerWeek": 1,
                        "isActive": True,
                    },
                ],
                "venues": [
                    {
                        "id": "venue-1",
                        "name": "Court A",
                        "isActive": True,
                        "availability": [
                            {"dayOfWeek": 2, "startTime": "18:00", "endTime": "20:00"},
                        ],
                    }
                ],
                "slotTemplates": [
                    {
                        "id": "locked-slot-1",
                        "teamId": "team-locked",
                        "venueId": "venue-1",
                        "dayOfWeek": 2,
                        "startTime": "18:00",
                        "durationMinutes": 90,
                        "lockLevel": "HARD",
                    },
                ],
            }
        )

        result = asyncio.run(build_schedule(input_data))

        assert result.status != "failed", (
            f"Solver returned 'failed' for fully-locked team; "
            f"status={result.status}, unplaced={result.unplaced}"
        )
        # The team has 1 hard-locked session — it must appear in output
        assert len(result.slots) >= 1, (
            f"Expected at least 1 slot (the hard-locked one), got {len(result.slots)}"
        )
