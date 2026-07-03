# Testing Strategy — ClubScheduler

Scope: backend + engine. The rebuilt frontend has its own tests (Vitest + RTL unit/integration with `vi.mock`, Playwright e2e in `frontend/tests/e2e`, and the container screenshot pipelines). Companion to [`/CLAUDE.md`](../../CLAUDE.md) §4 and [`../project-map.md`](../project-map.md).

---

## 1. CI pipeline (`.github/workflows/ci.yml`)

Order and dependencies:

```
lint ──┐
       ├─► blocking-tests ──► unit-tests
phpstan┘
engine-tests ───────────────────────────────────► build-docker
blocking-tests ─────────────────────────────────┘
```

All PHP test jobs first **create + migrate the test DB** (`doctrine:database:create --if-not-exists` + `migrations:migrate`, `--env=test`) and run phpunit with `-e APP_ENV=test` on the `docker compose exec` — the containers default to `APP_ENV=dev` (root `.env` env_file) and `phpunit.xml.dist`'s `<server APP_ENV=test>` is not `force`d, so the real env var must be set explicitly.

| Job | What it runs |
|-----|--------------|
| `lint` | `docker compose config` + `make -n help` |
| `phpstan` | `composer phpstan` (level 8) — needs postgres + redis |
| `blocking-tests` | the `--group phase1` security/queue/contract tests below — **gate for the rest of the PHP suite**. Beyond the original 4: `RlsIsolationTest` (DB-level RLS), `ClubAccessTest`/`UserSelfOnlyTest`/`ImportAuthorizationTest` (SEC-01/02/04), `MercureHardeningTest` (SEC-05/06) |
| `unit-tests` | full PHPUnit `tests/` |
| `engine-tests` | `pytest` + `ruff check .` + `mypy` (in the engine container) |
| `build-docker` | `docker compose build` (needs blocking + engine tests) |

All PHP jobs invoke `vendor/bin/phpunit` (PHPUnit 11, the direct `phpunit/phpunit` dep) — same binary as `Makefile` and `composer test`.

---

## 2. Backend tests (`backend/tests/`)

Layout: `Unit/` (Entity, Enum, Service — no DB) · `Integration/Api/` · `Security/` · `Queue/` · `CrossStack/`.
Groups (PHP attributes): `#[Group('phase1')]`, `#[Group('integration')]`, `#[Group('contract')]`, `#[Group('unit')]`. Test isolation via DAMA DoctrineTestBundle; bootstrap `tests/bootstrap.php`.

### Blocking guardrails (`phase1`)
| Test | Asserts |
|------|---------|
| `Security/TenantIsolationTest` | 403 on another club's data · 200 on own club · 403 when membership inactive · 200 with no `X-Club-Id` |
| `Security/TenantCacheIsolationTest` | Implemented (B3, resolved 2026-07-01) — 2 real tests: cache invalidation isolates clubs; entity without `club_id` purges nothing. |
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

## 5. Known testing gaps
- None outstanding. `TenantCacheIsolationTest` is implemented (B3) and the 9 PHPUnit 11 doc-comment deprecations were migrated to attributes (B6) — both resolved 2026-07-01 (see `../technical-debt.md`).
