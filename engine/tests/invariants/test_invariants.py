from __future__ import annotations

import pathlib
from collections import defaultdict
from typing import Any

import pytest
from hypothesis import given, settings
from hypothesis import strategies as st
from ortools.sat.python import cp_model

from app.main import read_contract_version
from app.solver.constraints import add_level_1_hard_constraints, parse_v2_constraints
from app.solver.model import build_model
from app.solver.objective import add_level_2_objective
from app.solver.result_builder import build_result
from tests.support.pipeline import solve_payload, team_coach

CONTRACT_VERSION = read_contract_version()

FIXTURES_DIR = pathlib.Path(__file__).resolve().parents[1] / "fixtures"


def _normalize_team_fields(data: dict[str, Any]) -> None:
    """Add snake_case aliases so constraints.py can read camelCase input."""
    for team in data.get("teams", []):
        if "sessionsPerWeek" in team and "sessions_per_week" not in team:
            team["sessions_per_week"] = team["sessionsPerWeek"]
        if "minSessionsOverride" in team and "min_sessions_override" not in team:
            team["min_sessions_override"] = team["minSessionsOverride"]
        if "forcedVenueId" in team and "forced_venue_id" not in team:
            team["forced_venue_id"] = team["forcedVenueId"]


# AUD-ENG-28 (soldé 2026-08-09) — les invariants tournent désormais sur `solve_payload`,
# c'est-à-dire sur `build_schedule`, le VRAI pipeline. Ce harnais local ne survit que pour
# UN test : celui du point d'extension `skip_rest_day_and_distribution`, que l'ADR-0001
# conserve « documenté et testé, mais dormant » et que la production n'emprunte jamais.
#
# Le report annoncé ici (« deferred to E1 ») redoutait à raison qu'une migration naïve vide
# les invariants coach de leur substance : la prod lit les coachs des contraintes
# TEAM_COACH, et les fixtures n'en portaient aucune — `team_coach_map` serait resté vide et
# « aucun coach n'est dédoublé » serait devenu vrai PAR ABSENCE DE COACH.
#
# Le piège n'a pas été évité en gardant un pipeline parallèle, mais en faisant porter aux
# fixtures la forme que le backend sérialise. `test_the_fixtures_really_exercise_coaches`
# interdit le retour en arrière.
def _run_pipeline(
    data: dict[str, Any],
    *,
    max_time_in_seconds: int = 5,
    skip_rest_day_and_distribution: bool = False,
    fallback_used: bool = False,
) -> dict[str, Any]:
    _normalize_team_fields(data)
    model = build_model(data)

    # Build assignments with coach_id so coach constraints are enforced.
    team_coaches: dict[str, str] = {}
    for tpl in data.get("slotTemplates", []):
        tid = tpl.get("teamId")
        cid = tpl.get("coachId")
        if tid and cid:
            team_coaches[tid] = cid

    assignments = []
    for slot_key, var in model.x.items():
        team_id, venue_id, day_of_week, slot_start = slot_key
        assignments.append(
            {
                "var": var,
                "team_id": team_id,
                "venue_id": venue_id,
                "slot_id": f"{day_of_week}:{slot_start}",
                "coach_id": team_coaches.get(team_id),
            }
        )

    add_level_1_hard_constraints(
        model,
        assignments,
        teams=data.get("teams", []),
        coaches=data.get("coaches", []),
        skip_rest_day_and_distribution=skip_rest_day_and_distribution,
    )

    # Add realistic upper bound: no team gets more than sessions_per_week.
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
    solver.parameters.max_time_in_seconds = max_time_in_seconds
    status = solver.Solve(model)
    return build_result(data, solver, model, status=status, fallback_used=fallback_used)


def _team_age_min_by_id(data: dict[str, Any]) -> dict[str, int | None]:
    return {team["id"]: team.get("ageMin") for team in data.get("teams", []) if team.get("id")}


def _hard_locked_team_ids(data: dict[str, Any]) -> set[str]:
    return {
        tpl["teamId"] for tpl in data.get("slotTemplates", []) if tpl.get("teamId") and tpl.get("lockLevel") == "HARD"
    }


