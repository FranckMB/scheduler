# ClubScheduler — Agent Context

> Monorepo agent cheat-sheet. If a fact is obvious from filenames, it is not here.

---

## Repo Layout

| Package | Language | Runtime | Key Entry Point |
|---------|----------|---------|-----------------|
| `backend/` | PHP 8.4 | Symfony 7 + API Platform | `public/index.php` |
| `frontend/` | TypeScript ~6.0 | React 18 + Vite 8 | `src/main.tsx` |
| `engine/` | Python 3.12 | FastAPI + OR-Tools CP-SAT | `app/main.py` |
| `specs/` | Markdown | Living specs — initiales/courantes/evolution (`specs/initiales/`, `specs/courantes/`, `specs/evolution/`) | `specs/README.md` |
| `docs/technique/` | Markdown | Technical runbook | `docs/technique/DEVELOPMENT.md` |
| `contracts/` | — | Shared API schemas (currently empty) | — |
| `tests/` | — | Cross-cutting / E2E test assets (currently empty) | — |

All services communicate via Docker network `clubscheduler_network`.

---

## Development Commands

### Root (orchestration)

```bash
make start   # docker compose up -d --wait (reads .env)
make stop    # docker compose down
make install # installs deps in all three packages
make test    # runs backend + engine + frontend tests
make lint    # runs backend + engine + frontend linters
make exec SERVICE=php-fpm  # open shell in a container
```

### Backend (runs **inside** Docker containers)

All backend commands are wrapped by `backend/Makefile` to run inside the `php-fpm` container.

```bash
make install          # composer install
make test             # lint + phpunit (group phase1)
make lint             # phpstan + cs + rector (dry-run)
make phpunit          # php vendor/bin/.phpunit/phpunit-9.6-0/phpunit --group phase1
make phpstan          # composer phpstan (level 8)
make cs-fix           # composer cs-fix (PHP-CS-Fixer)
make rector           # composer rector -- --dry-run
make schema-validate  # doctrine:schema:validate
make migration-diff   # doctrine:migrations:diff
make migration-migrate# doctrine:migrations:migrate
make exec             # shell in php-fpm container
```

**Important:** The PHPUnit binary is at the non-obvious path `vendor/bin/.phpunit/phpunit-9.6-0/phpunit`. The `make phpunit` target already includes `--group phase1`.

### Frontend (runs **on host** for dev)

```bash
npm install       # install deps
npm run dev       # Vite dev server (port 5173)
npm run build     # tsc -b && vite build
npm run lint      # ESLint
npm run preview   # preview production build
```

**Dev vs production:**
- Dev: `npm run dev` on host; Vite proxies `/api` → `localhost:8080`, `/.well-known/mercure` → `localhost:3000`, `/engine` → `localhost:8000`.
- Production: static files served by Nginx container on port 8081.

Frontend `Makefile` also has Docker helpers (`make start`, `make stop`, `make logs`, `make shell`), but `make install`, `make build`, `make dev`, `make lint`, `make test` run on the host machine.

### Engine (runs **inside** Docker container)

```bash
make install   # pip install -e ".[dev]"
make test      # pytest
make lint      # ruff check . && mypy
make format    # ruff format .
make exec      # shell in engine container
```

---

## CI / Test Pipeline

The CI workflow (`.github/workflows/ci.yml`) enforces the following order:

1. `lint` — validate Docker Compose + Makefile
2. `phpstan` — PHPStan level 8 (needs postgres + redis)
3. `blocking-tests` — four specific test files, all with `--group phase1`:
   - `tests/Security/TenantIsolationTest.php`
   - `tests/Security/TenantCacheIsolationTest.php`
   - `tests/Queue/ConcurrentGenerationTest.php`
   - `tests/CrossStack/ContractSchemaTest.php`
4. `unit-tests` — full PHPUnit suite
5. `functional-tests` — `tests/ --filter Functional`
6. `engine-tests` — pytest + ruff + mypy
7. `build-docker` — `docker compose build`

