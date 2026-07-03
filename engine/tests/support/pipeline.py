"""Shared test harness that drives the REAL production pipeline.

The engine's production entry point is ``app.main.build_schedule`` (async). The
older per-test ``_run_pipeline`` copies re-implemented a *different* pipeline
(no ``parse_v2_constraints``, coach via slotTemplates, single-pass, full
sessions bound) so they could pass while the real path silently ignored
constraints. Every test now routes through ``solve_payload`` so a green test
means the production solver actually honours the input.

``make_payload`` builds a payload in the exact shape the backend emits
(``ScheduleConstraintBuilder::serializeUnifiedConstraints`` — nested ``config``,
plus the v1 ``type``/``metadata`` coach constraints), so semantic tests exercise
the true contract.
"""

from __future__ import annotations

import asyncio
from typing import Any

from app.main import build_schedule
from app.schemas.input_schema import ScheduleInputSchema


def solve_payload(data: dict[str, Any], *, timeout: int | None = None) -> dict[str, Any]:
    """Run a raw payload dict through the production pipeline, return the output dict.

    Mirrors ``POST /generate`` exactly minus the FastAPI/lock layer:
    ScheduleInputSchema → build_schedule → ScheduleOutputSchema → dict(by_alias).
    """
    payload = dict(data)
    if timeout is not None:
        payload["solverTimeoutSeconds"] = timeout
    output = asyncio.run(build_schedule(ScheduleInputSchema.model_validate(payload)))
    return output.model_dump(by_alias=True)


def make_venue(
    venue_id: str,
    slots: list[tuple[int, str]],
    *,
    duration_minutes: int = 90,
    capacity: int = 1,
) -> dict[str, Any]:
    """A venue with training slots. ``slots`` = list of (dayOfWeek, "HH:MM")."""
    return {
        "id": venue_id,
        "name": venue_id,
        "isActive": True,
        "trainingSlots": [
            {
                "dayOfWeek": day,
                "startTime": start,
                "durationMinutes": duration_minutes,
                "capacity": capacity,
            }
            for day, start in slots
        ],
    }


def team_constraint(
    *,
    constraint_id: str,
    team_id: str,
    family: str,
    rule_type: str,
    config: dict[str, Any],
    name: str = "test constraint",
) -> dict[str, Any]:
    """A v2 unified constraint scoped to a team (backend serializeUnifiedConstraints shape)."""
    return {
        "id": constraint_id,
        "scope": "TEAM",
        "scopeTargetId": team_id,
        "family": family,
        "ruleType": rule_type,
        "name": name,
        "config": config,
        "sortOrder": 0,
        "isActive": True,
    }


def make_payload(
    *,
    teams: list[dict[str, Any]],
    venues: list[dict[str, Any]],
    constraints: list[dict[str, Any]] | None = None,
    slot_templates: list[dict[str, Any]] | None = None,
    priority_tiers: list[dict[str, Any]] | None = None,
    seed: int = 42,
    timeout: int = 30,
) -> dict[str, Any]:
    """Assemble a minimal but contract-accurate payload."""
    return {
        "clubId": "test-club",
        "seasonId": "test-season",
        "version": "1.0",
        "solverSeed": seed,
        "solverTimeoutSeconds": timeout,
        "venues": venues,
        "teams": teams,
        "coaches": [],
        "slotTemplates": slot_templates or [],
        "constraints": constraints or [],
        "priorityTiers": priority_tiers
        or [
            {"id": 1, "label": "S", "orToolsWeight": 10000, "defaultMinSessions": 2},
            {"id": 2, "label": "A", "orToolsWeight": 1000, "defaultMinSessions": 2},
            {"id": 3, "label": "B", "orToolsWeight": 100, "defaultMinSessions": 2},
            {"id": 4, "label": "C", "orToolsWeight": 10, "defaultMinSessions": 2},
            {"id": 5, "label": "D", "orToolsWeight": 1, "defaultMinSessions": 1},
        ],
    }


def make_team(
    team_id: str,
    *,
    sessions_per_week: int = 1,
    priority_tier_id: int = 3,
    match_day: int | None = None,
) -> dict[str, Any]:
    team: dict[str, Any] = {
        "id": team_id,
        "sportCategoryId": "cat",
        "priorityTierId": priority_tier_id,
        "name": team_id,
        "sessionsPerWeek": sessions_per_week,
        "isActive": True,
    }
    if match_day is not None:
        team["matchDay"] = match_day
    return team
