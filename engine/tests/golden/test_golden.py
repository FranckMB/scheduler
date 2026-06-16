from __future__ import annotations

import json
import pathlib
from typing import Any

import pytest
from ortools.sat.python import cp_model

from app.solver.constraints import add_level_1_hard_constraints
from app.solver.model import SLOT_MINUTES, build_model
from app.solver.objective import add_level_2_objective
from app.solver.result_builder import build_result

FIXTURES_DIR = pathlib.Path(__file__).resolve().parents[1] / "fixtures"

DENSE_CLUB_BASELINE_SCORE = 117679


def _load_fixture(name: str) -> dict[str, Any]:
    path = FIXTURES_DIR / f"{name}.json"
    with open(path, encoding="utf-8") as f:
        return json.load(f)


def _normalize_team_fields(data: dict[str, Any]) -> None:
    """Add snake_case aliases so constraints.py can read camelCase input."""
    for team in data.get("teams", []):
        if "sessionsPerWeek" in team and "sessions_per_week" not in team:
            team["sessions_per_week"] = team["sessionsPerWeek"]
        if "minSessionsOverride" in team and "min_sessions_override" not in team:
            team["min_sessions_override"] = team["minSessionsOverride"]
        if "forcedVenueId" in team and "forced_venue_id" not in team:
            team["forced_venue_id"] = team["forcedVenueId"]


def _run_pipeline(data: dict[str, Any], *, max_time_in_seconds: int = 10) -> dict[str, Any]:
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


class TestGoldenDatasets:
    def test_simple_club_is_optimal(self) -> None:
        data = _load_fixture("simple_club")
        result = _run_pipeline(data)

        assert result["status"] == "completed"
        assert result["score"] is not None
        assert len(result["slots"]) > 0
        assert result["diagnostics"] == []

    def test_medium_club_is_feasible_or_optimal(self) -> None:
        data = _load_fixture("medium_club")
        result = _run_pipeline(data, max_time_in_seconds=30)

        assert result["status"] == "completed"
        assert result["score"] is not None
        assert len(result["slots"]) > 0
        # Allow diagnostics for soft-lock moves or coach overloads in larger clubs.
        conflict_diags = [d for d in result["diagnostics"] if d["type"] == "conflict"]
        assert conflict_diags == []

    @pytest.mark.timeout(180)
    def test_dense_club_is_feasible_within_180s(self) -> None:
        data = _load_fixture("dense_club")
        result = _run_pipeline(data, max_time_in_seconds=180)

        assert result["status"] == "completed"
        assert result["score"] is not None
        assert len(result["slots"]) > 0
        conflict_diags = [d for d in result["diagnostics"] if d["type"] == "conflict"]
        assert conflict_diags == []
        assert result["score"] >= DENSE_CLUB_BASELINE_SCORE * 0.95

    def test_impossible_is_infeasible_with_diagnostics(self) -> None:
        data = _load_fixture("impossible")
        result = _run_pipeline(data)

        assert result["status"] == "failed"
        assert result["score"] is None
        assert any(d["type"] == "conflict" for d in result["diagnostics"])

    def test_vacation_week_is_feasible_and_respects_tiers(self) -> None:
        data = _load_fixture("vacation_week")
        result = _run_pipeline(data, max_time_in_seconds=30)

        assert result["status"] == "completed"
        assert result["score"] is not None
        assert len(result["slots"]) > 0
        conflict_diags = [d for d in result["diagnostics"] if d["type"] == "conflict"]
        assert conflict_diags == []

        # Verify tier S and A teams have at least their min sessions.
        team_sessions: dict[str, int] = {}
        for slot in result["slots"]:
            tid = slot["teamId"]
            team_sessions[tid] = team_sessions.get(tid, 0) + int(slot["durationMinutes"]) // SLOT_MINUTES

        tier_s_a_teams = [
            t for t in data["teams"] if t["priorityTierId"] in (1, 2) and t.get("isActive", False)
        ]
        for team in tier_s_a_teams:
            min_sessions = team.get("minSessionsOverride") or team["sessionsPerWeek"]
            placed = team_sessions.get(team["id"], 0)
            assert placed >= min_sessions, (
                f"Team {team['id']} (tier {team['priorityTierId']}) "
                f"has {placed} sessions, expected >= {min_sessions}"
            )
