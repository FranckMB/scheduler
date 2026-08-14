"""P4-97 — les séances VERROUILLÉES (HARD) comptent pour les règles du solveur.

Une séance verrouillée n'a PAS de variable dans le modèle (`model.py` la retire) : les
quatre consommateurs des variables (3b repos coach, 3c distribution salariés, 3d dos-à-dos,
plancher « au moins N à V ») ne la voyaient donc jamais. Résultat mesuré sur le terrain :
INFEASIBLE fantôme (3c), bornes trop laxes (3b), chaînes mixtes invisibles à la POSE (3d),
faux diagnostic `venue_minimum_unreachable` (plancher). Ces tests épinglent que la séance
verrouillée entre désormais comme CONSTANTE — traduite en personnes via `team_coach_map` /
`team_player_map` (JAMAIS `slot.coachId`).
"""

from __future__ import annotations

from ortools.sat.python import cp_model

from app.solver.constraints import (
    COACH_REST_VIOLATION_WEIGHT,
    SALARIE_VIOLATION_WEIGHT,
    AssignmentVariable,
    ResolvedImplicitRules,
    add_level_1_hard_constraints,
    add_venue_minimum_constraints,
)

# Deux salariés se répartissent les jours pour qu'AUCUN ne travaille les 5 jours ouvrés
# (sinon c'est le repos coach 3b — et non 3c — qui rendrait le modèle infaisable).
_SALARIE_BY_DAY = {1: "s1", 2: "s1", 3: "s1", 4: "s2", 5: "s2"}


def _solve(model: cp_model.CpModel) -> int:
    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = 5
    return solver.Solve(model)


def _locked(team_id: str, day: int, start_time: str, *, venue_id: str = "venue-1", duration: int = 90) -> dict:
    return {
        "team_id": team_id,
        "venue_id": venue_id,
        "day_of_week": day,
        "start_time": start_time,
        "duration_minutes": duration,
        "lock_level": "HARD",
    }


def _assignment(
    model: cp_model.CpModel,
    name: str,
    *,
    team_id: str,
    slot_id: str,
    venue_id: str = "venue-1",
    start: int | None = None,
    end: int | None = None,
) -> AssignmentVariable:
    return AssignmentVariable(
        var=model.NewBoolVar(name),
        team_id=team_id,
        slot_id=slot_id,
        venue_id=venue_id,
        coach_id=None,  # P4-97 : le mapping passe par team_coach_map, jamais par coach_id.
        player_ids=(),
        start=start,
        end=end,
    )


class TestCoachRestDayCountsLockedSessions:
    def test_coach_locked_five_days_stays_feasible_lock_is_sovereign(self) -> None:
        """Coach verrouillé les CINQ jours ouvrés : la borne HARD n'est PAS posée (verrou
        souverain, ALIGN-07) → la génération ne devient jamais infaisable pour ça. La
        violation est laissée au diagnostic post-solve (result_builder), pas au blocage.
        """
        model = cp_model.CpModel()
        model.locked_slots = tuple(_locked(f"team-{d}", d, "18:00") for d in range(1, 6))
        team_coach_map = {f"team-{d}": ["coach-1"] for d in range(1, 6)}

        add_level_1_hard_constraints(
            model,
            assignments=[],
            coaches=[{"id": "coach-1"}],
            team_coach_map=team_coach_map,
        )

        assert _solve(model) in (cp_model.FEASIBLE, cp_model.OPTIMAL)

    def test_preferred_lights_the_rest_literal_when_locks_exceed_the_cap(self) -> None:
        """PREFERRED : coach verrouillé 5 jours → le littéral de repos est VRAI (violation réelle,
        pénalité méritée) même sans aucune variable libre. Avant P4-97 : littéral sous-compté
        (verrous invisibles → aucun jour travaillé compté → aucun littéral vrai).
        """
        model = cp_model.CpModel()
        model.locked_slots = tuple(_locked(f"team-{d}", d, "18:00") for d in range(1, 6))
        team_coach_map = {f"team-{d}": ["coach-1"] for d in range(1, 6)}

        stats = add_level_1_hard_constraints(
            model,
            assignments=[],
            coaches=[{"id": "coach-1"}],
            team_coach_map=team_coach_map,
            implicit_rules=ResolvedImplicitRules(coach_rest_day_intensity="PREFERRED"),
        )

        rest_lits = [lit for lit, tag in stats.implicit_soft_terms if tag == COACH_REST_VIOLATION_WEIGHT]
        assert len(rest_lits) == 1
        model.Maximize(sum(rest_lits))  # peut-il rester à 0 ? non : il est forcé vrai
        solver = cp_model.CpSolver()
        solver.Solve(model)
        assert solver.Value(rest_lits[0]) == 1, "cinq jours verrouillés dépassent le plafond → littéral vrai"

    def test_four_locked_days_plus_one_free_refuses_the_fifth(self) -> None:
        """Quatre jours verrouillés + une 5ᵉ séance LIBRE : la libre est refusée (5 > 4)."""
        model = cp_model.CpModel()
        model.locked_slots = tuple(_locked(f"team-{d}", d, "18:00") for d in range(1, 5))
        free = _assignment(model, "free_day5", team_id="team-5", slot_id="5:18:00", start=18 * 60, end=19 * 60 + 30)
        team_coach_map = {f"team-{d}": ["coach-1"] for d in range(1, 6)}

        add_level_1_hard_constraints(
            model,
            assignments=[free],
            coaches=[{"id": "coach-1"}],
            team_coach_map=team_coach_map,
        )

        model.Add(free.var == 1)
        assert _solve(model) == cp_model.INFEASIBLE

    def test_four_locked_days_leave_the_fifth_free_slot_droppable(self) -> None:
        """Contrôle : sans forcer la 5ᵉ, le modèle reste faisable (la libre est simplement non retenue)."""
        model = cp_model.CpModel()
        model.locked_slots = tuple(_locked(f"team-{d}", d, "18:00") for d in range(1, 5))
        free = _assignment(model, "free_day5", team_id="team-5", slot_id="5:18:00", start=18 * 60, end=19 * 60 + 30)
        team_coach_map = {f"team-{d}": ["coach-1"] for d in range(1, 6)}

        add_level_1_hard_constraints(
            model,
            assignments=[free],
            coaches=[{"id": "coach-1"}],
            team_coach_map=team_coach_map,
        )

        assert _solve(model) in (cp_model.FEASIBLE, cp_model.OPTIMAL)


