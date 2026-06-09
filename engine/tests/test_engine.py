from __future__ import annotations

import asyncio

from app.main import build_schedule, health, read_contract_version, root
from app.schemas.input_schema import ScheduleInputSchema


def test_health_returns_ok() -> None:
    assert asyncio.run(health()) == {"status": "ok"}


def test_root_uses_contract_version_file() -> None:
    payload = asyncio.run(root())
    assert payload["status"] == "ok"
    assert payload["contract_version"] == read_contract_version()


def test_build_schedule_returns_completion_payload() -> None:
    input_data = ScheduleInputSchema.model_validate(
        {
            "clubId": "club-1",
            "seasonId": "season-1",
            "slotTemplates": [],
        }
    )

    result = asyncio.run(build_schedule(input_data))

    assert result.status == "completed"
    assert result.score == 0
    assert result.slots == []
    assert result.diagnostics == []
