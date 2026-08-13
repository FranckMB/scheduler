from __future__ import annotations

import json
import logging
from contextvars import ContextVar

# Correlation id (X-Request-Id) of the request currently being served. A
# ContextVar is COPIED by asyncio.to_thread — the solve runs in a worker thread,
# so a log emitted from inside the solve still sees the id set by the HTTP
# middleware on the event loop. Read at format time, never bound at emit.
request_id_var: ContextVar[str | None] = ContextVar("request_id", default=None)


class JsonLogFormatter(logging.Formatter):
    """One structured JSON object per log line (stdlib only, zero dependency).

    ``request_id`` is read AT FORMAT TIME from the contextvar: a record produced
    in the solve thread carries the id copied into that thread's context, not a
    stale value captured when the logger was configured.
    """

    def format(self, record: logging.LogRecord) -> str:
        payload: dict[str, object] = {
            "timestamp": self.formatTime(record),
            "level": record.levelname,
            "logger": record.name,
            "message": record.getMessage(),
            "request_id": request_id_var.get(),
        }
        if record.exc_info:
            payload["exc_info"] = self.formatException(record.exc_info)
        return json.dumps(payload, ensure_ascii=False)


def configure_logging(level: str) -> None:
    """Install the JSON formatter on the root logger, in every environment
    (uniformity — dev logs read like prod logs).

    ``force=True`` mirrors the previous ``basicConfig``: uvicorn installs its own
    root handlers first, so a plain configuration would be a silent no-op. We
    replace them to make our formatter and level actually take effect.
    """
    handler = logging.StreamHandler()
    handler.setFormatter(JsonLogFormatter())
    logging.basicConfig(level=level.upper(), handlers=[handler], force=True)