class TestSalarieDistributionCountsLockedSessions:
    def test_all_salarie_sessions_locked_is_no_longer_infeasible(self) -> None:
        """Chaque jour ouvré a un salarié VERROUILLÉ → plus d'INFEASIBLE fantôme (HARD)."""
        model = cp_model.CpModel()
        model.locked_slots = tuple(_locked(f"team-{d}", d, "18:00") for d in range(1, 6))
        team_coach_map = {f"team-{d}": [_SALARIE_BY_DAY[d]] for d in range(1, 6)}

        add_level_1_hard_constraints(
            model,
            assignments=[],
            coaches=[{"id": "s1", "isEmployee": True}, {"id": "s2", "isEmployee": True}],
            team_coach_map=team_coach_map,
        )

        assert _solve(model) in (cp_model.FEASIBLE, cp_model.OPTIMAL)

    def test_preferred_lights_no_phantom_literal_when_days_are_locked(self) -> None:
        """PREFERRED : cinq jours couverts par un salarié verrouillé → ZÉRO littéral de violation.

        Avant P4-97 les cinq jours s'allumaient (littéraux fantômes) — le −30 mesuré du seed.
        """
        model = cp_model.CpModel()
        model.locked_slots = tuple(_locked(f"team-{d}", d, "18:00") for d in range(1, 6))
        team_coach_map = {f"team-{d}": [_SALARIE_BY_DAY[d]] for d in range(1, 6)}

        stats = add_level_1_hard_constraints(
            model,
            assignments=[],
            coaches=[{"id": "s1", "isEmployee": True}, {"id": "s2", "isEmployee": True}],
            team_coach_map=team_coach_map,
            implicit_rules=ResolvedImplicitRules(salarie_distribution_intensity="PREFERRED"),
        )

        salarie_lits = [lit for lit, tag in stats.implicit_soft_terms if tag == SALARIE_VIOLATION_WEIGHT]
        assert len(salarie_lits) == 5, "un littéral agrégé par jour ouvré reste posé"
        model.Minimize(sum(salarie_lits))
        assert _solve(model) in (cp_model.FEASIBLE, cp_model.OPTIMAL)
        solver = cp_model.CpSolver()
        solver.Solve(model)
        assert solver.ObjectiveValue() == 0, "aucun jour n'est réellement sans salarié → aucun littéral vrai"

    def test_preferred_lights_only_the_genuinely_empty_day(self) -> None:
        """PREFERRED : 4 jours verrouillés + 1 jour VRAIMENT vide → un seul littéral vrai."""
        model = cp_model.CpModel()
        model.locked_slots = tuple(_locked(f"team-{d}", d, "18:00") for d in range(1, 5))
        team_coach_map = {f"team-{d}": [_SALARIE_BY_DAY[d]] for d in range(1, 5)}

        stats = add_level_1_hard_constraints(
            model,
            assignments=[],
            coaches=[{"id": "s1", "isEmployee": True}, {"id": "s2", "isEmployee": True}],
            team_coach_map=team_coach_map,
            implicit_rules=ResolvedImplicitRules(salarie_distribution_intensity="PREFERRED"),
        )

        salarie_lits = [lit for lit, tag in stats.implicit_soft_terms if tag == SALARIE_VIOLATION_WEIGHT]
        model.Minimize(sum(salarie_lits))
        solver = cp_model.CpSolver()
        solver.Solve(model)
        assert solver.ObjectiveValue() == 1, "seul le jour 5, réellement sans salarié, allume son littéral"


