from __future__ import annotations

import asyncio
import logging
from pathlib import Path
from typing import Any, cast

from fastapi import FastAPI, HTTPException, Request, status
from fastapi.responses import JSONResponse
from ortools.sat.python import cp_model
from pydantic import BaseModel, ConfigDict, Field, ValidationError

from app.core.config import get_settings
from app.schemas.input_schema import ScheduleInputSchema
from app.schemas.output_schema import ScheduleOutputSchema
from app.solver.constraints import (
    add_level_1_hard_constraints,
    add_time_window_constraints,
    add_venue_minimum_constraints,
    parse_v2_constraints,
)
from app.solver.model import DEFAULT_SESSION_MINUTES, ScheduleCpModel, _time_to_minutes, build_model
from app.solver.objective import (
    LEVEL_2_OBJECTIVE_WEIGHTS,
    add_level_2_objective,
    add_match_day_rest_bonus,
    add_preferred_day_bonus,
    add_preferred_time_bonus,
    add_spacing_penalty,
    is_team_satisfied_by_hard_locks,
)
from app.solver.result_builder import build_result

ENGINE_ROOT = Path(__file__).resolve().parents[1]
CONTRACT_VERSION_PATH = ENGINE_ROOT / "CONTRACT_VERSION"
IMPLICIT_RULES_PATH = ENGINE_ROOT / "implicit_rules.json"

settings = get_settings()
# force=True: uvicorn installs root handlers first, which would make a plain
# basicConfig a silent no-op — force our level/format to actually take effect.
logging.basicConfig(
    level=settings.log_level.upper(),
    format="%(asctime)s %(levelname)s %(name)s %(message)s",
    force=True,
)
logger = logging.getLogger("engine")

app = FastAPI(title=settings.app_name, version=settings.app_version)


@app.exception_handler(Exception)
async def _unhandled_exception_handler(request: Request, exc: Exception) -> JSONResponse:
    """ENG-06: last-resort handler so an unexpected solver/runtime error is
    logged with its traceback server-side and returns a clean JSON 500 instead
    of leaking internals. HTTPException and request-validation errors keep their
    own dedicated handlers (this only catches the truly unhandled)."""
    # Log the exception we were handed (exc_info=exc), not the ambient
    # sys.exc_info() — robust whether called in an except context or directly.
    logger.error("Unhandled error on %s %s", request.method, request.url.path, exc_info=exc)

    return JSONResponse(
        status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
        content={"status": "error", "detail": "Internal solver error."},
    )


_club_locks: dict[str, asyncio.Lock] = {}
_club_locks_guard = asyncio.Lock()
_MAX_CLUB_LOCKS = 256
_solve_semaphore = asyncio.Semaphore(settings.max_concurrent_solves)


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
        # LOCK is enforced as hard as HARD downstream. Aligning this set is defensive
        # only (ENG-20): its consumer just writes 0 into a min-floor that is already
        # all-zeros today (min is soft-only, ENG-18), and the ACTUAL LOCK-DAY conflict
        # enforcement already lives in add_time_window_constraints. Kept for the day a
        # hard min floor is re-activated, not for any effect today.
        if rule_type not in ("HARD", "LOCK") or family != "DAY":
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


def _lock_is_idle(lock: asyncio.Lock) -> bool:
    # Idle = neither held NOR awaited. Checking locked() alone is not enough:
    # during release, asyncio sets _locked=False before the woken waiter runs,
    # so a lock with a pending waiter can momentarily report not-locked. Deleting
    # it then would orphan the waiter and let a fresh request create a second
    # lock for the same club, breaking per-club serialisation (audit review).
    waiters = getattr(lock, "_waiters", None)
    return not lock.locked() and not waiters


async def get_club_lock(club_id: str) -> asyncio.Lock:
    async with _club_locks_guard:
        # Bound the dict: drop only genuinely idle locks (not held, no waiter).
        if len(_club_locks) > _MAX_CLUB_LOCKS:
            for cid in [c for c, lk in _club_locks.items() if c != club_id and _lock_is_idle(lk)]:
                del _club_locks[cid]
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
    #
    # _solve is CPU-bound (up to 650 s of CP-SAT). Run it in a worker thread so
    # the event loop stays responsive (/health answers during a solve). Solve()
    # releases the GIL and each request builds its own model/solver, so this is
    # thread-safe. A global semaphore bounds how many solves run at once.
    async with _solve_semaphore:
        logger.info("solve start club=%s teams=%d", input_data.club_id, len(input_data.teams))
        solver_status, solver, model, conflicts = await asyncio.to_thread(_solve, data, input_data)

    result_dict = build_result(
        data,
        solver,
        model,
        status=solver_status,
        fallback_used=False,
        constraint_version=read_contract_version(),
    )
    if conflicts:
        result_dict.setdefault("diagnostics", []).extend(conflicts)

    logger.info(
        "solve done club=%s status=%s slots=%d",
        input_data.club_id,
        result_dict.get("status"),
        len(result_dict.get("slots", [])),
    )
    # Validate and return.
    return ScheduleOutputSchema.model_validate(result_dict)


