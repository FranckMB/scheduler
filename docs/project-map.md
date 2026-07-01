# Project Map — ClubScheduler (engine + backend)

Detailed companion to the short index in [`/CLAUDE.md`](../CLAUDE.md). Frontend is excluded (slated for deletion + React rebuild). Generated/verified during onboarding against the real code and the `code-review-graph` knowledge graph (1906 nodes / 10 629 edges, 28 communities).

---

## 1. Repository layout

```
backend/   PHP 8.4 · Symfony 7.4 · API Platform 4.3 · Doctrine ORM 3.6 · Messenger · Mercure · JWT
engine/    Python 3.12 · FastAPI · OR-Tools CP-SAT
frontend/  TS · React 18 · Vite        (being rebuilt — excluded from graph + debt audit)
specs/     Living specs (initiales / courantes / evolution) — see specs/README.md
docs/      This documentation set + docs/technique/
docker/    Per-service Dockerfiles (php, pdf-worker, …)
.github/workflows/ci.yml   CI pipeline
contracts/ EMPTY placeholder (no codegen yet)
tests/     EMPTY placeholder (cross-stack tests currently live in backend/tests/)
```

All services share the Docker network `clubscheduler_network`.

---

## 2. Backend (`backend/`)

**Entry point:** `public/index.php` → `src/Kernel.php` (`MicroKernelTrait`). Active bundles: API Platform, Doctrine ORM, Messenger, Mercure, LexikJWT, Security, Twig, CORS (Nelmio), DoctrineFixtures, DAMADoctrineTest.

### 2.1 Source layout (`backend/src/`)
| Dir | Content |
|-----|---------|
| `Entity/` | ~21 Doctrine entities (UUID ids) |
| `ApiResource/` | ~20 API Platform resource DTOs (drive `/api/*`) |
| `Controller/` | 7 custom controllers (see 2.3) |
| `Message/` + `MessageHandler/` | 2 messages + 2 async handlers |
| `Service/` | ~12 business services (snapshot builder, result importer, PDF, FFBB importer, locks…) |
| `Repository/` | Doctrine repositories |
| `EventListener/` | `TenantFilterListener`, `CacheInvalidationListener`, `TeamTagSyncListener` |
| `Doctrine/Filter/` | `TenantFilter` (SQL-level tenant scoping) |
| `State/`, `Dto/`, `Enum/` (~10 enums), `DataFixtures/`, `Command/` | supporting code |

### 2.2 Domain entities (core)
- **Club** — root tenant: `slug` (unique), `ffbbClubCode`, `planId`, `billingCycle`, `timezone`, `locale`, `onboardingCompleted`.
- **User** (global) + **ClubUser** (membership junction: `clubId`, `userId`, `role`, `isActive`; unique `(clubId,userId)`) — access control pivot.
- **Season** — per-club: dates, `status`, `transitionData`.
- **Team** — per-club-season: `sportCategoryId`, `priorityTierId`, `level`, `gender`, `size`, `sessionsPerWeek`, `allowMultipleSessionsPerDay`, `forcedVenueId`, `parentTeamId`.
- **Schedule** — per-club-season run: `status` (`PENDING|GENERATING|COMPLETED|FAILED`), `score`, snapshot data/hash, solver metrics.
- **Coach**, **Venue**, **Constraint**, **ScheduleSlotTemplate**, **ScheduleDiagnostic**, …

### 2.3 API layer
- **Auto CRUD (API Platform):** `/api/{schedules,clubs,teams,coaches,venues,constraints,seasons,sport-categories,…}` — ~20 resources, default pagination 30/page. OpenAPI at `/api/docs`.
- **Custom controllers:**
  | Controller | Route | Action |
  |-----------|-------|--------|
  | `GenerateScheduleController` | `POST /api/schedules/{id}/generate` | dispatch `GenerateScheduleMessage` |
  | `ExportPdfController` | `POST /api/schedules/{id}/export-pdf` | dispatch `ExportPdfMessage` |
  | `ImportController` | `POST /api/clubs/{id}/import` | XLSX import via `FfbbExcelImporter` |
  | `ManualEditController` | `POST /api/schedule-slots/{id}/manual-edit/{constraint,lock,one-time}` | manual slot edits |
  | `ResetSeasonController` | `DELETE /api/reset-season` | batch-delete season data |
  | `AuthController` | `POST /api/register`, `GET /api/me` | registration / profile (JWT) |
  | `HealthController` | `GET /api/health` | `{"status":"ok"}` |