**Takeaway:** `blocking-tests` must pass before the rest of the PHP test suite runs. All blocking tests use `--group phase1`.

---

## Service Architecture & Inter-Communication

```
Browser ──► frontend:8081 (Nginx) ──► backend:8080 (nginx → php-fpm)
                │                        │
                │                        ├──► engine:8000 /generate
                │                        │
                └──◄─────────────────────┘ Mercure SSE (topic: club:{id}:schedule:{id})
```

- **Frontend → Backend:** relative URLs `/api/*`, proxyfied by frontend Nginx → backend Nginx.
- **Backend → Engine:** direct HTTP POST `http://engine:8000/generate` from `GenerateScheduleHandler`.
- **Frontend → Mercure:** `EventSource` on `/.well-known/mercure?topic=...`
- **Frontend never calls Engine directly.**
- **Engine never calls Backend directly.**

---

## Key Toolchain & Conventions

### Backend (PHP)
- **PHPStan:** level 8, with Doctrine + Symfony extensions.
- **CS-Fixer:** `@PSR12` + `@Symfony` + `strict_comparison` + `yoda_style` (equal/identical only).
- **Rector:** PHP 8.3 target, `codeQuality` + `typeDeclarations` + `symfony` sets.
- **API Platform:** all CRUD auto-generated under `/api/*`; OpenAPI docs at `/api/docs`.
- **Doctrine:** migrations live in `backend/migrations/`.
- **Messenger:** async bus uses Redis (`clubscheduler-redis` container). Worker runs in `messenger-worker` container.
- **Custom controllers:** `GenerateScheduleController` (`POST /api/schedules/{id}/generate`), `ExportPdfController` (`POST /api/schedules/{id}/export-pdf`).

### Frontend (React)
- **Build:** Vite 8 with `@tailwindcss/vite` plugin.
- **Alias:** `@/` resolves to `src/`.
- **Lint:** ESLint flat config (`eslint.config.js`) — `typescript-eslint`, `react-hooks`, `react-refresh`.
- **State:** Zustand for auth; TanStack Query (React Query) for server state.
- **HTTP:** `ky` client with Bearer token injection and 401 → logout redirect.
- **UI:** Tailwind CSS + FullCalendar + `@dnd-kit`.

### Engine (Python)
- **FastAPI** with Pydantic v2 schemas (`ScheduleInputSchema`, `ScheduleOutputSchema`).
- **Solver:** Google OR-Tools CP-SAT (`max_time=10s`).
- **Lint:** `ruff` (line length 120, target py312, double quotes, space indent, LF).
- **Type check:** `mypy` strict + `pydantic.mypy` plugin; `ortools` imports ignored.
- **Tests:** `pytest` with `pytest-timeout` and `hypothesis`. Golden fixtures in `tests/fixtures/`.
- **Isolation:** per-club asyncio locks prevent concurrent generation for the same club.

---

## Docker & Infrastructure

- **Base orchestration:** `docker-compose.yml` at repo root.
- **Env:** `.env` at root (not committed). `.env.dist` is the template.
- **Database:** PostgreSQL 16 (`clubscheduler-postgres`).
- **Cache / Message Bus:** Redis 7 (`clubscheduler-redis`), appendonly enabled.
- **Real-time:** Mercure hub (`clubscheduler-mercure`), JWT keyed by `JWT_PASSPHRASE`.
- **Mail:** Mailpit (`clubscheduler-mailpit`) for SMTP capture.
- **PDF Worker:** `pdf-worker` container (Node) — runs `worker.js` if present, else sleeps.
- **Healthchecks:** every service defines a Docker healthcheck.

---

## Gotchas & Agent Pitfalls

