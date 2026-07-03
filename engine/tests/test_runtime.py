"""Runtime tests (ENG-03/06): the solve must not block the event loop, the club
lock dict must stay bounded, and an unrecognised constraint must be logged."""

from __future__ import annotations

import asyncio
import logging
import threading
import time
from typing import Any

import app.main as main
from app.schemas.input_schema import ScheduleInputSchema
from app.solver.constraints import parse_v2_constraints

_MINIMAL_OUTPUT = {
    "status": "completed",
    "score": 0,
    "metrics": {"solver_version": "test", "nb_variables": 0, "nb_constraints": 0, "wall_time_ms": 0},
    "slots": [],
    "diagnostics": [],
}


def _minimal_input() -> ScheduleInputSchema:
    return ScheduleInputSchema.model_validate(
        {
            "clubId": "c", "seasonId": "s", "version": "1.0",
            "venues": [], "teams": [], "coaches": [], "slotTemplates": [],
            "constraints": [], "priorityTiers": [],
        }
    )


def test_solve_runs_off_the_event_loop(monkeypatch: Any) -> None:
    # ENG-03: _solve is CPU-bound and must run in a worker thread so /health
    # keeps answering. Prove (a) _solve executes off the main thread, and
    # (b) health() completes while a slow _solve is still running.
    solve_thread: dict[str, str] = {}

    def fake_solve(data: dict[str, Any], input_data: Any) -> tuple[Any, ...]:
        solve_thread["name"] = threading.current_thread().name
        time.sleep(0.3)
        return (0, None, None, [])

    monkeypatch.setattr(main, "_solve", fake_solve)
    monkeypatch.setattr(main, "build_result", lambda *a, **k: dict(_MINIMAL_OUTPUT))

    async def scenario() -> str:
        solve_task = asyncio.create_task(main.build_schedule(_minimal_input()))
        await asyncio.sleep(0.05)  # let the solve start on its worker thread
        # The event loop is free: health answers while the solve is mid-flight.
        health = await main.health()
        assert not solve_task.done(), "solve should still be running (event loop free)"
        await solve_task
        return health["status"]

    assert asyncio.run(scenario()) == "ok"
    assert solve_thread["name"] != threading.main_thread().name, "_solve must run off the main thread"


def test_club_locks_bounded() -> None:
    async def hammer() -> int:
        for i in range(main._MAX_CLUB_LOCKS + 50):
            await main.get_club_lock(f"club-{i}")
        return len(main._club_locks)

    size = asyncio.run(hammer())
    # Idle (unheld) locks are purged past the cap, so the dict cannot grow
    # unbounded across many one-shot clubs.
    assert size <= main._MAX_CLUB_LOCKS + 1, f"club lock dict unbounded: {size}"


def test_unrecognised_constraint_is_logged(caplog: Any) -> None:
    with caplog.at_level(logging.WARNING, logger="engine.constraints"):
        parse_v2_constraints([{"id": "x", "isActive": True, "family": "TOTALLY_UNKNOWN"}])
    assert any("unrecognised constraint" in r.message for r in caplog.records)
