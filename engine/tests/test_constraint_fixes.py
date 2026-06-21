from __future__ import annotations

import asyncio
from collections import Counter

from app.main import build_schedule
from app.schemas.input_schema import ScheduleInputSchema


def make_team(team_id: str, sessions_per_week: int) -> dict[str, object]:
    return {
        "id": team_id,
        "sportCategoryId": "sc-1",
        "priorityTierId": 3,
        "name": team_id.replace("-", " ").title(),
        "sessionsPerWeek": sessions_per_week,
        "isActive": True,
    }


def make_slot(day_of_week: int, start_time: str, duration_minutes: int = 90) -> dict[str, object]:
    return {
        "dayOfWeek": day_of_week,
        "startTime": start_time,
        "durationMinutes": duration_minutes,
        "capacity": 1,
    }


def make_input(
    teams: list[dict[str, object]],
    constraints: list[dict[str, object]],
    slots_per_venue: list[dict[str, object]] | None = None,
) -> ScheduleInputSchema:
    return ScheduleInputSchema.model_validate(
        {
            "clubId": "club-constraint-fixes",
            "seasonId": "season-constraint-fixes",
            "teams": teams,
            "venues": [
                {
                    "id": "venue-1",
                    "name": "Gymnasium",
                    "isActive": True,
                    "trainingSlots": slots_per_venue or [],
                }
            ],
            "constraints": constraints,
            "slotTemplates": [],
        }
    )


def day_constraint(team_id: str, rule_type: str, config: dict[str, object]) -> dict[str, object]:
    return {
        "id": f"constraint-{team_id}-{rule_type.lower()}-day",
        "family": "DAY",
        "ruleType": rule_type,
        "scope": "TEAM",
        "scopeTargetId": team_id,
        "isActive": True,
        "config": config,
    }


def time_constraint(team_id: str, config: dict[str, object]) -> dict[str, object]:
    return {
        "id": f"constraint-{team_id}-time",
        "family": "TIME",
        "ruleType": "HARD",
        "scope": "TEAM",
        "scopeTargetId": team_id,
        "isActive": True,
        "config": config,
    }


def team_slots(result, team_id: str):
    return [slot for slot in result.slots if slot.team_id == team_id]


class TestForcedDays:
    def test_forced_single_day_sessions_per_week_1(self) -> None:
        input_data = make_input(
            teams=[make_team("team-1", 1)],
            constraints=[day_constraint("team-1", "HARD", {"forcedDays": [3]})],
            slots_per_venue=[
                make_slot(1, "18:00"),
                make_slot(3, "18:00"),
                make_slot(5, "18:00"),
            ],
        )

        result = asyncio.run(build_schedule(input_data))

        slots = team_slots(result, "team-1")
        assert result.status != "infeasible"
        assert len(slots) == 1
        assert slots[0].day_of_week == 3

    def test_forced_two_days_sessions_per_week_2(self) -> None:
        input_data = make_input(
            teams=[make_team("team-1", 2)],
            constraints=[day_constraint("team-1", "HARD", {"forcedDays": [1, 3]})],
            slots_per_venue=[
                make_slot(1, "18:00"),
                make_slot(3, "18:00"),
                make_slot(5, "18:00"),
            ],
        )

        result = asyncio.run(build_schedule(input_data))

        slots = team_slots(result, "team-1")
        days = Counter(slot.day_of_week for slot in slots)
        assert result.status != "infeasible"
        assert len(slots) == 2
        assert days[1] == 1
        assert days[3] == 1

    def test_forced_two_days_sessions_per_week_3(self) -> None:
        input_data = make_input(
            teams=[make_team("team-1", 3)],
            constraints=[day_constraint("team-1", "HARD", {"forcedDays": [1, 3]})],
            slots_per_venue=[
                make_slot(1, "18:00"),
                make_slot(3, "18:00"),
                make_slot(5, "18:00"),
            ],
        )

        result = asyncio.run(build_schedule(input_data))

        slots = team_slots(result, "team-1")
        days = Counter(slot.day_of_week for slot in slots)
        assert result.status != "infeasible"
        assert len(slots) == 3
        assert days[1] >= 1
        assert days[3] >= 1

    def test_forced_two_days_sessions_per_week_1_never_uses_free_day(self) -> None:
        input_data = make_input(
            teams=[make_team("team-1", 1)],
            constraints=[day_constraint("team-1", "HARD", {"forcedDays": [1, 3]})],
            slots_per_venue=[
                make_slot(1, "18:00"),
                make_slot(3, "18:00"),
                make_slot(5, "18:00"),
            ],
        )

        result = asyncio.run(build_schedule(input_data))

        slots = team_slots(result, "team-1")
        assert result.status != "infeasible"
        assert len(slots) == 1
        assert slots[0].day_of_week in {1, 3}
        assert slots[0].day_of_week != 5

    def test_forced_two_days_sessions_per_week_4_with_extra_days(self) -> None:
        input_data = make_input(
            teams=[make_team("team-1", 4)],
            constraints=[day_constraint("team-1", "HARD", {"forcedDays": [1, 3]})],
            slots_per_venue=[
                make_slot(1, "18:00"),
                make_slot(3, "18:00"),
                make_slot(4, "18:00"),
                make_slot(5, "18:00"),
            ],
        )

        result = asyncio.run(build_schedule(input_data))

        slots = team_slots(result, "team-1")
        days = Counter(slot.day_of_week for slot in slots)
        assert result.status != "infeasible"
        assert len(slots) == 4
        assert days[1] >= 1
        assert days[3] >= 1


