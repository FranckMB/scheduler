# ClubScheduler — Operational Index

> Canonical agent cheat-sheet for this monorepo. Short on purpose (< 200 lines).
> Detail lives in `docs/`. If a fact is obvious from filenames, it is not here.
> Agent read order: **this file → `docs/project-map.md` (detail) → `specs/courantes/` (execution specs)**.
> Scope note: the current `frontend/` is slated for **deletion + a from-scratch React rebuild**. It is excluded from the code-review-graph index and the technical-debt audit until the rebuild starts.

## 1. What this is

ClubScheduler generates per-club, per-season training schedules for basketball clubs (FFBB).
A constraint solver (OR-Tools CP-SAT) places teams into venue time-slots under hard rules + a soft scoring objective. **Backend** orchestrates/persists/exposes the API, **engine** solves, **frontend** renders (being rebuilt).

## 2. Stack & zones

| Zone | Lang / Runtime | Entry point | Role |
|------|----------------|-------------|------|
| `backend/` | PHP 8.4 · Symfony 7.4 · API Platform 4.3 · Doctrine ORM 3.6 | `public/index.php` | API, persistence, async orchestration |
| `engine/` | Python 3.12 · FastAPI · OR-Tools CP-SAT | `app/main.py` | Schedule solver (`POST /generate`) |
| `frontend/` | TS · React 18 · Vite | `src/main.tsx` | UI — **being rebuilt; do not invest** |
| `specs/` | Markdown | `specs/README.md` | Living specs (initiales/courantes/evolution) |

**Boundaries (critical — never cross these):**
- `frontend → backend` via `/api/*` · `backend → engine` via `POST http://engine:8000/generate` · `backend → frontend` via Mercure SSE topic `club:{clubId}:schedule:{scheduleId}`.
- **Engine is reactive: it NEVER calls the backend.** **Frontend NEVER calls the engine directly.**

## 3. Key commands

Backend & engine commands run **inside Docker** (their Makefiles wrap `docker compose exec`). Frontend dev runs **on the host**.

```bash
make start | stop | install | test | lint        # root orchestration (docker compose, reads .env)
cd backend && make test    # CS-Fixer + PHPStan(lvl8) + PHPUnit (--group phase1)
cd backend && make phpstan | cs-fix | rector | migration-diff | migration-migrate
cd engine  && make test    # pytest + ruff + mypy   |  make format
cd frontend && npm run dev  # host, Vite :5173 (proxies /api,/engine,/.well-known/mercure)
```

## 4. CI order (`.github/workflows/ci.yml`)

`lint → phpstan → blocking-tests → {unit, functional} → engine-tests → build-docker`

**blocking-tests** (must pass first, all `--group phase1`): `Security/TenantIsolationTest`, `Security/TenantCacheIsolationTest` *(currently skipped — deferred)*, `Queue/ConcurrentGenerationTest`, `CrossStack/ContractSchemaTest`. Detail: `docs/testing/testing-strategy.md`.

## 5. Conventions (essentials)

- **Backend:** PHPStan level 8 (Doctrine+Symfony ext) · CS-Fixer `@Symfony` + `@PHP84Migration` + risky + Yoda + strict comparisons · Rector targets PHP **8.4** (aligned with composer `>=8.4`) · PHPUnit runs directly via `vendor/bin/phpunit` (PHPUnit 11, the `phpunit/phpunit` dev-dep) — same binary in CI, `Makefile`, and `composer test`.
- **Engine:** ruff (line 120, py312, double quotes, LF) · mypy `strict` + `pydantic.mypy` plugin (`ortools.*` ignored) · pytest (`-ra`) + golden fixtures + invariants + hypothesis.

## 6. Critical mechanisms

