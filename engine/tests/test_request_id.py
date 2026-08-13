"""P5-11 — correlation id (X-Request-Id) across the engine.

The middleware is exercised by DIRECT call (patron test_runtime.py, no httpx):
it echoes a valid id, generates one when absent, and NEVER echoes a malformed
value. The contextvar survives asyncio.to_thread (the solve runs in a thread),
and the JSON formatter emits a parsable line carrying the id.
"""

from __future__ import annotations

import asyncio
import json
import logging
import types
from typing import Any

import app.main as main
from app.core.logging import JsonLogFormatter, request_id_var

_VALID = "11111111-2222-4333-8444-555555555555"


def _run_middleware(headers: dict[str, str]) -> tuple[str, str | None]:
    """Call the middleware directly; return (echoed header, id seen during handling)."""
    seen: dict[str, str | None] = {"during": None}

    async def call_next(_request: Any) -> Any:
        seen["during"] = request_id_var.get()
        return types.SimpleNamespace(headers={})

    request = types.SimpleNamespace(headers=headers)
    response = asyncio.run(main._request_id_middleware(request, call_next))
    return response.headers["X-Request-Id"], seen["during"]


def test_valid_incoming_id_is_echoed() -> None:
    echoed, during = _run_middleware({"X-Request-Id": _VALID})
    assert echoed == _VALID
    assert during == _VALID  # visible to the downstream handler


def test_missing_id_is_generated() -> None:
    echoed, during = _run_middleware({})
    assert main._REQUEST_ID_RE.match(echoed)
    assert echoed == during


def test_malformed_id_is_regenerated_never_echoed() -> None:
    malformed = "garbage\r\nX-Injected: evil"
    echoed, _during = _run_middleware({"X-Request-Id": malformed})
    assert echoed != malformed
    assert main._REQUEST_ID_RE.match(echoed)


def test_request_id_visible_from_worker_thread() -> None:
    """asyncio.to_thread copies the context: a solve-thread log sees the id."""

    async def scenario() -> str | None:
        request_id_var.set(_VALID)
        return await asyncio.to_thread(request_id_var.get)

    assert asyncio.run(scenario()) == _VALID


def test_formatter_emits_parsable_json_with_request_id() -> None:
    token = request_id_var.set(_VALID)
    try:
        record = logging.LogRecord("engine", logging.INFO, __file__, 1, "hello", None, None)
        line = JsonLogFormatter().format(record)
    finally:
        request_id_var.reset(token)

    payload = json.loads(line)
    assert payload["message"] == "hello"
    assert payload["level"] == "INFO"
    assert payload["logger"] == "engine"
    assert payload["request_id"] == _VALID