class TestForcedAndForbiddenDays:
    def test_forced_and_forbidden_days_sessions_per_week_2(self) -> None:
        input_data = make_input(
            teams=[make_team("team-1", 2)],
            constraints=[
                day_constraint("team-1", "HARD", {"forcedDays": [3], "forbiddenDays": [6]}),
            ],
            slots_per_venue=[
                make_slot(1, "18:00"),
                make_slot(3, "18:00"),
                make_slot(6, "18:00"),
            ],
        )

        result = asyncio.run(build_schedule(input_data))

        slots = team_slots(result, "team-1")
        days = Counter(slot.day_of_week for slot in slots)
        assert result.status != "infeasible"
        assert len(slots) == 2
        assert days[3] == 1
        assert days[1] == 1
        assert days[6] == 0

    def test_forced_and_forbidden_days_sessions_per_week_3(self) -> None:
        input_data = make_input(
            teams=[make_team("team-1", 3)],
            constraints=[
                day_constraint("team-1", "HARD", {"forcedDays": [1], "forbiddenDays": [6]}),
            ],
            slots_per_venue=[
                make_slot(1, "18:00"),
                make_slot(3, "18:00"),
                make_slot(5, "18:00"),
                make_slot(6, "18:00"),
            ],
        )

        result = asyncio.run(build_schedule(input_data))

        slots = team_slots(result, "team-1")
        days = Counter(slot.day_of_week for slot in slots)
        assert result.status != "infeasible"
        assert len(slots) == 3
        assert days[1] == 1
        assert days[6] == 0
        assert days[3] + days[5] == 2


class TestConflictAndNonRegression:
    def test_day_constraint_conflict_returns_diagnostic_without_infeasible(self) -> None:
        input_data = make_input(
            teams=[make_team("team-conflict", 1), make_team("team-ok", 1)],
            constraints=[
                day_constraint(
                    "team-conflict",
                    "HARD",
                    {"forcedDays": [3], "forbiddenDays": [3]},
                ),
            ],
            slots_per_venue=[
                make_slot(1, "18:00"),
                make_slot(3, "18:00"),
            ],
        )

        result = asyncio.run(build_schedule(input_data))

        conflict_slots = team_slots(result, "team-conflict")
        ok_slots = team_slots(result, "team-ok")
        conflict_diagnostics = [diag for diag in result.diagnostics if diag.type == "day_constraint_conflict"]

        assert result.status != "infeasible"
        assert len(conflict_slots) == 0
        assert len(ok_slots) >= 1
        assert conflict_diagnostics
        assert conflict_diagnostics[0].severity == "ERROR"
        assert conflict_diagnostics[0].team_id == "team-conflict"

    def test_forbidden_days_still_block_forbidden_weekday(self) -> None:
        input_data = make_input(
            teams=[make_team("team-1", 1)],
            constraints=[day_constraint("team-1", "HARD", {"forbiddenDays": [1]})],
            slots_per_venue=[
                make_slot(1, "18:00"),
                make_slot(3, "18:00"),
            ],
        )

        result = asyncio.run(build_schedule(input_data))

        slots = team_slots(result, "team-1")
        assert result.status != "infeasible"
        assert len(slots) == 1
        assert slots[0].day_of_week == 3

    def test_hard_max_start_time_keeps_earlier_slot(self) -> None:
        input_data = make_input(
            teams=[make_team("team-1", 1)],
            constraints=[time_constraint("team-1", {"maxStartTime": "18:50"})],
            slots_per_venue=[
                make_slot(3, "17:30"),
                make_slot(3, "19:00"),
            ],
        )

        result = asyncio.run(build_schedule(input_data))

        slots = team_slots(result, "team-1")
        assert result.status != "infeasible"
        assert len(slots) == 1
        assert str(slots[0].start_time) == "17:30:00"

    def test_sessions_per_week_caps_team_assignments(self) -> None:
        input_data = make_input(
            teams=[make_team("team-1", 2)],
            constraints=[],
            slots_per_venue=[
                make_slot(1, "18:00"),
                make_slot(2, "18:00"),
                make_slot(3, "18:00"),
                make_slot(4, "18:00"),
                make_slot(5, "18:00"),
            ],
        )

        result = asyncio.run(build_schedule(input_data))

        slots = team_slots(result, "team-1")
        assert result.status != "infeasible"
        assert len(slots) <= 2

    def test_preferred_day_is_still_respected_softly(self) -> None:
        input_data = make_input(
            teams=[make_team("team-1", 1)],
            constraints=[day_constraint("team-1", "PREFERRED", {"preferredDays": [2]})],
            slots_per_venue=[
                make_slot(1, "18:00"),
                make_slot(2, "18:00"),
            ],
        )

        result = asyncio.run(build_schedule(input_data))

        slots = team_slots(result, "team-1")
        assert result.status != "infeasible"
        assert len(slots) == 1
        assert slots[0].day_of_week == 2