### 2.4 Async / messaging
- **Transport:** Redis (`redis://redis:6379/messages`), `sync://` under test. Worker: `messenger-worker` container.
- **`GenerateScheduleMessage`** (`scheduleId`, `clubId`, `timeoutSeconds`=650) → **`GenerateScheduleHandler`**: acquire `ClubGenerationLock` → build frozen snapshot (`ScheduleConstraintBuilder.buildForClubSeason()`) → `POST http://engine:8000/generate` → import via `ScheduleResultImporter` → write `ScheduleDiagnostic` → publish Mercure.
- **`ExportPdfMessage`** → **`ExportPdfHandler`**: `PdfGenerator.generate()` → publish Mercure with export URLs.
- **Mercure topic:** `club:{clubId}:schedule:{scheduleId}` (validated non-empty). `MERCURE_URL` env.
- **`ClubGenerationLock`** (Redis): key `schedule_generation:club:{clubId}`, atomic `SETEX NX` + TTL, token-checked release.

### 2.5 Multi-tenant isolation (security-critical)
1. `TenantFilter` (Doctrine SQL filter) appends `{table}.club_id = :param` on tenant entities; registered in `config/packages/doctrine.yaml`.
2. `TenantFilterListener` (kernel REQUEST, priority 8): resolves club from `_club_id` attr / `X-Club-Id` header, **requires an active `ClubUser` membership (else 403)**, enables the filter, and sets PostgreSQL `SET LOCAL app.club_id` (RLS).
3. Cache pools `cache.tenant` (1h) and `cache.schedule` (4h) on Redis; `CacheInvalidationListener` keeps them coherent.
- Reference docs: `backend/docs/TENANT.md`, `backend/docs/RLS.md`.

### 2.6 Tooling (verified)
- **PHPStan** level 8 (`phpstan.neon`, Doctrine+Symfony ext).
- **CS-Fixer** (`.php-cs-fixer.dist.php`): `@Symfony` risky + `@PHP84Migration` + `@PHP80Migration:risky` + Yoda + strict comparisons + trailing commas.
- **Rector** (`rector.php`): `withPhpVersion(80400)`, aligned with composer `>=8.4`.
- **PHPUnit**: direct `vendor/bin/phpunit` (11.5.55, `phpunit/phpunit ^11`) in CI, `Makefile`, and `composer test`; schema `vendor/phpunit/phpunit/phpunit.xsd`. `make phpunit` adds `--group phase1`; the suite needs `make db-init-test` first.
- `config/services.yaml`: autowire all `App\*` except `DevScheduleReportWriter` (dev-only tool).

---

## 3. Engine (`engine/`)

**Entry point:** `app/main.py` (FastAPI). Routes: `GET /`, `GET /health`, `POST /generate` (main), `POST /implicit-constraints` (sync enabled implicit rules with backend).

### 3.1 Modules
| Module | Role |
|--------|------|
| `app/main.py` | FastAPI app, route handlers, per-club locking, solve orchestration |
| `app/core/config.py` | `Settings` (pydantic-settings, env prefix `ENGINE_`) |
| `app/schemas/input_schema.py` | `ScheduleInputSchema` (+ Venue/Team/Coach/Constraint/SlotTemplate); `solver_timeout_seconds`=650, `solver_seed`=42 |
| `app/schemas/output_schema.py` | `ScheduleOutputSchema`, `ScheduleSlotSchema`, `DiagnosticSchema`, `SolverMetricsSchema` |
| `app/solver/model.py` | `ScheduleCpModel(cp_model.CpModel)`, `build_model`, slot/lock/capacity extraction |
| `app/solver/constraints.py` (~1640 l.) | Level-1 hard constraints + `parse_v2_constraints()` |
| `app/solver/objective.py` (~732 l.) | Level-2 soft objective, tiered placement scoring, bonuses |
| `app/solver/result_builder.py` (~701 l.) | CP-SAT solution → output schema + diagnostics |

