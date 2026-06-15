from __future__ import annotations

import unittest
from typing import Any

from ortools.sat.python import cp_model

from app.schemas.output_schema import ScheduleOutputSchema
from app.solver.model import build_model
from app.solver.result_builder import build_result


class ResultBuilderTest(unittest.TestCase):
    def _solve(self, model: cp_model.CpModel) -> tuple[cp_model.CpSolver, int]:
        solver = cp_model.CpSolver()
        solver.parameters.max_time_in_seconds = 2
        status = solver.Solve(model)
        return solver, status

    def _minimal_data(self) -> dict[str, Any]:
        return {
            "clubId": "club-1",
            "seasonId": "season-1",
            "teams": [{"id": "team-1", "priorityTierId": 3, "sportCategoryId": "sc-1", "name": "Team 1"}],
            "venues": [{"id": "venue-1", "name": "Court A", "availability": [{"dayOfWeek": 1, "startTime": "09:00", "endTime": "10:00"}]}],
            "coaches": [],
            "slotTemplates": [],
        }

    def test_feasible_solution_produces_slots_and_empty_diagnostics(self):
        data = self._minimal_data()
        model = build_model(data)
        # Force the single available slot to 1 so the solution is feasible.
        for var in model.x.values():
            model.Add(var == 1)

        solver, status = self._solve(model)
        result = build_result(data, solver, model, status=status)

        self.assertEqual(result["status"], "completed")
        self.assertIsNotNone(result["score"])
        self.assertGreaterEqual(result["score"], 0)
        self.assertTrue(result["slots"])
        self.assertEqual(result["diagnostics"], [])

        # Validate against the Pydantic schema.
        validated = ScheduleOutputSchema.model_validate(result)
        self.assertEqual(validated.status, "completed")

    def test_infeasible_solution_returns_failed_status_and_diagnostics(self):
        data = self._minimal_data()
        model = build_model(data)
        # Force two conflicting assignments at the same venue/time.
        keys = list(model.x.keys())
        self.assertGreaterEqual(len(keys), 1)
        first_key = keys[0]
        model.Add(model.x[first_key] == 1)
        # Block the same venue slot so it's impossible.
        model.Add(model.x[first_key] == 0)

        solver, status = self._solve(model)
        result = build_result(data, solver, model, status=status)

        self.assertEqual(result["status"], "failed")
        self.assertIsNone(result["score"])
        self.assertTrue(result["diagnostics"])
        diag_types = {d["type"] for d in result["diagnostics"]}
        self.assertIn("conflict", diag_types)

        validated = ScheduleOutputSchema.model_validate(result)
        self.assertEqual(validated.status, "failed")

    def test_hard_locked_slots_are_preserved(self):
        data = self._minimal_data()
        data["slotTemplates"] = [
            {
                "id": "locked-1",
                "teamId": "team-1",
                "venueId": "venue-1",
                "dayOfWeek": 2,
                "startTime": "14:00",
                "durationMinutes": 60,
                "lockLevel": "HARD",
            },
        ]
        model = build_model(data)
        solver, status = self._solve(model)
        result = build_result(data, solver, model, status=status)

        hard_slots = [s for s in result["slots"] if s.get("lockLevel") == "HARD"]
        self.assertEqual(len(hard_slots), 1)
        self.assertEqual(hard_slots[0]["teamId"], "team-1")
        self.assertEqual(hard_slots[0]["venueId"], "venue-1")
        self.assertEqual(hard_slots[0]["dayOfWeek"], 2)
        self.assertEqual(hard_slots[0]["startTime"], "14:00")
        self.assertEqual(hard_slots[0]["durationMinutes"], 60)

    def test_all_teams_appear_in_output(self):
        data = self._minimal_data()
        data["teams"].append(
            {"id": "team-2", "priorityTierId": 2, "sportCategoryId": "sc-1", "name": "Team 2"}
        )
        model = build_model(data)
        # Do not force any variable to 1, so team-2 has no slot.
        solver, status = self._solve(model)
        result = build_result(data, solver, model, status=status)

        placed_teams = {s["teamId"] for s in result["slots"]}
        unplaced_diags = [d for d in result["diagnostics"] if d["type"] == "unplaced"]
        unplaced_teams = {d["teamId"] for d in unplaced_diags}

        all_teams = {"team-1", "team-2"}
        self.assertTrue(
            all_teams.issubset(placed_teams | unplaced_teams),
            f"Not all teams accounted for: placed={placed_teams}, unplaced={unplaced_teams}",
        )

    def test_soft_lock_moved_diagnostic(self):
        data = self._minimal_data()
        data["slotTemplates"] = [
            {
                "id": "soft-1",
                "teamId": "team-1",
                "venueId": "venue-1",
                "dayOfWeek": 1,
                "startTime": "09:00",
                "durationMinutes": 15,
                "lockLevel": "SOFT",
            },
        ]
        model = build_model(data)
        # Force the variable to 0 so the SOFT slot is "moved" (absent).
        for var in model.x.values():
            model.Add(var == 0)

        solver, status = self._solve(model)
        result = build_result(data, solver, model, status=status)

        soft_diags = [d for d in result["diagnostics"] if d["type"] == "soft_lock_moved"]
        self.assertTrue(soft_diags)
        self.assertEqual(soft_diags[0]["teamId"], "team-1")

    def test_coach_overload_diagnostic(self):
        data = self._minimal_data()
        data["coaches"] = [
            {"id": "coach-1", "firstName": "Alice", "lastName": "Smith", "maxDaysOverride": 1},
        ]
        data["slotTemplates"] = [
            {
                "id": "tpl-1",
                "teamId": "team-1",
                "venueId": "venue-1",
                "coachId": "coach-1",
                "dayOfWeek": 1,
                "startTime": "09:00",
                "durationMinutes": 15,
                "lockLevel": "NONE",
            },
            {
                "id": "tpl-2",
                "teamId": "team-1",
                "venueId": "venue-1",
                "coachId": "coach-1",
                "dayOfWeek": 1,
                "startTime": "09:15",
                "durationMinutes": 15,
                "lockLevel": "NONE",
            },
        ]
        # Expand availability to cover both slots.
        data["venues"][0]["availability"] = [{"dayOfWeek": 1, "startTime": "09:00", "endTime": "09:30"}]
        model = build_model(data)
        for var in model.x.values():
            model.Add(var == 1)

        solver, status = self._solve(model)
        result = build_result(data, solver, model, status=status)

        overload_diags = [d for d in result["diagnostics"] if d["type"] == "coach_overload"]
        self.assertTrue(overload_diags)
        self.assertEqual(overload_diags[0]["coachId"], "coach-1")

    def test_empty_diagnostics_when_everything_ok(self):
        data = self._minimal_data()
        data["coaches"] = [
            {"id": "coach-1", "firstName": "Alice", "lastName": "Smith"},
        ]
        data["slotTemplates"] = [
            {
                "id": "tpl-1",
                "teamId": "team-1",
                "venueId": "venue-1",
                "coachId": "coach-1",
                "dayOfWeek": 1,
                "startTime": "09:00",
                "durationMinutes": 15,
                "lockLevel": "NONE",
            },
        ]
        model = build_model(data)
        for var in model.x.values():
            model.Add(var == 1)

        solver, status = self._solve(model)
        result = build_result(data, solver, model, status=status)

        self.assertEqual(result["diagnostics"], [])


if __name__ == "__main__":
    unittest.main()
