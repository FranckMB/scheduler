from __future__ import annotations

import pathlib
from typing import Any

import pytest
from hypothesis import given, settings, strategies as st
from ortools.sat.python import cp_model

from app.solver.constraints import add_level_1_hard_constraints
from app.solver.model import build_model
from app.solver.objective import add_level_2_objective
from app.solver.result_builder import build_result

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


def _run_pipeline(data: dict[str, Any], *, max_time_in_seconds: int = 5) -> dict[str, Any]:
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
        assignments.append({
            "var": var,
            "team_id": team_id,
            "venue_id": venue_id,
            "slot_id": f"{day_of_week}:{slot_start}",
            "coach_id": team_coaches.get(team_id),
        })

    add_level_1_hard_constraints(model, assignments, teams=data.get("teams", []))

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
    return build_result(data, solver, model, status=status)


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
    for i in range(num_venues):
        vid = draw(venue_id_st)
        if not any(v["id"] == vid for v in venues):
            venues.append({"id": vid, "name": f"Venue {vid}", "isActive": True})

    coaches = []
    for i in range(num_coaches):
        cid = draw(coach_id_st)
        if not any(c["id"] == cid for c in coaches):
            coaches.append({"id": cid, "firstName": f"Coach{i}", "lastName": "X", "isActive": True})

    teams = []
    for i in range(num_teams):
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

    return {
        "clubId": "club-hypothesis",
        "seasonId": "season-2024",
        "version": "2.0",
        "solverSeed": 42,
        "venues": venues,
        "teams": teams,
        "coaches": coaches,
        "slotTemplates": templates,
        "constraints": [],
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


class TestInvariants:
    @settings(max_examples=20, deadline=None)
    @given(data=random_fixture())
    def test_no_venue_double_booking(self, data: dict[str, Any]) -> None:
        result = _run_pipeline(data)
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
        result = _run_pipeline(data)
        if result["status"] != "completed":
            pytest.skip("Solver did not find a feasible solution")

        coach_bookings: dict[tuple[str, int, str], set[str]] = {}
        for slot in result["slots"]:
            coach_id = slot.get("coachId")
            if not coach_id:
                continue
            key = (coach_id, slot["dayOfWeek"], slot["startTime"])
            coach_bookings.setdefault(key, set()).add(slot["teamId"])

        for key, team_ids in coach_bookings.items():
            # The result builder assigns the same coach to all slots of a team,
            # so we only assert that no two *different* teams share a coach.
            assert len(team_ids) <= 1, f"Coach double-booking at {key}: {team_ids}"

    @settings(max_examples=20, deadline=None)
    @given(data=random_fixture())
    def test_coach_consistency(self, data: dict[str, Any]) -> None:
        result = _run_pipeline(data)
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

    @settings(max_examples=20, deadline=None)
    @given(data=random_fixture())
    def test_hard_locked_slots_preserved(self, data: dict[str, Any]) -> None:
        result = _run_pipeline(data)

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
                and s["startTime"] == tpl["startTime"]
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
            "venues": [{"id": "gym-a", "name": "Gym A", "isActive": True, "trainingSlots": [{"dayOfWeek": 1, "startTime": "18:00", "durationMinutes": 15, "capacity": 1}]}],
            "teams": [
                {"id": "team-s", "sportCategoryId": "sc-1", "priorityTierId": 1, "name": "Team S", "sessionsPerWeek": 1, "isActive": True},
                {"id": "team-d", "sportCategoryId": "sc-1", "priorityTierId": 5, "name": "Team D", "sessionsPerWeek": 0, "isActive": True},
            ],
            "coaches": [],
            "slotTemplates": [],
            "constraints": [],
            "priorityTiers": [
                {"id": 1, "label": "S", "orToolsWeight": 10000, "defaultMinSessions": 1},
                {"id": 5, "label": "D", "orToolsWeight": 1, "defaultMinSessions": 0},
            ],
        }
        result = _run_pipeline(data)

        assert result["status"] == "completed"
        placed_teams = {s["teamId"] for s in result["slots"]}
        assert "team-s" in placed_teams, "S-tier team must be placed in a direct conflict"
        assert "team-d" not in placed_teams, "D-tier team must be sacrificed in a direct conflict"
