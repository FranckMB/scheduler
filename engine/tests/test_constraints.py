import unittest

from ortools.sat.python import cp_model

from app.solver.constraints import (
    AssignmentVariable,
    add_level_1_hard_constraints,
    add_required_bridge_stub,
    add_travel_feasibility_stub,
)


class LevelOneHardConstraintsTest(unittest.TestCase):
    def solve(self, model: cp_model.CpModel) -> int:
        solver = cp_model.CpSolver()
        solver.parameters.max_time_in_seconds = 2
        return solver.Solve(model)

    def assignment(
        self,
        model: cp_model.CpModel,
        name: str,
        *,
        team_id: str = "team-1",
        slot_id: str = "slot-1",
        venue_id: str = "venue-1",
        coach_id: str = "coach-1",
        **kwargs,
    ) -> AssignmentVariable:
        return AssignmentVariable(
            var=model.NewBoolVar(name),
            team_id=team_id,
            slot_id=slot_id,
            venue_id=venue_id,
            coach_id=coach_id,
            **kwargs,
        )

    def test_room_double_booking_is_impossible(self):
        model = cp_model.CpModel()
        first = self.assignment(model, "first", team_id="team-1", coach_id="coach-1")
        second = self.assignment(model, "second", team_id="team-2", coach_id="coach-2")

        stats = add_level_1_hard_constraints(model, [first, second])
        model.Add(first.var == 1)
        model.Add(second.var == 1)

        self.assertEqual(stats.room_at_most_one, 1)
        self.assertEqual(cp_model.INFEASIBLE, self.solve(model))

    def test_t22_model_x_keys_use_team_venue_day_and_start_order(self):
        model = cp_model.CpModel()
        first = model.NewBoolVar("first")
        second = model.NewBoolVar("second")
        assignments = {
            ("team-1", "venue-1", 1, "09:00"): first,
            ("team-2", "venue-1", 1, "09:00"): second,
        }

        stats = add_level_1_hard_constraints(model, assignments)
        model.Add(first == 1)
        model.Add(second == 1)

        self.assertEqual(stats.room_at_most_one, 1)
        self.assertEqual(cp_model.INFEASIBLE, self.solve(model))

    def test_coach_on_two_venues_at_same_time_is_impossible(self):
        model = cp_model.CpModel()
        first = self.assignment(model, "first", team_id="team-1", venue_id="venue-1")
        second = self.assignment(model, "second", team_id="team-2", venue_id="venue-2")

        stats = add_level_1_hard_constraints(model, [first, second])
        model.Add(first.var == 1)
        model.Add(second.var == 1)

        self.assertEqual(stats.coach_at_most_one, 1)
        self.assertEqual(cp_model.INFEASIBLE, self.solve(model))

    def test_coach_player_overlap_is_impossible(self):
        model = cp_model.CpModel()
        coaching = self.assignment(
            model, "coaching", team_id="team-1", venue_id="venue-1", coach_id="person-1"
        )
        playing = self.assignment(
            model,
            "playing",
            team_id="team-2",
            venue_id="venue-2",
            coach_id="coach-2",
            player_ids=("person-1",),
        )

        stats = add_level_1_hard_constraints(model, [coaching, playing])
        model.Add(coaching.var == 1)
        model.Add(playing.var == 1)

        self.assertEqual(stats.coach_player_non_overlap, 1)
        self.assertEqual(cp_model.INFEASIBLE, self.solve(model))

    def test_travel_and_required_bridge_are_satisfied_mvp_stubs(self):
        model = cp_model.CpModel()
        assignment = self.assignment(model, "kept")

        self.assertEqual(0, add_travel_feasibility_stub(model, [assignment]))
        self.assertEqual(0, add_required_bridge_stub(model, [assignment]))
        model.Add(assignment.var == 1)

        self.assertIn(self.solve(model), (cp_model.FEASIBLE, cp_model.OPTIMAL))

    def test_fixed_slot_is_forced_to_one(self):
        model = cp_model.CpModel()
        fixed = self.assignment(model, "fixed", fixed=True)

        stats = add_level_1_hard_constraints(model, [fixed])
        solver = cp_model.CpSolver()
        solver.parameters.max_time_in_seconds = 2
        status = solver.Solve(model)

        self.assertEqual(stats.fixed_slots, 1)
        self.assertIn(status, (cp_model.FEASIBLE, cp_model.OPTIMAL))
        self.assertEqual(1, solver.Value(fixed.var))

    def test_forbidden_assignment_is_forced_to_zero(self):
        model = cp_model.CpModel()
        forbidden = self.assignment(model, "forbidden", forbidden=True)

        stats = add_level_1_hard_constraints(model, [forbidden])
        model.Add(forbidden.var == 1)

        self.assertEqual(stats.forbidden_assignments, 1)
        self.assertEqual(cp_model.INFEASIBLE, self.solve(model))

    def test_coach_unavailable_assignment_is_forced_to_zero(self):
        model = cp_model.CpModel()
        unavailable = self.assignment(model, "unavailable", coach_unavailable=True)

        stats = add_level_1_hard_constraints(model, [unavailable])
        model.Add(unavailable.var == 1)

        self.assertEqual(stats.coach_unavailability, 1)
        self.assertEqual(cp_model.INFEASIBLE, self.solve(model))

    def test_closed_venue_assignment_is_forced_to_zero(self):
        model = cp_model.CpModel()
        closed = self.assignment(model, "closed", venue_closed=True)

        stats = add_level_1_hard_constraints(model, [closed])
        model.Add(closed.var == 1)

        self.assertEqual(stats.venue_closures, 1)
        self.assertEqual(cp_model.INFEASIBLE, self.solve(model))

    def test_min_sessions_effectif_is_guaranteed(self):
        model = cp_model.CpModel()
        assignments = [
            self.assignment(model, "first", team_id="team-1", slot_id="slot-1", venue_id="venue-1"),
            self.assignment(model, "second", team_id="team-1", slot_id="slot-2", venue_id="venue-1"),
            self.assignment(model, "third", team_id="team-1", slot_id="slot-3", venue_id="venue-1"),
        ]

        stats = add_level_1_hard_constraints(
            model, assignments, min_sessions_by_team={"team-1": 2}
        )
        solver = cp_model.CpSolver()
        solver.parameters.max_time_in_seconds = 2
        status = solver.Solve(model)

        self.assertEqual(stats.min_sessions, 1)
        self.assertIn(status, (cp_model.FEASIBLE, cp_model.OPTIMAL))
        self.assertGreaterEqual(sum(solver.Value(item.var) for item in assignments), 2)

    def test_other_venues_are_forced_to_zero_when_venue_is_forced(self):
        model = cp_model.CpModel()
        wanted = self.assignment(
            model, "wanted", team_id="team-1", session_id="session-1", venue_id="venue-1"
        )
        other = self.assignment(
            model, "other", team_id="team-1", session_id="session-1", venue_id="venue-2"
        )

        stats = add_level_1_hard_constraints(
            model, [wanted, other], forced_venues={("team-1", "session-1"): "venue-1"}
        )
        model.Add(other.var == 1)

        self.assertEqual(stats.forced_venues, 1)
        self.assertEqual(cp_model.INFEASIBLE, self.solve(model))


if __name__ == "__main__":
    unittest.main()
