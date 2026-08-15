"""P4-97 bis — un créneau VERROUILLÉ est un FAIT du planning : toutes les règles s'y appliquent.

P4-97 a rendu les verrous visibles aux 4 règles « bien-être » (3b/3c/3d + plancher « au moins N à
V »). Les règles STRUCTURELLES — capacité gymnase (1), coach jamais dans 2 gymnases (2), coach-joueur
non simultané (3), une séance par jour et par équipe (11) — restaient AVEUGLES : le créneau
verrouillé n'a pas de variable (``model.py``), donc un placement LIBRE pouvait le contredire.

Chaque test épingle : « verrou + placement libre qui violerait la règle → le libre est REFUSÉ »
(forcé à 1 ⇒ INFEASIBLE), avec le témoin « non forcé ⇒ le modèle reste faisable, le libre est juste
non retenu ». Source des personnes : ``team_coach_map`` / ``team_player_map`` — JAMAIS ``slot.coachId``.
"""

from __future__ import annotations

from ortools.sat.python import cp_model

from app.solver.constraints import AssignmentVariable, add_level_1_hard_constraints

JDR = "jdr"
MATEO = "mateo"


def _solve(model: cp_model.CpModel) -> int:
    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = 5
    return solver.Solve(model)


def _locked(team_id: str, day: int, start_time: str, *, venue_id: str, duration: int = 90) -> dict:
    return {
        "team_id": team_id,
        "venue_id": venue_id,
        "day_of_week": day,
        "start_time": start_time,
        "duration_minutes": duration,
        "lock_level": "HARD",
    }


def _free(
    model: cp_model.CpModel,
    name: str,
    *,
    team_id: str,
    venue_id: str,
    day: int,
    start_min: int,
    duration: int = 90,
) -> AssignmentVariable:
    hh, mm = divmod(start_min, 60)
    return AssignmentVariable(
        var=model.NewBoolVar(name),
        team_id=team_id,
        slot_id=f"{day}:{hh:02d}:{mm:02d}",
        venue_id=venue_id,
        coach_id=None,  # les personnes viennent des cartes, jamais de coach_id (P4-97 bis)
        player_ids=(),
        start=start_min,
        end=start_min + duration,
    )


class TestCoachPlayerNonOverlap:
    """Règle 3 + le CAS RÉEL : « Mara » coache une équipe LIBRE pendant qu'elle JOUE dans une
    équipe VERROUILLÉE à la même heure dans un AUTRE gymnase."""

    def test_free_coaching_while_locked_playing_elsewhere_is_refused(self) -> None:
        model = cp_model.CpModel()
        model.locked_slots = (_locked("SM2", 4, "19:00", venue_id=JDR),)  # Mara JOUE, verrouillé
        free = _free(model, "sf2_free", team_id="SF2", venue_id=MATEO, day=4, start_min=19 * 60)  # Mara COACHE

        add_level_1_hard_constraints(
            model,
            assignments=[free],
            team_coach_map={"SF2": ["mara"]},
            team_player_map={"SM2": ["mara"]},
        )

        model.Add(free.var == 1)
        assert _solve(model) == cp_model.INFEASIBLE

    def test_free_coaching_while_locked_playing_elsewhere_is_droppable(self) -> None:
        model = cp_model.CpModel()
        model.locked_slots = (_locked("SM2", 4, "19:00", venue_id=JDR),)
        free = _free(model, "sf2_free", team_id="SF2", venue_id=MATEO, day=4, start_min=19 * 60)

        add_level_1_hard_constraints(
            model,
            assignments=[free],
            team_coach_map={"SF2": ["mara"]},
            team_player_map={"SM2": ["mara"]},
        )

        assert _solve(model) in (cp_model.FEASIBLE, cp_model.OPTIMAL)

    def test_free_coaching_while_locked_playing_same_venue_is_still_refused(self) -> None:
        """Même gymnase : coacher ET jouer restent deux rôles inconciliables (pas l'exemption D-14)."""
        model = cp_model.CpModel()
        model.locked_slots = (_locked("SM2", 4, "19:00", venue_id=JDR),)
        free = _free(model, "sf2_free", team_id="SF2", venue_id=JDR, day=4, start_min=19 * 60)

        add_level_1_hard_constraints(
            model,
            assignments=[free],
            team_coach_map={"SF2": ["mara"]},
            team_player_map={"SM2": ["mara"]},
        )

        model.Add(free.var == 1)
        assert _solve(model) == cp_model.INFEASIBLE


