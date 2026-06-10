# ClubScheduler — Engine Agent Context

> Python 3.12 + FastAPI + OR-Tools CP-SAT. Optimization solver for sports club scheduling.

---

## Architecture

```
engine/
├── app/
│   ├── main.py              # FastAPI entry point + endpoints
│   ├── core/
│   │   └── config.py        # Pydantic settings
│   ├── schemas/
│   │   ├── input_schema.py  # ScheduleInputSchema (Pydantic v2)
│   │   └── output_schema.py # ScheduleOutputSchema (Pydantic v2)
│   └── solver/
│       ├── model.py         # CP-SAT model builder (ScheduleCpModel)
│       ├── constraints.py   # 11 hard constraints (Level 1)
│       ├── objective.py     # Level-2 weighted objective
│       └── result_builder.py # Solution → ScheduleOutputSchema
├── tests/
│   ├── fixtures/            # Golden fixtures (5 scenarios)
│   ├── golden/              # Golden path tests
│   ├── invariants/          # Invariant tests
│   └── test_result_builder.py
├── CONTRACT_VERSION         # Contract version string
├── pyproject.toml           # ruff, mypy, pytest config
└── Makefile
```

---

## Key Conventions

- **Pydantic v2** for all request/response schemas.
- **FastAPI** with auto-generated OpenAPI docs.
- **Solver pipeline** is pure functions: `build_model` → `add_level_1_hard_constraints` → `add_level_2_objective` → `cp_model.CpSolver().Solve()` → `build_result`.
- **Solver timeout** is hardcoded to `10s` in `main.py` (`solver.parameters.max_time_in_seconds = 10`).
- **Per-club asyncio locks** prevent concurrent generation for the same club (`_club_locks` dict in `main.py`).
- **Contract version** is read from `CONTRACT_VERSION` file (root of engine package).

---

## Toolchain

- **ruff** — line length 120, target py312, double quotes, space indent, LF.
- **mypy** — strict mode + `pydantic.mypy` plugin. `ortools` imports are ignored.
- **pytest** — with `pytest-timeout` and `hypothesis`. Golden fixtures in `tests/fixtures/`.
- **setuptools** — package is installed as `clubscheduler-engine` in editable mode.

---

## Commands

All commands run **inside the engine container** via `engine/Makefile`:

```bash
cd engine
make install              # pip install -e ".[dev]"
make test                 # pytest
make lint                 # ruff check . && mypy
make format               # ruff format .
make exec                 # shell in engine container
```

Inside the container:
```bash
pytest tests/                    # Run all tests
pytest tests/golden/             # Golden path tests
pytest tests/invariants/          # Invariant tests
pytest tests/test_result_builder.py
ruff check .                     # Lint
ruff format .                    # Format
mypy                             # Type check
```

---

## Solver Pipeline

### Input: `ScheduleInputSchema`
- `version`, `clubId`, `seasonId`, `scheduleName`, `solverSeed`
- `venues`, `teams`, `coaches`, `constraints`, `slotTemplates`

### Model (`model.py`)
- Creates boolean variables `x[team, venue, day, slot]`.
- Returns `ScheduleCpModel` object with variables and helper methods.

### Hard Constraints (`constraints.py`) — Level 1
1. **Room at-most-one** — one venue hosts max one team per time slot.
2. **Coach at-most-one** — one coach coaches max one team per time slot.
3. **Coach-player non-overlap** — a coach-player cannot be in two roles simultaneously.
4. **Travel feasibility** — MVP stub (no data yet, always satisfied).
5. **Fixed slots** — pre-placed slots are forced to 1.
6. **Forbidden assignments** — forbidden variables are forced to 0.
7. **Coach unavailability** — unavailable coach slots forced to 0.
8. **Venue closures** — closed venue slots forced to 0.
9. **Required bridge** — MVP stub (no data yet, always satisfied).
10. **Min sessions** — each team gets at least its effective minimum sessions.
11. **Forced venues** — if a venue is forced, all other venues are excluded.

### Objective (`objective.py`) — Level 2
Maximize weighted score. Fixed T24 weights (changing them requires new `SCORE_FORMULA_VERSION`):

| Criterion | Weight |
|-----------|--------|
| Tier S | 10,000 |
| Tier A | 1,000 |
| SOFT | 800 |
| Tier B | 100 |
| Preferred link | 80 |
| Preferred slot | 60 |
| Grouping | 50 |
| Tier C | 10 |
| Max days | 8 |
| Optional link | 5 |
| Tier D | 1 |
| Rest | 3 |

### Output: `ScheduleOutputSchema`
- `status` (`completed`/`failed`/`infeasible`)
- `score`, `slots[]`, `diagnostics[]`, `metrics`

---

## API Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/` | GET | Health + contract version |
| `/health` | GET | Simple health check |
| `/generate` | POST | **Main** — solve schedule |

---

## Gotchas

1. **All commands run in container** — `engine/Makefile` wraps everything with `docker compose exec`. Running `pytest` or `ruff` on host will fail unless Python venv is set up locally.
2. **Solver timeout is 10s** — hardcoded in `main.py`. Changing it requires editing the source.
3. **Per-club locks** — `asyncio.Lock` per `club_id` prevents concurrent requests for the same club. Lock is acquired in `generate_schedule` endpoint.
4. **Contract version** — read from `CONTRACT_VERSION` file at engine root. If missing, falls back to settings default.
5. **MVP stubs** — `travel_feasibility` and `required_bridge` constraints are stubs (return 0 constraints). They will be implemented when data exists.
6. **Score formula is fixed** — `SCORE_FORMULA_VERSION = "T24_LEVEL_2_FIXED_WEIGHTS_V1"`. Changing weights requires bumping the version.
7. **Constraint aliases** — `constraints.py` has 5 compatibility aliases for the same function (`add_hard_constraints`, `add_mvp_hard_constraints`, etc.) to support different naming conventions from calling code.
8. **No direct backend calls** — engine is purely reactive. Backend calls it via HTTP POST. Engine never calls backend.
9. **Hypothesis tests** — `tests/` includes hypothesis-generated tests. `.hypothesis/` directory may grow large.

---

## Quick Reference

| Task | Command |
|------|---------|
| Install deps | `cd engine && make install` |
| Run tests | `cd engine && make test` |
| Run lint | `cd engine && make lint` |
| Format code | `cd engine && make format` |
| Enter container | `cd engine && make exec` |
