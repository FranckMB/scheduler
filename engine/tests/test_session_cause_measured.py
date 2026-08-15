"""P4-99 PR-1 — la cause d'une séance manquante, MESURÉE à la source.

Le principe fondateur : la cause d'un créneau fermé est ENREGISTRÉE au moment EXACT où le
solveur ferme le candidat (décision B), jamais reconstituée après coup en re-testant la règle.
Ces tests MUTENT les vraies fonctions de contrainte (pas un double) et prouvent que le bon
motif atterrit sur la bonne variable, que la dégradation (contrainte sans id) ne lève jamais,
que le modèle NU reste inchangé, et que « resté ouvert » est compté sans être inventé.
"""

from __future__ import annotations

import unittest

from ortools.sat.python import cp_model

from app.schemas.output_schema import ScheduleOutputSchema
from app.solver.constraints import (
    AssignmentVariable,
    add_coach_unavailability_constraints,
    add_forced_venue_constraints,
    add_time_window_constraints,
)
from app.solver.model import ScheduleCpModel, build_model
from app.solver.result_builder import _collect_session_causes, build_result


class RecordedClosureCauseTest(unittest.TestCase):
    def test_forced_venue_closure_records_cause_on_the_right_variable(self) -> None:
        model = ScheduleCpModel()
        here = AssignmentVariable(var=model.NewBoolVar("here"), team_id="t1", venue_id="vA")
        elsewhere = AssignmentVariable(var=model.NewBoolVar("elsewhere"), team_id="t1", venue_id="vB")
        model.forced_venue_sources = {"t1": {"constraint_id": "c-forced", "label": "Vilar imposé"}}

        added = add_forced_venue_constraints(model, [here, elsewhere], forced_venues={"t1": "vA"})

        self.assertEqual(added, 1)
        # Le candidat AILLEURS porte la cause ; le gymnase imposé lui-même n'est jamais fermé.
        self.assertNotIn(here.var.Index(), model.candidate_closures)
        self.assertEqual(
            model.candidate_closures[elsewhere.var.Index()],
            [{"kind": "forced_venue_elsewhere", "constraintId": "c-forced", "label": "Vilar imposé"}],
        )

    def test_coach_unavailability_records_coach_and_constraint(self) -> None:
        model = ScheduleCpModel()
        # slot_id "3:18:00" = mercredi 18h00 ; l'indispo couvre toute la journée.
        assignment = AssignmentVariable(var=model.NewBoolVar("a"), team_id="t1", venue_id="vA", slot_id="3:18:00")
        model.coach_unavailability_sources = {
            "coach-x": [{"constraint_id": "c-unavail", "label": "Indispo Marie", "intervals": [(3, 0, 1440)]}]
        }

        added = add_coach_unavailability_constraints(
            model,
            [assignment],
            {"coach-x": {(3, 0, 1440)}},
            team_coach_map={"t1": ["coach-x"]},
        )

        self.assertEqual(added, 1)
        cause = model.candidate_closures[assignment.var.Index()][0]
        self.assertEqual(cause["kind"], "coach_unavailability")
        self.assertEqual(cause["coachId"], "coach-x")
        self.assertEqual(cause["constraintId"], "c-unavail")
        self.assertEqual(cause["label"], "Indispo Marie")

    def test_coach_unavailability_attributes_to_the_constraint_that_actually_matches(self) -> None:
        # Le défaut à falsifier : un coach porte DEUX indispos (lundi ET vendredi) ; c'est celle
        # de VENDREDI qui ferme ce créneau. La cause DOIT porter l'id de la seconde, jamais le
        # premier venu (`coach_sources[0]` = « c-mon » = une contrainte sans rapport).
        model = ScheduleCpModel()
        assignment = AssignmentVariable(var=model.NewBoolVar("fri"), team_id="t1", venue_id="vA", slot_id="5:18:00")
        model.coach_unavailability_sources = {
            "coach-x": [
                {"constraint_id": "c-mon", "label": "Indispo lundi", "intervals": [(1, 0, 1440)]},
                {"constraint_id": "c-fri", "label": "Indispo vendredi", "intervals": [(5, 0, 1440)]},
            ]
        }

        add_coach_unavailability_constraints(
            model,
            [assignment],
            {"coach-x": {(1, 0, 1440), (5, 0, 1440)}},
            team_coach_map={"t1": ["coach-x"]},
        )

        causes = model.candidate_closures[assignment.var.Index()]
        self.assertEqual(len(causes), 1)
        self.assertEqual(causes[0]["constraintId"], "c-fri")
        self.assertEqual(causes[0]["label"], "Indispo vendredi")
        self.assertEqual(causes[0]["coachId"], "coach-x")

    def test_two_coach_constraints_covering_the_same_slot_yield_two_causes(self) -> None:
        # Deux règles couvrent le MÊME moment → deux causes (les deux ferment réellement,
        # c'est mesuré) ; miroir de day_forbidden multi-sources.
        model = ScheduleCpModel()
        assignment = AssignmentVariable(var=model.NewBoolVar("fri"), team_id="t1", venue_id="vA", slot_id="5:18:00")
        model.coach_unavailability_sources = {
            "coach-x": [
                {"constraint_id": "c-a", "label": "Règle A", "intervals": [(5, 0, 1440)]},
                {"constraint_id": "c-b", "label": "Règle B", "intervals": [(5, 1000, 1440)]},
            ]
        }

        add_coach_unavailability_constraints(
            model,
            [assignment],
            {"coach-x": {(5, 0, 1440), (5, 1000, 1440)}},
            team_coach_map={"t1": ["coach-x"]},
        )

        constraint_ids = {c["constraintId"] for c in model.candidate_closures[assignment.var.Index()]}
        self.assertEqual(constraint_ids, {"c-a", "c-b"})

    def test_day_forbidden_records_the_source_constraint(self) -> None:
        model = ScheduleCpModel()
        var = model.NewBoolVar("wed")
        x = {("t1", "vA", 3, "18:00"): var}
        time_windows = [
            {
                "family": "DAY",
                "ruleType": "HARD",
                "scopeTargetId": "t1",
                "id": "c-day",
                "name": "Pas le mercredi",
                "config": {"forbiddenDays": [3]},
            }
        ]

        added, _conflicts = add_time_window_constraints(model, x, time_windows)

        self.assertEqual(added, 1)
        self.assertIn(
            {"kind": "day_forbidden", "constraintId": "c-day", "label": "Pas le mercredi"},
            model.candidate_closures[var.Index()],
        )

    def test_day_forbidden_without_id_degrades_to_null_and_never_raises(self) -> None:
        model = ScheduleCpModel()
        var = model.NewBoolVar("wed")
        x = {("t1", "vA", 3, "18:00"): var}
        # Contrainte héritée : NI id NI name — la cause dégrade au kind seul, jamais un KeyError.
        time_windows = [{"family": "DAY", "ruleType": "HARD", "scopeTargetId": "t1", "config": {"forbiddenDays": [3]}}]

        add_time_window_constraints(model, x, time_windows)

        self.assertEqual(
            model.candidate_closures[var.Index()],
            [{"kind": "day_forbidden", "constraintId": None, "label": None}],
        )

    def test_bare_cp_model_records_nothing_and_behaviour_is_unchanged(self) -> None:
        # Les tests unitaires de contrainte passent des cp_model.CpModel NUS : pas d'attribut
        # custom → aucun enregistrement, pose strictement inchangée.
        model = cp_model.CpModel()
        here = AssignmentVariable(var=model.NewBoolVar("here"), team_id="t1", venue_id="vA")
        elsewhere = AssignmentVariable(var=model.NewBoolVar("elsewhere"), team_id="t1", venue_id="vB")

        added = add_forced_venue_constraints(model, [here, elsewhere], forced_venues={"t1": "vA"})

        self.assertEqual(added, 1)  # comportement identique : l'ailleurs est bien fermé
        self.assertFalse(hasattr(model, "candidate_closures"))

    def test_build_model_records_lock_removed_candidate_of_other_team(self) -> None:
        data = {
            "teams": [{"id": "A"}, {"id": "B"}],
            "venues": [
                {
                    "id": "vA",
                    "trainingSlots": [{"dayOfWeek": 1, "startTime": "18:00", "durationMinutes": 90, "capacity": 1}],
                }
            ],
            "slotTemplates": [
                {
                    "teamId": "A",
                    "venueId": "vA",
                    "dayOfWeek": 1,
                    "startTime": "18:00",
                    "durationMinutes": 90,
                    "lockLevel": "HARD",
                }
            ],
        }
        model = build_model(data)

        # Le verrou de A occupe le créneau : le candidat de B y est retiré → cause hard_lock.
        self.assertEqual(
            model.lock_removed_candidates.get(("B", "vA", 1, "18:00")),
            {"kind": "hard_lock"},
        )
        # Le créneau verrouillé de A EST sa séance : jamais compté comme un candidat retiré.
        self.assertNotIn(("A", "vA", 1, "18:00"), model.lock_removed_candidates)