1. **PHPUnit path:** Do not guess `vendor/bin/phpunit`. The actual binary is `vendor/bin/.phpunit/phpunit-9.6-0/phpunit`. The `make phpunit` target already uses it.
2. **Backend commands must run in container:** `backend/Makefile` wraps everything with `docker compose exec`. Running `composer` or `php bin/console` directly on the host will fail unless PHP 8.4 and extensions are installed locally.
3. **Frontend dev runs on host:** `npm run dev` is meant to run on the host machine (port 5173). Only `make start` / `make stop` / `make logs` / `make shell` are Docker helpers.
4. **Engine commands must run in container:** `engine/Makefile` wraps everything with `docker compose exec`. Running `pytest` or `ruff` on the host will fail unless the Python venv is set up locally.
5. **CI order matters:** `blocking-tests` must pass before `unit-tests` and `functional-tests`. If you add a new cross-cutting security or contract test, consider whether it belongs in `blocking-tests`.
6. **Contracts directory is empty:** There is no shared codegen yet. The API contract between backend and engine is manually kept in sync via Pydantic schemas (engine) and API Platform resources (backend). The `ContractSchemaTest` is the guardrail.
7. **Tests directory is empty:** Cross-cutting tests are currently inside `backend/tests/`. The top-level `tests/` directory is reserved for future E2E or cross-service tests.
8. **Rector targets PHP 8.3:** `rector.php` specifies `withPhpVersion(80300)` even though the project requires PHP 8.4. This is intentional — do not change without verifying the Rector rule set supports 8.4.
9. **CLAUDE.md is a 1-line pointer:** real onboarding lives in `AGENTS.md` and execution specs live in `specs/courantes/`.
10. **Dev vs prod proxy:** The Vite dev server proxies API calls. In production, the frontend Nginx container proxies `/api` to the backend Nginx container. Do not hardcode `localhost:8080` in frontend API calls.

---

## Quick Reference

| 3-tier living spec system | `specs/README.md` | `specs/initiales/`, `specs/courantes/`, `specs/evolution/` | overview |
| Task | Command |
|------|---------|
| Start stack | `make start` |
| Stop stack | `make stop` |
| Backend shell | `make exec SERVICE=php-fpm` (or `cd backend && make exec`) |
| Backend tests | `cd backend && make test` |
| Backend lint | `cd backend && make lint` |
| Frontend dev | `cd frontend && npm run dev` |
| Frontend lint | `cd frontend && npm run lint && npx tsc --noEmit` |
| Engine shell | `cd engine && make exec` |
| Engine tests | `cd engine && make test` |
| Engine lint | `cd engine && make lint` |
| Health check | `make health` (curls `localhost:8080/api/health`) |
| Logs | `make logs` |

<!-- code-review-graph MCP tools -->
## MCP Tools: code-review-graph

**IMPORTANT: This project has a knowledge graph. ALWAYS use the
code-review-graph MCP tools BEFORE using Grep/Glob/Read to explore
the codebase.** The graph is faster, cheaper (fewer tokens), and gives
you structural context (callers, dependents, test coverage) that file
scanning cannot.

### When to use graph tools FIRST

- **Exploring code**: `semantic_search_nodes` or `query_graph` instead of Grep
- **Understanding impact**: `get_impact_radius` instead of manually tracing imports
- **Code review**: `detect_changes` + `get_review_context` instead of reading entire files
- **Finding relationships**: `query_graph` with callers_of/callees_of/imports_of/tests_for
- **Architecture questions**: `get_architecture_overview` + `list_communities`

Fall back to Grep/Glob/Read **only** when the graph doesn't cover what you need.

### Key Tools

| Tool | Use when |
| ------ | ---------- |
| `detect_changes` | Reviewing code changes — gives risk-scored analysis |
| `get_review_context` | Need source snippets for review — token-efficient |
| `get_impact_radius` | Understanding blast radius of a change |
| `get_affected_flows` | Finding which execution paths are impacted |
| `query_graph` | Tracing callers, callees, imports, tests, dependencies |
| `semantic_search_nodes` | Finding functions/classes by name or keyword |
| `get_architecture_overview` | Understanding high-level codebase structure |
| `refactor_tool` | Planning renames, finding dead code |

### Workflow

1. The graph auto-updates on file changes (via hooks).
2. Use `detect_changes` for code review.
3. Use `get_affected_flows` to understand impact.
4. Use `query_graph` pattern="tests_for" to check coverage.