# ---------------------------------------------------------------------------
# Hypothesis strategies
# ---------------------------------------------------------------------------


def _time_str(minutes: int) -> str:
    return f"{minutes // 60:02d}:{minutes % 60:02d}"


slot_start_st = st.sampled_from([17 * 60, 18 * 60, 19 * 60, 20 * 60])
duration_st = st.sampled_from([60, 90, 120])
day_st = st.sampled_from([1, 2, 3, 4, 5])
venue_id_st = st.sampled_from(["gym-a", "gym-b", "court-1"])
coach_id_st = st.sampled_from(["coach-1", "coach-2", "coach-3"])
team_id_st = st.sampled_from(["team-s", "team-a", "team-b", "team-c", "team-d"])
tier_st = st.sampled_from([1, 2, 3, 4, 5])


@st.composite
def random_fixture(draw: st.DrawFn) -> dict[str, Any]:
    num_venues = draw(st.integers(min_value=1, max_value=3))
    num_teams = draw(st.integers(min_value=1, max_value=5))
    num_coaches = draw(st.integers(min_value=1, max_value=3))

    venues = []
    for _ in range(num_venues):
        vid = draw(venue_id_st)
        if not any(v["id"] == vid for v in venues):
            venues.append({"id": vid, "name": f"Venue {vid}", "isActive": True})

    coaches = []
    for i in range(num_coaches):
        cid = draw(coach_id_st)
        if not any(c["id"] == cid for c in coaches):
            coaches.append({"id": cid, "firstName": f"Coach{i}", "lastName": "X", "isActive": True})

    teams = []
    for _ in range(num_teams):
        tid = draw(team_id_st)
        tier = draw(tier_st)
        if not any(t["id"] == tid for t in teams):
            teams.append(
                {
                    "id": tid,
                    "sportCategoryId": "sc-1",
                    "priorityTierId": tier,
                    "name": f"Team {tid}",
                    "sessionsPerWeek": draw(st.integers(min_value=1, max_value=2)),
                    "isActive": True,
                }
            )

    venue_avail_map: dict[str, list[dict[str, Any]]] = {v["id"]: [] for v in venues}
    for v in venues:
        for d in draw(st.lists(day_st, min_size=1, max_size=3, unique=True)):
            start = draw(slot_start_st)
            duration = draw(st.sampled_from([60, 120, 180]))
            venue_avail_map[v["id"]].append(
                {"dayOfWeek": d, "startTime": _time_str(start), "durationMinutes": duration, "capacity": 1}
            )

    templates = []
    seen_template_keys: set[tuple[str, str, int, str]] = set()
    seen_team_ids: set[str] = set()
    hard_locks = draw(st.lists(st.booleans(), min_size=0, max_size=2))
    for i, is_hard in enumerate(hard_locks):
        t = draw(st.sampled_from(teams)) if teams else None
        v = draw(st.sampled_from(venues)) if venues else None
        c = draw(st.sampled_from(coaches)) if coaches else None
        if t and v:
            # Limit each team to at most one template so result_builder
            # coach assignment is unambiguous.
            if t["id"] in seen_team_ids:
                continue
            seen_team_ids.add(t["id"])
            d = draw(day_st)
            start = draw(slot_start_st)
            tpl_key = (t["id"], v["id"], d, _time_str(start))
            if tpl_key in seen_template_keys:
                continue
            seen_template_keys.add(tpl_key)
            templates.append(
                {
                    "id": f"tpl-{i}",
                    "teamId": t["id"],
                    "venueId": v["id"],
                    "coachId": c["id"] if c else None,
                    "dayOfWeek": d,
                    "startTime": _time_str(start),
                    "durationMinutes": draw(duration_st),
                    "lockLevel": "HARD" if is_hard else "NONE",
                }
            )

    # Inject training slots into venue objects
    for v in venues:
        v["trainingSlots"] = venue_avail_map.get(v["id"], [])

    # AUD-ENG-28 — le coach voyage par une contrainte TEAM_COACH, comme en production.
    #
    # Le harnais local lisait `slotTemplates[].coachId` ; la prod, elle, construit son
    # `team_coach_map` depuis `parse_v2_constraints`. Migrer les invariants sur le vrai
    # pipeline SANS émettre ces contraintes aurait vidé les invariants coach de leur
    # substance : `team_coach_map` serait resté vide, aucune contrainte coach n'aurait été
    # posée, et « aucun coach n'est dédoublé » serait devenu vrai par absence de coach.
    #
    # C'est le piège que le commentaire « deferred to E1 » signalait. On ne l'évite pas en
    # gardant un pipeline parallèle : on l'évite en faisant porter aux fixtures la forme
    # que le backend sérialise vraiment.
    # CHAQUE équipe porte un coach MAIN, comme en production — pas seulement celles qui
    # ont un template. Limiter les coachs aux équipes templatées rendait
    # `test_no_coach_double_booking` VIDE : au plus deux équipes en avaient un, et
    # neutraliser les deux contraintes coach du solveur laissait l'invariant vert.
    coach_by_team = {t["id"]: draw(st.sampled_from(coaches))["id"] for t in teams} if coaches else {}
    constraints = [team_coach(f"tc-{i}", tid, cid) for i, (tid, cid) in enumerate(coach_by_team.items())]

    # Le coach du template suit la même carte : deux vérités sur « qui coache cette
    # équipe » se contrediraient, et c'est le template que `result_builder` affiche.
    for tpl in templates:
        if tpl["teamId"] in coach_by_team:
            tpl["coachId"] = coach_by_team[tpl["teamId"]]

    return {
        "clubId": "club-hypothesis",
        "seasonId": "season-2024",
        "version": CONTRACT_VERSION,
        "solverSeed": 42,
        "venues": venues,
        "teams": teams,
        "coaches": coaches,
        "slotTemplates": templates,
        "constraints": constraints,
        "priorityTiers": [
            {"id": 1, "label": "S", "orToolsWeight": 10000, "defaultMinSessions": 2},
            {"id": 2, "label": "A", "orToolsWeight": 1000, "defaultMinSessions": 2},
            {"id": 3, "label": "B", "orToolsWeight": 100, "defaultMinSessions": 1},
            {"id": 4, "label": "C", "orToolsWeight": 10, "defaultMinSessions": 1},
            {"id": 5, "label": "D", "orToolsWeight": 1, "defaultMinSessions": 1},
        ],
    }