# Hard cap (seconds) on the phase-2 chaining optimisation. Placement is already
# optimal and locked by then, so this only bounds how long we polish the small
# back-to-back bonus — best-effort, never at the expense of placement or budget.
CHAINING_PHASE_MAX_SECONDS = 10


def _adaptive_timeout(n_teams: int, n_venues: int, payload_cap: int) -> int:
    """Scale the solve budget to problem size, capped by the payload budget.

    complexity = n_teams * n_venues → small problems return fast instead of
    burning the full 650 s ceiling. Tiers: ≤50 → 60 s · ≤200 → 180 s · else
    600 s. ``payload_cap`` (``solver_timeout_seconds``) is the hard ceiling:
    the manager can never be made to wait longer than they asked for.
    """
    complexity = n_teams * n_venues
    if complexity <= 50:
        adaptive = 60
    elif complexity <= 200:
        adaptive = 180
    else:
        adaptive = 600
    return min(adaptive, payload_cap)


# The single default worker's objective bound stays hopelessly loose on dense,
# soft-preference-rich problems (e.g. 49 teams with 55 soft venue preferences):
# it FINDS the optimal placement in ~2 s but then fails to PROVE it, burning the
# whole adaptive budget (measured: 612 s, gap never closes). CP-SAT's 8-worker
# portfolio includes the bound-tightening worker that closes the proof in ~2 s
# with an identical objective. Small problems keep 1 worker so their solve stays
# bit-for-bit reproducible (the golden fixtures depend on it); only the top
# complexity tier — where the stall lives and speed matters — pays the
# multi-worker cost (the optimal *value* is stable run-to-run; the exact
# equally-optimal assignment may differ, which is why the large golden fixtures
# assert score + slot count, not exact placement).
LARGE_PROBLEM_WORKERS = 8


