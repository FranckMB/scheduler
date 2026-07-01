from __future__ import annotations

import asyncio
from pathlib import Path
from typing import Any, cast

from fastapi import FastAPI, HTTPException, status
from fastapi.responses import JSONResponse
from ortools.sat.python import cp_model
from pydantic import BaseModel, ConfigDict, Field, ValidationError

from app.core.config import get_settings
from app.schemas.input_schema import ScheduleInputSchema
from app.schemas.output_schema import ScheduleOutputSchema
from app.solver.constraints import add_level_1_hard_constraints, add_time_window_constraints, parse_v2_constraints
from app.solver.model import DEFAULT_SESSION_MINUTES, ScheduleCpModel, build_model, _time_to_minutes
from app.solver.objective import add_level_2_objective, add_preferred_day_bonus, is_team_satisfied_by_hard_locks, LEVEL_2_OBJECTIVE_WEIGHTS
from app.solver.result_builder import build_result

ENGINE_ROOT = Path(__file__).resolve().parents[1]
CONTRACT_VERSION_PATH = ENGINE_ROOT / "CONTRACT_VERSION"
IMPLICIT_RULES_PATH = ENGINE_ROOT / "implicit_rules.json"

settings = get_settings()
app = FastAPI(title=settings.app_name, version=settings.app_version)

_club_locks: dict[str, asyncio.Lock] = {}
_club_locks_guard = asyncio.Lock()


class SerializableModel(BaseModel):
    model_config = ConfigDict(extra="forbid", populate_by_name=True)


class ImplicitRuleSchema(SerializableModel):
    name: str
    enabled: bool
    description: str


class ImplicitConstraintSyncRequest(SerializableModel):
    version: str
    rules: list[ImplicitRuleSchema] = Field(default_factory=list)


def read_contract_version() -> str:
    try:
        return CONTRACT_VERSION_PATH.read_text(encoding="utf-8").strip()
    except FileNotFoundError:
        return settings.contract_version


def read_implicit_rules() -> ImplicitConstraintSyncRequest:
    try:
        return ImplicitConstraintSyncRequest.model_validate_json(
            IMPLICIT_RULES_PATH.read_text(encoding="utf-8"),
        )
    except FileNotFoundError as exc:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="implicit_rules.json not found",
        ) from exc
    except ValidationError as exc:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="implicit_rules.json is invalid",
        ) from exc


def _day_constraint_conflict_team_ids(time_windows: list[dict[str, Any]]) -> set[str]:
    forced_days_by_team: dict[str, set[int]] = {}
    forbidden_days_by_team: dict[str, set[int]] = {}

    for constraint in time_windows or []:
        if not constraint.get("isActive", True):
            continue

        rule_type = constraint.get("ruleType") or constraint.get("rule_type")
        family = constraint.get("family")
        if rule_type == "PREFERRED" and family == "TIME":
            continue
        if rule_type != "HARD" or family != "DAY":
            continue

        team_id = constraint.get("scope_target_id") or constraint.get("scopeTargetId")
        if team_id is None:
            continue

        team_id_text = str(team_id)
        config = constraint.get("config") or {}
        forced_days = config.get("forcedDays") or []
        forbidden_days = config.get("forbiddenDays") or []

        forced_days_by_team.setdefault(team_id_text, set()).update(int(day) for day in forced_days if day is not None)
        forbidden_days_by_team.setdefault(team_id_text, set()).update(
            int(day) for day in forbidden_days if day is not None
        )

    return {
        team_id
        for team_id, forced_days in forced_days_by_team.items()
        if forced_days & forbidden_days_by_team.get(team_id, set())
    }


async def get_club_lock(club_id: str) -> asyncio.Lock:
    async with _club_locks_guard:
        lock = _club_locks.get(club_id)
        if lock is None:
            lock = asyncio.Lock()
            _club_locks[club_id] = lock
        return lock