# ---------------------------------------------------------------------------
# Invariant tests
# ---------------------------------------------------------------------------


def test_the_fixtures_really_exercise_coaches() -> None:
    """AUD-ENG-28 — le garde qui empêche les invariants coach de devenir vides.

    Les invariants tournent sur le vrai pipeline, qui lit les coachs des contraintes
    TEAM_COACH (`parse_v2_constraints`) et de nulle part ailleurs. Si les fixtures cessaient
    d'en émettre — par exemple en revenant au `slotTemplates[].coachId` d'avant —
    `team_coach_map` redeviendrait vide, aucune contrainte coach ne serait posée, et
    `test_no_coach_double_booking` passerait **sans rien vérifier**.

    C'est le mode de panne le plus dangereux d'une suite de tests : elle reste verte en
    ayant cessé de prouver quoi que ce soit.
    """
    payloads = [random_fixture().example() for _ in range(30)]
    with_coach = [p for p in payloads if parse_v2_constraints(p["constraints"]).get("team_coach_map")]

    assert with_coach, (
        "Aucune des 30 fixtures générées ne produit de team_coach_map : les invariants coach "
        "ne vérifient plus rien. Les fixtures doivent émettre des contraintes TEAM_COACH — "
        "c'est de là, et de là seulement, que la production tire ses coachs."
    )

    # Et le lien doit être FIDÈLE : chaque coach épinglé sur un template atteint la carte
    # que le solveur consomme.
    for payload in with_coach:
        mapped = parse_v2_constraints(payload["constraints"])["team_coach_map"]
        for tpl in payload["slotTemplates"]:
            if tpl.get("coachId"):
                assert tpl["coachId"] in mapped.get(tpl["teamId"], []), (
                    f"le coach {tpl['coachId']} de l'équipe {tpl['teamId']} n'atteint pas le solveur"
                )