class TestCoachAtMostOne:
    """Règle 2 : un coach ne peut pas être dans DEUX gymnases à la fois — mais le MÊME gymnase
    reste autorisé (D-14)."""

    def test_free_coaching_while_locked_coaching_other_venue_is_refused(self) -> None:
        model = cp_model.CpModel()
        model.locked_slots = (_locked("A", 4, "19:00", venue_id=JDR),)
        free = _free(model, "b_free", team_id="B", venue_id=MATEO, day=4, start_min=19 * 60)

        add_level_1_hard_constraints(
            model,
            assignments=[free],
            team_coach_map={"A": ["coach-1"], "B": ["coach-1"]},
        )

        model.Add(free.var == 1)
        assert _solve(model) == cp_model.INFEASIBLE

    def test_free_coaching_while_locked_coaching_same_venue_is_allowed(self) -> None:
        """D-14 : le même coach tenant deux équipes dans le MÊME gymnase au même moment est permis
        — à condition que le gymnase ait la place (capacité 2 : deux équipes y tiennent)."""
        model = cp_model.CpModel()
        model.slot_capacities = {(JDR, 4, "19:00"): 2}
        model.locked_slots = (_locked("A", 4, "19:00", venue_id=JDR),)
        free = _free(model, "b_free", team_id="B", venue_id=JDR, day=4, start_min=19 * 60)

        add_level_1_hard_constraints(
            model,
            assignments=[free],
            team_coach_map={"A": ["coach-1"], "B": ["coach-1"]},
        )

        model.Add(free.var == 1)
        assert _solve(model) in (cp_model.FEASIBLE, cp_model.OPTIMAL)


class TestOneSessionPerDay:
    """Règle 11 + le second CAS RÉEL : SF2 a un jeudi VERROUILLÉ ; pas de second créneau ce jeudi."""

    def test_locked_thursday_refuses_a_free_second_session_that_day(self) -> None:
        model = cp_model.CpModel()
        model.locked_slots = (_locked("SF2", 4, "20:30", venue_id=JDR),)  # jeudi verrouillé
        free = _free(model, "sf2_free", team_id="SF2", venue_id=MATEO, day=4, start_min=19 * 60)

        add_level_1_hard_constraints(
            model,
            assignments=[free],
            teams=[{"id": "SF2", "sessionsPerWeek": 2}],
            team_coach_map={"SF2": ["mara"]},
        )

        model.Add(free.var == 1)
        assert _solve(model) == cp_model.INFEASIBLE

    def test_a_free_session_on_another_day_stays_feasible(self) -> None:
        """Témoin : un créneau libre un AUTRE jour que le jeudi verrouillé reste plaçable."""
        model = cp_model.CpModel()
        model.locked_slots = (_locked("SF2", 4, "20:30", venue_id=JDR),)
        free = _free(model, "sf2_tue", team_id="SF2", venue_id=MATEO, day=2, start_min=19 * 60)

        add_level_1_hard_constraints(
            model,
            assignments=[free],
            teams=[{"id": "SF2", "sessionsPerWeek": 2}],
            team_coach_map={"SF2": ["mara"]},
        )

        model.Add(free.var == 1)
        assert _solve(model) in (cp_model.FEASIBLE, cp_model.OPTIMAL)


class TestRoomCapacity:
    """Règle 1 : un verrou occupe une place ; un placement libre qui commence AVANT le verrou et
    le chevauche au même gymnase est refusé si la capacité est saturée."""

    def test_free_starting_before_lock_overlapping_full_capacity_is_refused(self) -> None:
        model = cp_model.CpModel()
        model.slot_capacities = {(JDR, 4, "18:00"): 1}
        model.locked_slots = (_locked("A", 4, "19:00", venue_id=JDR),)  # 19:00-20:30
        # Libre 18:00-19:30 : chevauche le verrou de 19:00 à 19:30 au même gymnase, capacité 1.
        free = _free(model, "b_free", team_id="B", venue_id=JDR, day=4, start_min=18 * 60)

        add_level_1_hard_constraints(model, assignments=[free], team_coach_map={"B": ["coach-2"]})

        model.Add(free.var == 1)
        assert _solve(model) == cp_model.INFEASIBLE

    def test_free_overlapping_lock_stays_feasible_when_capacity_is_two(self) -> None:
        """Capacité 2 : le verrou occupe UNE place ; il en reste une pour le créneau libre."""
        model = cp_model.CpModel()
        model.slot_capacities = {(JDR, 4, "18:00"): 2}
        model.locked_slots = (_locked("A", 4, "19:00", venue_id=JDR),)
        free = _free(model, "b_free", team_id="B", venue_id=JDR, day=4, start_min=18 * 60)

        add_level_1_hard_constraints(model, assignments=[free], team_coach_map={"B": ["coach-2"]})

        model.Add(free.var == 1)
        assert _solve(model) in (cp_model.FEASIBLE, cp_model.OPTIMAL)