async def build_schedule(input_data: ScheduleInputSchema) -> ScheduleOutputSchema:
    # Convert Pydantic input to a plain dict for the solver pipeline.
    data: dict[str, Any] = input_data.model_dump(by_alias=True)

    # Single pass with all HARD constraints (including coach rest day + salarie
    # distribution).  If the solver returns INFEASIBLE, build_result produces
    # status="failed" with conflict diagnostics — no silent constraint dropping.
    # Decision: docs/architecture/adr-0001-single-pass-solve.md
    solver_status, solver, model, conflicts = _solve(data, input_data)

    result_dict = build_result(
        data, solver, model, status=solver_status, fallback_used=False
    )
    if conflicts:
        result_dict.setdefault("diagnostics", []).extend(conflicts)

    # Validate and return.
    return ScheduleOutputSchema.model_validate(result_dict)


def _solve(
    data: dict[str, Any],
    input_data: ScheduleInputSchema,
) -> tuple[int, cp_model.CpSolver, ScheduleCpModel, list[dict[str, Any]]]:
    """Run the solver pipeline: build model, add constraints, solve.

    Returns (status, solver, model, conflicts).  All HARD constraints are
    active — no fallback pass that silently drops rest-day or distribution
    constraints.  Uses the full ``solver_timeout_seconds``.
    """
    model: ScheduleCpModel = build_model(data)

    parsed = parse_v2_constraints(data.get("constraints", []))
    team_coach_map: dict[str, list[str]] = parsed.get("team_coach_map", {})
    team_player_map: dict[str, list[str]] = parsed.get("team_player_map", {})

    locked_slots_by_team: dict[str, int] = {}
    for locked_slot in model.locked_slots:
        locked_team_id: str | None = locked_slot.get("team_id")
        if locked_team_id:
            locked_slots_by_team[locked_team_id] = locked_slots_by_team.get(locked_team_id, 0) + 1

    # Identify teams whose sessionsPerWeek is fully covered by HARD locks.
    # These teams must NOT receive the -UNPLACED_PENALTY term in the objective
    # because their solver variables are forced to 0 by remaining_sessions,
    # not because they are genuinely unplaced.
    hard_satisfied_team_ids: set[str] = set()
    for team in data.get("teams", []):
        team_id = team.get("id")
        sessions_per_week = team.get("sessions_per_week") or team.get("sessionsPerWeek")
        if team_id and sessions_per_week:
            if is_team_satisfied_by_hard_locks(str(team_id), model.locked_slots, int(sessions_per_week)):
                hard_satisfied_team_ids.add(str(team_id))

    # Hard min_sessions forces UNKNOWN when venue capacity < total sessions needed.
    # Soft-only via objective bonus (session_count:20) + WARNING diagnostics.
    adjusted_min_by_team: dict[str, int] = {
        str(team.get("id") or ""): 0
        for team in data.get("teams", [])
        if team.get("id")
    }

    available_assignments_by_team: dict[str, list[Any]] = {}
    for slot_key, var in model.x.items():
        team_id = slot_key[0]
        available_assignments_by_team.setdefault(team_id, []).append(var)

    for team in data.get("teams", []):
        team_id = team.get("id")
        max_sessions = team.get("sessions_per_week") or team.get("sessionsPerWeek")
        if team_id and max_sessions and not available_assignments_by_team.get(team_id, []):
            adjusted_min_by_team[str(team_id)] = 0

    for team_id in _day_constraint_conflict_team_ids(parsed["time_windows"]):
        adjusted_min_by_team[team_id] = 0

    # Build assignments from model.x with start/end for consecutive-session constraints.
    # Each (team, venue, day, slot) appears exactly ONCE — no per-coach duplication.
    # Coach and player info is passed separately via team_coach_map / team_player_map.
    assignments: list[dict[str, Any]] = []
    for slot_key, var in model.x.items():
        team_id_str = str(slot_key[0])
        venue_id_str = str(slot_key[1])
        day_of_week = slot_key[2]
        slot_start = slot_key[3]
        slot_id = f"{day_of_week}:{slot_start}"

        vsk = (venue_id_str, day_of_week, slot_start)
        duration = model.slot_durations.get(vsk, DEFAULT_SESSION_MINUTES)
        start_minutes = _time_to_minutes(slot_start)
        end_minutes = start_minutes + duration

        assignments.append({
            "var": var,
            "team_id": team_id_str,
            "venue_id": venue_id_str,
            "slot_id": slot_id,
            "start": start_minutes,
            "end": end_minutes,
        })

    add_level_1_hard_constraints(
        model,
        assignments,
        teams=data.get("teams", []),
        coaches=data.get("coaches", []),
        fixed_assignments=parsed["fixed_slots"],
        forbidden_assignments=parsed["forbidden_assignments"],
        coach_unavailability=parsed["coach_unavailability"],
        venue_closures=parsed["venue_closures"],
        forced_venues=parsed["forced_venues"],
        priority_tiers=parsed.get("priority_tiers", {}),
        min_sessions_by_team=adjusted_min_by_team or None,
        team_coach_map=team_coach_map,
        team_player_map=team_player_map,
    )

    _time_window_added, conflicts = add_time_window_constraints(model, model.x, parsed["time_windows"])

    assignments_by_team: dict[str, list[Any]] = {}
    for slot_key, var in model.x.items():
        team_id = slot_key[0]
        assignments_by_team.setdefault(team_id, []).append(var)

    for team in data.get("teams", []):
        team_id = team.get("id")
        max_sessions = team.get("sessions_per_week") or team.get("sessionsPerWeek")
        if team_id and max_sessions:
            team_vars = assignments_by_team.get(team_id, [])
            if team_vars:
                remaining_sessions = int(max_sessions) - locked_slots_by_team.get(team_id, 0)
                cast(Any, model).Add(sum(team_vars) <= max(0, remaining_sessions))

    # Add objective function.
    preferred_venues: dict[str, str] = parsed.get("preferred_venues", {})
    soft_terms = []
    for slot_key, var in model.x.items():
        team_id = str(slot_key[0])
        venue_id = str(slot_key[1])
        preferred_venue_id = preferred_venues.get(team_id)
        if preferred_venue_id is not None and venue_id == preferred_venue_id:
            soft_terms.append((var, "preferred"))

    soft_terms.extend(add_preferred_day_bonus(model, model.x, parsed["time_windows"], LEVEL_2_OBJECTIVE_WEIGHTS))

    add_level_2_objective(
        model,
        assignments,
        teams=data.get("teams", []),
        soft_terms=soft_terms,
        hard_satisfied_team_ids=hard_satisfied_team_ids,
    )

    # Solve — uses the full configured timeout.
    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = input_data.solver_timeout_seconds
    solver.parameters.random_seed = input_data.solver_seed
    solver.parameters.num_search_workers = 1
    status = solver.Solve(model)

    return status, solver, model, conflicts


