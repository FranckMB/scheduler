# Testing Strategy — ClubScheduler

Scope: backend + engine (frontend excluded — being rebuilt). Companion to [`/CLAUDE.md`](../../CLAUDE.md) §4 and [`../project-map.md`](../project-map.md).

---

## 1. CI pipeline (`.github/workflows/ci.yml`)

Order and dependencies:

```
lint ──┐
       ├─► blocking-tests ──┬─► unit-tests
phpstan┘                    └─► functional-tests
engine-tests ───────────────────────────────────► build-docker
blocking-tests ─────────────────────────────────┘
```

| Job | What it runs |
|-----|--------------|
| `lint` | `docker compose config` + `make -n help` |
| `phpstan` | `composer phpstan` (level 8) — needs postgres + redis |
| `blocking-tests` | the 4 `--group phase1` tests below — **gate for the rest of the PHP suite** |
| `unit-tests` | full PHPUnit `tests/` |
| `functional-tests` | `tests/ --filter Functional` |
| `engine-tests` | `pytest` + `ruff check .` + `mypy` (in the engine container) |
| `build-docker` | `docker compose build` (needs blocking + engine tests) |

All PHP jobs invoke the bridge binary `vendor/bin/.phpunit/phpunit-9.6-0/phpunit` (see the version inconsistency in [`../technical-debt.md`](../technical-debt.md)).

---

## 2. Backend tests (`backend/tests/`)

Layout: `Unit/` (Entity, Enum, Service — no DB) · `Integration/Api/` · `Security/` · `Queue/` · `CrossStack/`.
Groups (PHP attributes): `#[Group('phase1')]`, `#[Group('integration')]`, `#[Group('contract')]`, `#[Group('unit')]`. Test isolation via DAMA DoctrineTestBundle; bootstrap `tests/bootstrap.php`.

### Blocking guardrails (`phase1`)
| Test | Asserts |
|------|---------|
| `Security/TenantIsolationTest` | 403 on another club's data · 200 on own club · 403 when membership inactive · 200 with no `X-Club-Id` |
| `Security/TenantCacheIsolationTest` | **Currently skipped** — "Cache isolation test deferred to Phase 2". It runs in CI but asserts nothing yet (see debt). |
| `Queue/ConcurrentGenerationTest` | 2nd `ClubGenerationLock` acquire for same club fails · different clubs acquire concurrently · wrong token cannot release |
| `CrossStack/ContractSchemaTest` (`phase1`+`contract`) | engine payload shape valid (version, clubId, seasonId, teams, venues, coaches, constraints, trainingSlots, sportCategoryId, scopeTargetId…) · POSTs to the real engine when reachable, else skips |

`ContractSchemaTest` is the **only** guardrail for the manually-synced backend↔engine contract (no codegen). Any change to engine Pydantic schemas or the backend payload must keep it green.

---

## 3. Engine tests (`engine/tests/`)

- **Unit by feature/constraint:** `test_constraints.py`, `test_objective.py`, `test_result_builder.py`, `test_coach_rest_day.py`, `test_salarie_distribution.py`, `test_max_consecutive_sessions.py`, `test_age_order.py`, `test_chaining_bonus.py`, `test_engine.py` (endpoints), …
- **Golden / integration** (`tests/golden/`): full solves on real club fixtures (`simple_club.json`, `dense_club.json`, `bccl_regression.json`, …) with expected outputs; `test_two_pass.py` covers the INFEASIBLE→fallback path.
- **Invariants** (`tests/invariants/test_invariants.py`): post-solve checks — no team/coach overlaps, venue capacity respected, hard locks honored.
- **Fixtures** (`tests/fixtures/`): 12 JSON club configs (simple, medium, dense, overlap_*, no_rest_*, vacation_week, impossible, score_hard_only_teams…).
- Property-based tests via hypothesis; `pytest-timeout` guards runaway solves.

Run: `cd engine && make test` (pytest + ruff + mypy, inside the engine container).

---

## 4. How to run locally

```bash
make start                                   # bring the stack up first (tests need postgres/redis/engine)
cd backend && make test                      # CS-Fixer + PHPStan + PHPUnit (phase1)
cd backend && make phpunit                   # PHPUnit only, already scoped to --group phase1
cd engine  && make test                      # pytest + ruff + mypy
```

Backend & engine tests run **inside Docker** — running `phpunit`/`pytest` on the host will fail. If the stack is down, `ContractSchemaTest` and other integration tests skip or fail rather than silently passing.

---

## 5. Known testing gaps (tracked in `../technical-debt.md`)
- `TenantCacheIsolationTest` is a blocking job but currently a no-op (skipped) — false sense of coverage on cache isolation.
- PHPUnit binary version pinned in CI (`9.6-0`) diverges from `composer.json` (`^11`) / `phpunit.xml.dist` (`11.5-0`).