class TestInvariants:
    @settings(max_examples=20, deadline=None)
    @given(data=random_fixture())
    def test_no_venue_double_booking(self, data: dict[str, Any]) -> None:
        result = solve_payload(data, timeout=5)
        if result["status"] != "completed":
            pytest.skip("Solver did not find a feasible solution")

        venue_bookings: dict[tuple[str, int, str], list[str]] = {}
        for slot in result["slots"]:
            key = (slot["venueId"], slot["dayOfWeek"], slot["startTime"])
            venue_bookings.setdefault(key, []).append(slot["teamId"])

        for key, team_ids in venue_bookings.items():
            assert len(team_ids) <= 1, f"Venue double-booking at {key}: {team_ids}"

    @settings(max_examples=20, deadline=None)
    @given(data=random_fixture())
    def test_no_coach_double_booking(self, data: dict[str, Any]) -> None:
        result = solve_payload(data, timeout=5)
        if result["status"] != "completed":
            pytest.skip("Solver did not find a feasible solution")

        # ⚑ D-14 (2026-08-09) — la clé porte le GYMNASE, et ce n'est pas un détail.
        #
        # Cet invariant épinglait « un coach, une équipe à la fois », gymnase ignoré. C'est
        # la règle que D-14 a RETIRÉE : un coach peut tenir les SM1 et les SM2 au même
        # moment dans le MÊME gymnase — il y est présent une fois, il surveille deux
        # groupes. Seuls deux gymnases DIFFÉRENTS restent une impossibilité physique.
        #
        # ⚠ Il a survécu à D-14 parce qu'il était VIDE : jusqu'à AUD-ENG-28, seules les
        # équipes portant un template avaient un coach, et le cas ne se produisait jamais.
        # Rendre l'invariant vivant a réveillé une règle périmée — il échouait 2 fois sur 8,
        # avec deux équipes du même gymnase pour « preuve ».
        #
        # La leçon vaut d'être gardée : un test qu'on croit inutile parce qu'il ne casse
        # jamais peut simplement ne rien vérifier, et sa règle continue de vieillir.
        # ⚑ Les créneaux VERROUILLÉS sont hors sujet ici, et c'est structurel : un verrou
        # HARD est pré-placé HORS du solveur (P2-9 PR B), sa variable n'existe pas, donc
        # aucune contrainte du moteur ne peut le voir — encore moins refuser qu'il dédouble
        # un coach. C'est le BACKEND qui l'interdit, en amont, au récap
        # (`CoachDoubleBookingDetector` bloque la génération).
        #
        # Mesuré, pas supposé : les cas qui faisaient rougir cet invariant opposaient
        # systématiquement un créneau `lockLevel: HARD` à un créneau placé par le solveur.
        # L'invariant porte donc sur ce que le MOTEUR décide — le seul périmètre dont il
        # répond. Y inclure les verrous ferait échouer le moteur pour une garantie qu'il
        # n'a jamais eue, et qui est tenue ailleurs.
        coach_bookings: dict[tuple[str, int, str, str], set[str]] = {}
        for slot in result["slots"]:
            coach_id = slot.get("coachId")
            if not coach_id or slot.get("lockLevel") == "HARD":
                continue
            key = (coach_id, slot["dayOfWeek"], slot["startTime"], slot["venueId"])
            coach_bookings.setdefault(key, set()).add(slot["teamId"])

        # Deux gymnases DIFFÉRENTS au même instant : impossibilité physique.
        by_moment: dict[tuple[str, int, str], set[str]] = {}
        for (coach_id, day, start, venue), _team_ids in coach_bookings.items():
            by_moment.setdefault((coach_id, day, start), set()).add(venue)

        for moment, venues in by_moment.items():
            assert len(venues) <= 1, f"Coach dans {len(venues)} gymnases à la fois — {moment} : {sorted(venues)}"

    @settings(max_examples=20, deadline=None)
    @given(data=random_fixture())
    def test_age_order_per_venue_day(self, data: dict[str, Any]) -> None:
        result = solve_payload(data, timeout=5)
        if result["status"] != "completed":
            pytest.skip("Solver did not find a feasible solution")

        age_min_by_team = _team_age_min_by_id(data)
        hard_locked_team_ids = _hard_locked_team_ids(data)

        slots_by_group: dict[tuple[str, int], list[dict[str, Any]]] = {}
        for slot in result["slots"]:
            key = (slot["venueId"], slot["dayOfWeek"])
            slots_by_group.setdefault(key, []).append(slot)

        for key, slots in slots_by_group.items():
            for i, slot_a in enumerate(slots):
                age_a = age_min_by_team.get(slot_a["teamId"])
                if age_a is None or slot_a["teamId"] in hard_locked_team_ids:
                    continue
                for slot_b in slots[i + 1 :]:
                    age_b = age_min_by_team.get(slot_b["teamId"])
                    if age_b is None or slot_b["teamId"] in hard_locked_team_ids:
                        continue
                    if age_a < age_b:
                        assert slot_a["startTime"] <= slot_b["startTime"], (
                            f"Age order violated at {key}: {slot_a['teamId']} ({age_a}) at {slot_a['startTime']} "
                            f"must start at or before {slot_b['teamId']} ({age_b}) at {slot_b['startTime']}"
                        )
                    elif age_b < age_a:
                        assert slot_b["startTime"] <= slot_a["startTime"], (
                            f"Age order violated at {key}: {slot_b['teamId']} ({age_b}) at {slot_b['startTime']} "
                            f"must start at or before {slot_a['teamId']} ({age_a}) at {slot_a['startTime']}"
                        )

    @settings(max_examples=20, deadline=None)
    @given(data=random_fixture())
    def test_coach_consistency(self, data: dict[str, Any]) -> None:
        result = solve_payload(data, timeout=5)
        if result["status"] != "completed":
            pytest.skip("Solver did not find a feasible solution")

        # Build map of expected coaches per (team, venue, day, time) from templates.
        expected_coaches: dict[tuple[str, str, int, str], str] = {}
        for tpl in data.get("slotTemplates", []):
            tid = tpl.get("teamId")
            cid = tpl.get("coachId")
            vid = tpl.get("venueId")
            dow = tpl.get("dayOfWeek")
            stime = tpl.get("startTime")
            if tid and cid and vid and dow is not None and stime:
                expected_coaches[(tid, vid, dow, stime)] = cid

        for slot in result["slots"]:
            tid = slot["teamId"]
            cid = slot.get("coachId")
            key = (tid, slot["venueId"], slot["dayOfWeek"], slot["startTime"])
            if key in expected_coaches and cid is not None:
                assert cid == expected_coaches[key], (
                    f"Slot for {tid} at {key} has coach {cid}, expected {expected_coaches[key]}"
                )

    def test_coach_rest_day_warning_matches_five_weekday_workload(self) -> None:
        data = {
            "clubId": "club-rest-day-warning",
            "seasonId": "season-2024",
            "version": "2.0",
            "solverSeed": 42,
            "venues": [
                {
                    "id": "venue-1",
                    "name": "Venue 1",
                    "isActive": True,
                    "trainingSlots": [
                        {"dayOfWeek": d, "startTime": "18:00", "durationMinutes": 60, "capacity": 1}
                        for d in range(1, 6)
                    ],
                }
            ],
            "teams": [
                {
                    "id": f"team-{d}",
                    "sportCategoryId": "sc-1",
                    "priorityTierId": 1,
                    "name": f"Team {d}",
                    "sessionsPerWeek": 1,
                    "isActive": True,
                }
                for d in range(1, 6)
            ],
            "coaches": [
                {
                    "id": "coach-1",
                    "firstName": "Coach",
                    "lastName": "One",
                    "isActive": True,
                }
            ],
            "slotTemplates": [
                {
                    "id": f"tpl-{d}",
                    "teamId": f"team-{d}",
                    "venueId": "venue-1",
                    "coachId": "coach-1",
                    "dayOfWeek": d,
                    "startTime": "18:00",
                    "durationMinutes": 60,
                    "lockLevel": "NONE",
                }
                for d in range(1, 6)
            ],
            "constraints": [],
            "priorityTiers": [
                {"id": 1, "label": "S", "orToolsWeight": 10000, "defaultMinSessions": 1},
            ],
        }

        first_pass = _run_pipeline(data)
        assert first_pass["status"] == "failed", "Pass 1 should fail when coach works all 5 weekdays"

        result = _run_pipeline(
            data,
            skip_rest_day_and_distribution=True,
            fallback_used=True,
        )

        assert result["status"] == "completed"

        coach_days: dict[str, set[int]] = defaultdict(set)
        for slot in result["slots"]:
            coach_id = slot.get("coachId")
            day_of_week = slot.get("dayOfWeek")
            if coach_id and day_of_week is not None and 1 <= int(day_of_week) <= 5:
                coach_days[str(coach_id)].add(int(day_of_week))

        warnings_by_coach = {
            str(diag["coachId"])
            for diag in result["diagnostics"]
            if diag.get("type") == "coach_no_rest_day" and diag.get("severity") == "WARNING" and diag.get("coachId")
        }

        for coach_id, days in coach_days.items():
            if len(days) == 5:
                assert coach_id in warnings_by_coach, (
                    f"Coach {coach_id} works all 5 weekdays, but no coach_no_rest_day WARNING was emitted"
                )

    @settings(max_examples=20, deadline=None)
    @given(data=random_fixture())
    def test_hard_locked_slots_preserved(self, data: dict[str, Any]) -> None:
        result = solve_payload(data, timeout=5)

        hard_templates = [t for t in data.get("slotTemplates", []) if t.get("lockLevel") == "HARD"]
        hard_slots = [s for s in result["slots"] if s.get("lockLevel") == "HARD"]

        assert len(hard_slots) == len(hard_templates), (
            f"Expected {len(hard_templates)} HARD slots, found {len(hard_slots)}"
        )

        for tpl in hard_templates:
            found = any(
                s["teamId"] == tpl["teamId"]
                and s["venueId"] == tpl["venueId"]
                and s["dayOfWeek"] == tpl["dayOfWeek"]
                # L'API rend une heure CANONIQUE ("18:00:00") ; la fixture, comme le
                # backend, écrit "18:00". On compare donc sur HH:MM. Le harnais local
                # renvoyait la forme d'entrée telle quelle, ce qui masquait cet écart —
                # et faisait croire que les deux bouts parlaient le même format.
                and str(s["startTime"])[:5] == tpl["startTime"][:5]
                for s in hard_slots
            )
            assert found, f"HARD slot for team {tpl['teamId']} not preserved"

    def test_tier_s_wins_over_tier_d_in_direct_conflict(self) -> None:
        """When only one slot exists and both S and D teams want it, S must be placed."""
        data = {
            "clubId": "club-priority",
            "seasonId": "season-2024",
            "version": "2.0",
            "solverSeed": 42,
            "venues": [
                {
                    "id": "gym-a",
                    "name": "Gym A",
                    "isActive": True,
                    "trainingSlots": [{"dayOfWeek": 1, "startTime": "18:00", "durationMinutes": 15, "capacity": 1}],
                }
            ],
            "teams": [
                {
                    "id": "team-s",
                    "sportCategoryId": "sc-1",
                    "priorityTierId": 1,
                    "name": "Team S",
                    "sessionsPerWeek": 1,
                    "isActive": True,
                },
                {
                    "id": "team-d",
                    "sportCategoryId": "sc-1",
                    "priorityTierId": 5,
                    "name": "Team D",
                    "sessionsPerWeek": 0,
                    "isActive": True,
                },
            ],
            "coaches": [],
            "slotTemplates": [],
            "constraints": [],
            "priorityTiers": [
                {"id": 1, "label": "S", "orToolsWeight": 10000, "defaultMinSessions": 1},
                {"id": 5, "label": "D", "orToolsWeight": 1, "defaultMinSessions": 0},
            ],
        }
        result = solve_payload(data, timeout=5)

        assert result["status"] == "completed"
        placed_teams = {s["teamId"] for s in result["slots"]}
        assert "team-s" in placed_teams, "S-tier team must be placed in a direct conflict"
        assert "team-d" not in placed_teams, "D-tier team must be sacrificed in a direct conflict"