@app.get("/health")
async def health() -> dict[str, str]:
    return {"status": "ok"}


@app.post("/generate", response_model=ScheduleOutputSchema)
async def generate_schedule(input_data: ScheduleInputSchema) -> ScheduleOutputSchema:
    lock = await get_club_lock(input_data.club_id)
    async with lock:
        return await build_schedule(input_data)


@app.post("/implicit-constraints")
async def sync_implicit_constraints(input_data: ImplicitConstraintSyncRequest) -> JSONResponse:
    engine_rules = read_implicit_rules()
    backend_rules = sorted(rule.name for rule in input_data.rules if rule.enabled)
    engine_enabled_rules = sorted(rule.name for rule in engine_rules.rules if rule.enabled)
    missing_in_engine = sorted(set(backend_rules) - set(engine_enabled_rules))
    missing_in_backend = sorted(set(engine_enabled_rules) - set(backend_rules))

    if not missing_in_engine and not missing_in_backend:
        return JSONResponse(
            status_code=status.HTTP_200_OK,
            content={"status": "synchronized", "rules_count": len(engine_enabled_rules)},
        )

    return JSONResponse(
        status_code=status.HTTP_409_CONFLICT,
        content={
            "status": "desynchronized",
            "backend_rules": backend_rules,
            "engine_rules": engine_enabled_rules,
            "missing_in_engine": missing_in_engine,
            "missing_in_backend": missing_in_backend,
        },
    )


@app.get("/")
async def root() -> dict[str, str]:
    return {"status": "ok", "contract_version": read_contract_version()}