class CollectSessionCausesTest(unittest.TestCase):
    def test_open_candidate_is_counted_but_never_invented(self) -> None:
        data = {
            "teams": [{"id": "T", "name": "T", "sessionsPerWeek": 2}],
            "venues": [
                {
                    "id": "vA",
                    "name": "Court A",
                    "trainingSlots": [
                        {"dayOfWeek": 1, "startTime": "18:00", "durationMinutes": 90, "capacity": 1},
                        {"dayOfWeek": 2, "startTime": "18:00", "durationMinutes": 90, "capacity": 1},
                    ],
                }
            ],
            "coaches": [],
            "slotTemplates": [],
        }
        model = build_model(data)
        keys = list(model.x)
        self.assertEqual(len(keys), 2)
        # Une séance placée, l'autre créneau LIBRE laissé ouvert (non fermé, non retenu).
        model.Add(model.x[keys[0]] == 1)
        model.Add(model.x[keys[1]] == 0)

        solver = cp_model.CpSolver()
        solver.parameters.max_time_in_seconds = 2
        status = solver.Solve(model)

        result = build_result(data, solver, model, status=status)
        below = [d for d in result["diagnostics"] if d["type"] == "session_below_effective_min"]
        self.assertEqual(len(below), 1)
        # Le créneau libre est COMPTÉ… et aucune cause n'est inventée (rien ne l'a fermé).
        self.assertEqual(below[0]["openCandidates"], 1)
        self.assertEqual(below[0]["causes"], [])

        # Le schéma complet (extra="forbid") accepte les nouveaux champs.
        validated = ScheduleOutputSchema.model_validate(result)
        self.assertEqual(validated.status, "completed")

    def test_aggregates_recorded_closures_by_kind_with_counts(self) -> None:
        model = ScheduleCpModel()
        v1 = model.NewBoolVar("v1")
        v2 = model.NewBoolVar("v2")
        v3 = model.NewBoolVar("v3")
        model.x = {
            ("T", "vA", 1, "18:00"): v1,
            ("T", "vA", 2, "18:00"): v2,
            ("T", "vA", 3, "18:00"): v3,
        }
        # v1 + v2 fermés par la MÊME règle jour → count 2 ; v3 resté ouvert.
        model.candidate_closures = {
            v1.Index(): [{"kind": "day_forbidden", "constraintId": "c-day", "label": "Pas le lundi"}],
            v2.Index(): [{"kind": "day_forbidden", "constraintId": "c-day", "label": "Pas le lundi"}],
        }
        for var in (v1, v2, v3):
            model.Add(var == 0)

        solver = cp_model.CpSolver()
        solver.parameters.max_time_in_seconds = 2
        solver.Solve(model)

        causes_by_team = _collect_session_causes(model, solver)
        entry = causes_by_team["T"]
        self.assertEqual(
            entry["causes"],
            [{"kind": "day_forbidden", "constraintId": "c-day", "label": "Pas le lundi", "count": 2}],
        )
        self.assertEqual(entry["openCandidates"], 1)


if __name__ == "__main__":
    unittest.main()
