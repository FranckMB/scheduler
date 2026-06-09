import unittest

from ortools.sat.python import cp_model

from app.solver.objective import (
    BONUS_WEIGHT_NAMES,
    LEVEL_2_OBJECTIVE_WEIGHTS,
    SCORE_FORMULA_VERSION,
    add_level_2_objective,
)


EXPECTED_WEIGHTS = {
    "S": 10000,
    "A": 1000,
    "SOFT": 800,
    "B": 100,
    "pref_link": 80,
    "preferred": 60,
    "grouping": 50,
    "C": 10,
    "max_days": 8,
    "opt_link": 5,
    "D": 1,
    "rest": 3,
}


class LevelTwoObjectiveTest(unittest.TestCase):
    def solve(self, model: cp_model.CpModel) -> tuple[int, cp_model.CpSolver]:
        solver = cp_model.CpSolver()
        solver.parameters.max_time_in_seconds = 2
        return solver.Solve(model), solver

    def test_fixed_weights_and_formula_version_are_locked(self):
        self.assertEqual(EXPECTED_WEIGHTS, dict(LEVEL_2_OBJECTIVE_WEIGHTS))
        self.assertEqual("T24_LEVEL_2_FIXED_WEIGHTS_V1", SCORE_FORMULA_VERSION)

        with self.assertRaises(TypeError):
            LEVEL_2_OBJECTIVE_WEIGHTS["S"] = 1

    def test_priority_tiers_have_expected_score_impact_order(self):
        for higher_tier, lower_tier in (("S", "A"), ("A", "B"), ("B", "C"), ("C", "D")):
            with self.subTest(higher_tier=higher_tier, lower_tier=lower_tier):
                model = cp_model.CpModel()
                higher = model.NewBoolVar(f"{higher_tier}_placed")
                lower = model.NewBoolVar(f"{lower_tier}_placed")
                model.AddExactlyOne(higher, lower)

                stats = add_level_2_objective(
                    model,
                    [
                        {"id": higher_tier, "var": higher, "priority_tier": higher_tier},
                        {"id": lower_tier, "var": lower, "priority_tier": lower_tier},
                    ],
                )
                status, solver = self.solve(model)

                self.assertEqual(cp_model.OPTIMAL, status)
                self.assertEqual(1, solver.Value(higher))
                self.assertEqual(0, solver.Value(lower))
                self.assertGreater(
                    stats.coefficient_by_assignment[higher_tier],
                    stats.coefficient_by_assignment[lower_tier],
                )

    def test_team_priority_tier_id_scores_each_placed_session(self):
        model = cp_model.CpModel()
        assignments = []
        teams = []

        for tier_id, tier_name in enumerate(("S", "A", "B", "C", "D"), start=1):
            variable = model.NewBoolVar(f"team_{tier_name}_placed")
            team_id = f"team-{tier_name}"
            assignments.append({"id": team_id, "var": variable, "team_id": team_id})
            teams.append({"id": team_id, "priority_tier_id": tier_id})

        stats = add_level_2_objective(model, assignments, teams=teams)

        for tier_name in ("S", "A", "B", "C", "D"):
            self.assertEqual(
                EXPECTED_WEIGHTS[tier_name],
                stats.coefficient_by_assignment[f"team-{tier_name}"],
            )

    def test_soft_constraint_respect_adds_fixed_bonuses(self):
        model = cp_model.CpModel()
        plain = model.NewBoolVar("plain_A_placed")
        with_bonuses = model.NewBoolVar("bonus_A_placed")
        model.AddExactlyOne(plain, with_bonuses)

        stats = add_level_2_objective(
            model,
            [
                {"id": "plain", "var": plain, "priority_tier": "A"},
                {
                    "id": "with-bonuses",
                    "var": with_bonuses,
                    "priority_tier": "A",
                    "soft_bonuses": BONUS_WEIGHT_NAMES,
                },
            ],
        )
        status, solver = self.solve(model)

        expected_bonus_score = EXPECTED_WEIGHTS["A"] + sum(
            EXPECTED_WEIGHTS[name] for name in BONUS_WEIGHT_NAMES
        )
        self.assertEqual(cp_model.OPTIMAL, status)
        self.assertEqual(EXPECTED_WEIGHTS["A"], stats.coefficient_by_assignment["plain"])
        self.assertEqual(expected_bonus_score, stats.coefficient_by_assignment["with-bonuses"])
        self.assertEqual(0, solver.Value(plain))
        self.assertEqual(1, solver.Value(with_bonuses))

    def test_score_formula_version_must_match_fixed_weights(self):
        model = cp_model.CpModel()
        variable = model.NewBoolVar("placed")

        with self.assertRaises(ValueError):
            add_level_2_objective(
                model,
                [{"var": variable, "priority_tier": "S"}],
                score_formula_version="T24_LEVEL_2_FIXED_WEIGHTS_V0",
            )


if __name__ == "__main__":
    unittest.main()