### 3.2 Solve pipeline (`POST /generate`)
parse `ScheduleInputSchema` → `build_model()` → `parse_v2_constraints()` → `add_level_1_hard_constraints()` + time-window constraints → `add_level_2_objective()` → solve (`max_time_in_seconds = solver_timeout_seconds` default **650 s**, `random_seed = solver_seed`) → `build_result()` → `ScheduleOutputSchema` with diagnostics. Per-club serialization via `_club_locks: dict[str, asyncio.Lock]` (+ `_club_locks_guard`).

### 3.3 Contract
`engine/CONTRACT_VERSION` holds the version (e.g. `2.0`). Backend syncs the version via `GET /` and enabled implicit rules via `POST /implicit-constraints`. No codegen — Pydantic (engine) ⇄ payload (backend) are kept in sync manually; `ContractSchemaTest` (backend) is the guardrail.

### 3.4 Tooling (verified) — see `pyproject.toml`
ruff (line 120, py312, double quotes, LF) · mypy `strict` + `pydantic.mypy` (`ortools.*` ignored) · pytest `-ra` + pytest-timeout + pytest-cov + hypothesis · bandit (excludes `tests`). Runtime deps: `fastapi`, `pydantic`, `pydantic-settings`, `ortools 9.11.x`, `uvicorn[standard]`.

---

## 4. Infrastructure

- **Orchestration:** root `docker-compose.yml` (reads `.env`; template `.env.dist`).
- **Services:** PostgreSQL 16 (`clubscheduler-postgres`), Redis 7 appendonly (`clubscheduler-redis`), Mercure hub (`clubscheduler-mercure`, JWT via `JWT_PASSPHRASE`), Mailpit (`clubscheduler-mailpit`), `pdf-worker` (Node), `php-fpm` + nginx, `engine`, `messenger-worker`. Every service has a Docker healthcheck.
- **Prod vs dev:** dev `npm run dev` on host (:5173) proxies `/api`→8080, `/.well-known/mercure`→3000, `/engine`→8000. Prod: frontend Nginx serves static files and proxies `/api` to backend Nginx.

---

## 5. Knowledge graph & the auto-update hook (pre-existing automation)

`code-review-graph` is installed (`uv tool`) and a graph is built for **backend + engine only** (`.code-review-graphignore` excludes `frontend/` and non-code dirs). It backs the `explore-codebase`, `review-changes`, `refactor-safely`, `debug-issue` skills.

Two hooks in `.claude/settings.json` (paths corrected to this checkout):
- **`SessionStart`** → `code-review-graph status` (read-only).
- **`PostToolUse` on `Edit|Write|Bash`** → `code-review-graph update --skip-flows` — **the only automatic action in this repo**: it incrementally re-indexes the graph after each edit. It never touches application code. This is the documented exception to the "no hidden automation" rule. To make it manual, remove the `PostToolUse` block and run `code-review-graph update` by hand.

The MCP server (`.mcp.json`: `uvx code-review-graph serve`) loads at session start; its tools (`semantic_search_nodes`, `query_graph`, `get_impact_radius`, `detect_changes`, …) are the preferred way to explore before Grep/Read. Semantic search additionally needs `code-review-graph embed` (not run — pulls a heavy local model).

---

## 6. Cross-references
- Tests & guardrails: [`testing/testing-strategy.md`](testing/testing-strategy.md)
- Debt (proof-backed): [`technical-debt.md`](technical-debt.md) · safe deletions: [`cleanup-candidates.md`](cleanup-candidates.md)
- Decisions to formalize: [`architecture/adr-index.md`](architecture/adr-index.md)
