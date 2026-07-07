# ClubScheduler — Engine Agent Context

> Python 3.12 + FastAPI + OR-Tools CP-SAT. Reactive solver microservice.
> **Pointer file** — commands, CI, boundaries, solver principles: see root [`CLAUDE.md`](../CLAUDE.md), [`docs/project-map.md`](../docs/project-map.md) §3 and [`docs/architecture/adr-0001-single-pass-solve.md`](../docs/architecture/adr-0001-single-pass-solve.md). Do not duplicate them here.

## Where things live (no counts — they rot)

- `app/main.py` FastAPI endpoints + solve orchestration · `app/schemas/` Pydantic v2 input/output · `app/solver/` model / constraints / objective / result_builder · `tests/` golden + invariants + hypothesis, fixtures under `tests/fixtures/`.
- Nested solver detail: [`app/solver/AGENTS.md`](app/solver/AGENTS.md).
- Contract source of truth: `CONTRACT_VERSION` file at engine root (returned in `/` and metrics).

## Endpoints (verify in `app/main.py` before relying on this)

`GET /` (health + contract) · `GET /health` · `POST /generate` (main) · `POST /implicit-constraints` (validation warnings for the wizard).

## Zone gotchas (facts not in the root docs)

1. **All commands run in the engine container** — `engine/Makefile` wraps `docker compose exec`. Host `pytest`/`ruff` fail without a local venv.
2. **Output `status` literals** are `"queued" | "generating" | "completed" | "failed"` (`app/schemas/output_schema.py` — `Literal`, source of truth).
3. **Score formula** — `SCORE_FORMULA_VERSION = "T24_LEVEL_2_FIXED_WEIGHTS_V6"` (`app/solver/objective.py`). Changing any level-2 weight requires bumping it. Weights table lives in the root spec / `objective.py`, not here.
4. **Two-phase solve** — phase 1 optimal placement (locked), phase 2 bounded 10 s chaining bonus with warm-start. Both phases get the payload seed. See `app/main.py`.
5. **Timeout is payload-driven** — `solver_timeout_seconds` (default 650 s) bounds the adaptive timeout computed in `main.py`.
6. **Per-club `asyncio.Lock`** (`_club_locks` in `main.py`) serialises requests per club. ⚠ Known limit: the solve itself currently runs on the event loop (audit ENG-03), so one solve blocks all clubs until fixed.
7. **Uvicorn runs without reload** — after editing engine code, restart the container before any e2e test (stale code otherwise).
8. **Hypothesis** — `.hypothesis/` directory may grow large; safe to delete.