- **Multi-tenant isolation** (backend): Doctrine `TenantFilter` + `TenantFilterListener` (resolves club from `_club_id` / `X-Club-Id`, requires an active `ClubUser` membership → 403 otherwise) + PostgreSQL RLS `SET LOCAL app.club_id`. Guarded by `TenantIsolationTest`. See `backend/docs/TENANT.md`, `backend/docs/RLS.md`.
- **Concurrency**: backend `ClubGenerationLock` (Redis `SETEX NX` + release token); engine per-club `asyncio.Lock`. Guarded by `ConcurrentGenerationTest`.
- **Async generation**: `GenerateScheduleController` → `GenerateScheduleMessage` → `GenerateScheduleHandler` (frozen snapshot → POST engine → import results → Mercure publish). Symfony Messenger over Redis, `messenger-worker` container.
- **Backend↔engine contract**: engine Pydantic schemas ⇄ backend payload; version in `engine/CONTRACT_VERSION`. **No codegen — synced manually.** Guarded by `ContractSchemaTest`.
- **Solver**: CP-SAT, default **timeout 650 s** + seed 42, both from the input payload (`solver_timeout_seconds` / `solver_seed`). *(NB: `engine/app/solver/AGENTS.md` still says "10s" — stale, see debt.)*

## 7. Workflow rules (orchestrator)

All custom agents/skills are **manual / user-triggered**. No hidden automation, with one pre-existing exception documented in `docs/project-map.md` (the `code-review-graph` PostToolUse hook).

Feature cycle: need → *(I read this file, then enter `/plan` injecting the scope checklist §9)* → optional `contrarian-review` agent on the plan → you validate → I implement **strictly in scope** (no opportunistic refactor) → change summary → optional `validation-runner` → optional `documentation-update` → optional `/code-review` (+ `/security-review` if the change touches auth/data/external integrations).

**Before every `/plan`** I read this file myself and inject the boundaries (§2), conventions (§5) and the scope checklist (§9) into the plan prompt — the built-in `Plan`/`Explore` subagents do **not** read `CLAUDE.md`.

## 8. Documentation rules

`CLAUDE.md` = short index; `docs/` = detail; **one canonical home, no duplication**. Root `AGENTS.md` is a pointer to this file; nested `backend/AGENTS.md` & `engine/AGENTS.md` hold package-level detail. Update only via the `documentation-update` skill when behaviour / architecture / conventions / APIs actually changed. Structural decisions → ADR in `docs/architecture/adr-index.md`. Update `specs/courantes/` per the triggers in `specs/README.md`.

## 9. Scope checklist — inject verbatim into every `/plan`; the produced plan must fill these literally

- besoin reformulé et ambiguïtés identifiées avant de planifier ;
- zone ou sous-projet concerné (engine / backend / frontend, etc.) ;
- dossiers autorisés et dossiers interdits pour cette feature ;
- fichiers probablement modifiés et fichiers de tests probablement modifiés ;
- documentation à mettre à jour si le plan est exécuté ;
- conditions qui exigeraient de revenir demander une validation (changement de zone, dépendance inter-zone non prévue) ;
- confirmation explicite qu'aucun refactoring hors scope n'est prévu.

## 10. Gotchas (top)

1. Backend & engine commands fail on the host — they must run inside the containers. Frontend dev is host-only.
2. PHPUnit = `vendor/bin/phpunit` (PHPUnit 11) everywhere (CI, `Makefile`, `composer test`). `make phpunit` adds `--group phase1`. The suite needs the test DB — run `make db-init-test` first (CI brings it up via `docker compose up -d --wait`).
3. `contracts/` and the top-level `tests/` dir are empty placeholders (cross-stack tests live in `backend/tests/`).
4. Frontend is being deleted/rebuilt — excluded from the graph index (`.code-review-graphignore`) and the debt audit.

**Pointers:** `docs/project-map.md` · `docs/testing/testing-strategy.md` · `docs/technical-debt.md` · `docs/cleanup-candidates.md` · `docs/architecture/adr-index.md` · `specs/README.md`