def _adaptive_workers(n_teams: int, n_venues: int) -> int:
    """Number of CP-SAT search workers, scaled to problem size (see above).

    Mirrors the ``_adaptive_timeout`` tiers: ≤200 complexity → 1 (deterministic,
    already fast), else → 8 (fast optimality proof on the stall-prone tier).
    """
    return 1 if n_teams * n_venues <= 200 else LARGE_PROBLEM_WORKERS


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

    # FACILITY_CAPACITY: tighten per-slot capacity to maxTeams. min() only — the
    # backend already sets capacity to 1 for non-divisible venues, so a maxTeams
    # above the slot capacity must not re-open it. add_room_at_most_one and the
    # over-capacity diagnostic both read model.slot_capacities → stays coherent.
    venue_capacity_caps: dict[str, int] = parsed.get("venue_capacity_caps", {})
    if venue_capacity_caps:
        for vsk in model.slot_capacities:
            cap = venue_capacity_caps.get(str(vsk[0]))
            if cap is not None:
                model.slot_capacities[vsk] = min(model.slot_capacities[vsk], cap)

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
        if (
            team_id
            and sessions_per_week
            and is_team_satisfied_by_hard_locks(str(team_id), model.locked_slots, int(sessions_per_week))
        ):
            hard_satisfied_team_ids.add(str(team_id))

    # Hard min_sessions forces UNKNOWN when venue capacity < total sessions needed.
    # Soft-only via objective bonus (session_count:20) + WARNING diagnostics.
    adjusted_min_by_team: dict[str, int] = {
        str(team.get("id") or ""): 0 for team in data.get("teams", []) if team.get("id")
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
    # Player info is passed separately via team_player_map. The team's MAIN coach
    # (first entry of team_coach_map after the role filter) is attached so the
    # chaining bonus can reward back-to-back sessions of the same coach.
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

        team_coaches = team_coach_map.get(team_id_str) or []
        main_coach_id = team_coaches[0] if team_coaches else None

        assignments.append(
            {
                "var": var,
                "team_id": team_id_str,
                "venue_id": venue_id_str,
                "slot_id": slot_id,
                "start": start_minutes,
                "end": end_minutes,
                "coach_id": main_coach_id,
            }
        )

    add_level_1_hard_constraints(
        model,
        assignments,
        teams=data.get("teams", []),
        coaches=data.get("coaches", []),
        fixed_assignments=parsed["fixed_slots"],
        forbidden_assignments=parsed["forbidden_assignments"],
        coach_unavailability=parsed["coach_unavailability"],
        forced_venues=parsed["forced_venues"],
        priority_tiers=parsed.get("priority_tiers", {}),
        min_sessions_by_team=adjusted_min_by_team or None,
        team_coach_map=team_coach_map,
        team_player_map=team_player_map,
    )

    _time_window_added, conflicts = add_time_window_constraints(model, model.x, parsed["time_windows"])
    _vm_added, vm_conflicts = add_venue_minimum_constraints(model, model.x, parsed.get("venue_minimums", []))
    # Parse-time "constraint not honored" warnings (target-less scope, coach
    # ruleType coerced…) ride the same diagnostics channel as hard conflicts.
    conflicts = [*conflicts, *vm_conflicts, *parsed.get("parse_warnings", [])]

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
    # Soft "avoid this venue" rules (ENG-11): a TRUE MALUS on the avoided slot
    # ("avoided_venue" < 0) — a complement bonus on every other venue would give
    # the team a flat per-session advantage and bias cross-team allocation.
    # Still soft: feasibility is never affected.
    avoided_by_team: dict[str, set[str]] = {}
    for avoided in parsed.get("avoided_venues", []):
        avoided_by_team.setdefault(avoided["scope_target_id"], set()).add(avoided["venue_id"])
    soft_terms = []
    for slot_key, var in model.x.items():
        team_id = str(slot_key[0])
        venue_id = str(slot_key[1])
        preferred_venue_id = preferred_venues.get(team_id)
        if preferred_venue_id is not None and venue_id == preferred_venue_id:
            soft_terms.append((var, "preferred"))
        avoided_set = avoided_by_team.get(team_id)
        if avoided_set is not None and venue_id in avoided_set:
            soft_terms.append((var, "avoided_venue"))

    soft_terms.extend(add_preferred_day_bonus(model, model.x, parsed["time_windows"], LEVEL_2_OBJECTIVE_WEIGHTS))
    soft_terms.extend(add_preferred_time_bonus(model, model.x, parsed["time_windows"], LEVEL_2_OBJECTIVE_WEIGHTS))
    soft_terms.extend(add_match_day_rest_bonus(model, model.x, data.get("teams", []), LEVEL_2_OBJECTIVE_WEIGHTS))
    soft_terms.extend(add_spacing_penalty(model, model.x, data.get("teams", []), LEVEL_2_OBJECTIVE_WEIGHTS))

    # Phase 1 installs the PLACEMENT objective only; the chaining terms are built
    # into the model but kept out of the objective (apply_chaining=False) so their
    # tiny coefficients never wreck the placement optimality proof.
    objective_stats = add_level_2_objective(
        model,
        assignments,
        teams=data.get("teams", []),
        soft_terms=soft_terms,
        hard_satisfied_team_ids=hard_satisfied_team_ids,
        apply_chaining=False,
    )

    # Adaptive timeout capped by the payload budget.
    n_teams = len(data.get("teams") or [])
    n_venues = len(data.get("venues") or [])
    timeout_seconds = _adaptive_timeout(n_teams, n_venues, input_data.solver_timeout_seconds)
    workers = _adaptive_workers(n_teams, n_venues)

    # --- Phase 1: solve for the optimal placement (fast, chaining excluded). ---
    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = timeout_seconds
    solver.parameters.random_seed = input_data.solver_seed
    solver.parameters.num_search_workers = workers
    status = solver.Solve(model)

    # --- Phase 2: lock the placement quality, then optimise the chaining bonus
    # under a hard time cap. Proving chaining-optimality can be slow, so we bound
    # it and keep the best-effort result — placement stays optimal either way. ---
    if status in (cp_model.OPTIMAL, cp_model.FEASIBLE) and objective_stats.chaining_terms:
        placement_optimum = int(solver.ObjectiveValue())
        cast(Any, model).Add(objective_stats.placement_expression >= placement_optimum)
        # Warm-start phase 2 with the placement-optimal solution so it always has
        # at least that (chaining ≥ 0) to return, even if the cap fires early.
        for phase1_var in model.x.values():
            cast(Any, model).AddHint(phase1_var, solver.Value(phase1_var))
        cast(Any, model).Maximize(
            objective_stats.placement_expression + sum(weight * var for var, weight in objective_stats.chaining_terms)
        )
        phase2_solver = cp_model.CpSolver()
        phase2_solver.parameters.max_time_in_seconds = min(timeout_seconds, CHAINING_PHASE_MAX_SECONDS)
        phase2_solver.parameters.random_seed = input_data.solver_seed
        phase2_solver.parameters.num_search_workers = workers
        phase2_status = phase2_solver.Solve(model)
        if phase2_status in (cp_model.OPTIMAL, cp_model.FEASIBLE):
            solver, status = phase2_solver, phase2_status

    return status, solver, model, conflicts


@app.get("/health")
async def health() -> dict[str, str]:
    return {"status": "ok"}


@app.post("/generate", response_model=ScheduleOutputSchema)
async def generate_schedule(input_data: ScheduleInputSchema) -> ScheduleOutputSchema:
    # ENG-14: reject a payload whose contract MAJOR the engine does not speak,
    # instead of silently solving against a schema it may misread. The contract
    # is manually synced (no codegen), so a major bump on one side must fail
    # loud rather than produce a subtly wrong plan.
    contract_version = read_contract_version()
    if input_data.version.split(".")[0] != contract_version.split(".")[0]:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_CONTENT,
            detail=f"Unsupported contract version {input_data.version!r}; engine speaks {contract_version}.",
        )

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
