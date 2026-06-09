from __future__ import annotations

import asyncio
from pathlib import Path
from typing import Any

from fastapi import FastAPI
from ortools.sat.python import cp_model

from app.core.config import get_settings
from app.schemas.input_schema import ScheduleInputSchema
from app.schemas.output_schema import ScheduleOutputSchema
from app.solver.constraints import add_level_1_hard_constraints
from app.solver.model import ScheduleCpModel, build_model
from app.solver.objective import add_level_2_objective
from app.solver.result_builder import build_result

ENGINE_ROOT = Path(__file__).resolve().parents[1]
CONTRACT_VERSION_PATH = ENGINE_ROOT / "CONTRACT_VERSION"

settings = get_settings()
app = FastAPI(title=settings.app_name, version=settings.app_version)

_club_locks: dict[str, asyncio.Lock] = {}
_club_locks_guard = asyncio.Lock()


def read_contract_version() -> str:
    try:
        return CONTRACT_VERSION_PATH.read_text(encoding="utf-8").strip()
    except FileNotFoundError:
        return settings.contract_version


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

    # Build the CP-SAT model.
    model: ScheduleCpModel = build_model(data)

    # Add hard constraints.
    add_level_1_hard_constraints(
        model,
        model.x,
        teams=data.get("teams", []),
    )

    # Add objective function.
    add_level_2_objective(
        model,
        model.x,
        teams=data.get("teams", []),
    )

    # Solve.
    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = 10
    status = solver.Solve(model)

    # Transform the solution into the output schema.
    result_dict = build_result(data, solver, model, status=status)

    # Validate and return.
    return ScheduleOutputSchema.model_validate(result_dict)


@app.get("/health")
async def health() -> dict[str, str]:
    return {"status": "ok"}


@app.post("/generate", response_model=ScheduleOutputSchema)
async def generate_schedule(input_data: ScheduleInputSchema) -> ScheduleOutputSchema:
    lock = await get_club_lock(input_data.club_id)
    async with lock:
        return await build_schedule(input_data)


@app.get("/")
async def root() -> dict[str, str]:
    return {"status": "ok", "contract_version": read_contract_version()}