class TestMaxConsecutiveCountsLockedSessions:
    def test_two_locked_plus_one_adjacent_free_refuses_the_free(self) -> None:
        """Chaîne 2 verrouillés + 1 libre adjacente (18:00→19:30→21:00) → la libre est refusée."""
        model = cp_model.CpModel()
        model.locked_slots = (
            _locked("team-1", 1, "18:00"),
            _locked("team-2", 1, "19:30"),
        )
        free = _assignment(model, "free_2100", team_id="team-3", slot_id="1:21:00", start=21 * 60, end=22 * 60 + 30)
        team_coach_map = {"team-1": ["coach-1"], "team-2": ["coach-1"], "team-3": ["coach-1"]}

        add_level_1_hard_constraints(
            model,
            assignments=[free],
            coaches=[{"id": "coach-1"}],
            team_coach_map=team_coach_map,
        )

        model.Add(free.var == 1)
        assert _solve(model) == cp_model.INFEASIBLE

    def test_two_locked_plus_one_adjacent_free_is_feasible_when_free_dropped(self) -> None:
        """Contrôle : sans forcer la libre, le modèle reste faisable (elle est juste non retenue)."""
        model = cp_model.CpModel()
        model.locked_slots = (
            _locked("team-1", 1, "18:00"),
            _locked("team-2", 1, "19:30"),
        )
        free = _assignment(model, "free_2100", team_id="team-3", slot_id="1:21:00", start=21 * 60, end=22 * 60 + 30)
        team_coach_map = {"team-1": ["coach-1"], "team-2": ["coach-1"], "team-3": ["coach-1"]}

        add_level_1_hard_constraints(
            model,
            assignments=[free],
            coaches=[{"id": "coach-1"}],
            team_coach_map=team_coach_map,
        )

        assert _solve(model) in (cp_model.FEASIBLE, cp_model.OPTIMAL)


class TestVenueMinimumCreditsLockedDays:
    def _model_with_free_days(self, days: list[int], locked_slots: tuple[dict, ...]) -> cp_model.CpModel:
        model = cp_model.CpModel()
        model.locked_slots = locked_slots
        model.x = {("team-1", "venue-1", d, "18:00"): model.NewBoolVar(f"x_{d}") for d in days}
        return model

    def test_team_pinned_n_times_yields_no_constraint_and_no_diagnostic(self) -> None:
        """Équipe épinglée N=2 fois à V : plancher saturé par les réservations → rien à poser.

        Avant P4-97 : `min=2` avec 1 seul jour libre → faux `venue_minimum_unreachable`.
        """
        model = self._model_with_free_days(
            days=[3],  # un seul jour libre — insuffisant à lui seul pour min=2
            locked_slots=(_locked("team-1", 1, "18:00"), _locked("team-1", 2, "18:00")),
        )
        added, conflicts = add_venue_minimum_constraints(
            model,
            model.x,
            [{"scope_target_id": "team-1", "venue_id": "venue-1", "min": 2}],
        )
        assert added == 0
        assert conflicts == []

    def test_team_pinned_n_minus_one_needs_one_free_day(self) -> None:
        """Épinglée N-1=1 fois, min=2 → effective_min=1 : contrainte posée s'il reste un jour libre."""
        model = self._model_with_free_days(
            days=[3],
            locked_slots=(_locked("team-1", 1, "18:00"),),
        )
        added, conflicts = add_venue_minimum_constraints(
            model,
            model.x,
            [{"scope_target_id": "team-1", "venue_id": "venue-1", "min": 2}],
        )
        assert added == 1
        assert conflicts == []

    def test_team_pinned_n_minus_one_diagnoses_only_without_free_day(self) -> None:
        """Épinglée 1 fois, min=2, et le SEUL jour disponible est le jour verrouillé (exclu) →
        aucun jour libre distinct → diagnostic ERROR (jamais un INFEASIBLE silencieux)."""
        model = self._model_with_free_days(
            days=[1],  # le seul jour candidat coïncide avec le jour verrouillé
            locked_slots=(_locked("team-1", 1, "18:00"),),
        )
        added, conflicts = add_venue_minimum_constraints(
            model,
            model.x,
            [{"scope_target_id": "team-1", "venue_id": "venue-1", "min": 2}],
        )
        assert added == 0
        assert len(conflicts) == 1
        assert conflicts[0]["type"] == "venue_minimum_unreachable"
