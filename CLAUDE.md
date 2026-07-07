# ClubScheduler — Operational Index

> Canonical agent cheat-sheet for this monorepo. Short on purpose (< 200 lines).
> Detail lives in `docs/`. If a fact is obvious from filenames, it is not here.
> Agent read order: **this file → `docs/project-map.md` (detail) → `specs/courantes/` (execution specs)**.
> Scope note: `frontend/` has been **rebuilt from scratch** (React 19 · Vite · Tailwind 4) and is **active** — it is indexed by the code-review-graph and Serena. Delivered: auth, the **planning work-loop** (`src/features/planning`) and the **data-entry wizard** (`src/features/wizard`).

## 1. What this is

ClubScheduler generates per-club, per-season training schedules for basketball clubs (FFBB).
A constraint solver (OR-Tools CP-SAT) places teams into venue time-slots under hard rules + a soft scoring objective. **Backend** orchestrates/persists/exposes the API, **engine** solves, **frontend** renders (wizard to enter data → generate → work-loop to adjust/regenerate).

## 2. Stack & zones

| Zone | Lang / Runtime | Entry point | Role |
|------|----------------|-------------|------|
| `backend/` | PHP 8.4 · Symfony 7.4 · API Platform 4.3 · Doctrine ORM 3.6 | `public/index.php` | API, persistence, async orchestration |
| `engine/` | Python 3.12 · FastAPI · OR-Tools CP-SAT | `app/main.py` | Schedule solver (`POST /generate`) |
| `frontend/` | TS · React 19 · Vite · Tailwind 4 | `src/main.tsx` | UI — auth · planning work-loop · data-entry wizard |
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

`{lint, phpstan} → blocking-tests → unit-tests · engine-tests (parallel) → build-docker`

**blocking-tests** (must pass first, all `--group phase1`): `Security/TenantIsolationTest`, `Security/SeasonIsolationTest` (multi-season scoping + X-Season-Id validation), `Security/SeasonReadonlyTest` (archived-season writes → 409), `Security/MatchTenantIsolationTest` (match entities tenant+season scoped), `Security/TenantCacheIsolationTest`, `Queue/ConcurrentGenerationTest`, `CrossStack/ContractSchemaTest`, `Security/RlsIsolationTest` (RLS enforced at the DB), `Security/{ClubAccessTest,UserSelfOnlyTest,ImportAuthorizationTest}` (SEC-01/02/04 tenant-API lockdown), `Security/MercureHardeningTest` (SEC-05/06). Detail: `docs/testing/testing-strategy.md`.

## 5. Conventions (essentials)

- **Backend:** PHPStan level 8 (Doctrine+Symfony ext) · CS-Fixer `@Symfony` + `@PHP84Migration` + risky + Yoda + strict comparisons · Rector targets PHP **8.4** (aligned with composer `>=8.4`) · PHPUnit runs directly via `vendor/bin/phpunit` (PHPUnit 11, the `phpunit/phpunit` dev-dep) — same binary in CI, `Makefile`, and `composer test`.
- **Engine:** ruff (line 120, py312, double quotes, LF) · mypy `strict` + `pydantic.mypy` plugin (`ortools.*` ignored) · pytest (`-ra`) + golden fixtures + invariants + hypothesis.

## 6. Critical mechanisms

- **Multi-tenant isolation** (backend): 3 layers. (1) Doctrine `TenantFilter` + `TenantFilterListener` (**priority 7, AFTER the firewall**) resolves club from `_club_id` / `X-Club-Id` / else the **authenticated JWT user's active `ClubUser` membership** (the frontend sends no header); spoofed header → 403. The listener also resolves the **season** (`X-Season-Id` validated → 403 if foreign/unknown; else calendar-derived current via `SeasonResolver`, July-15 pivot) and enables the `season_filter` (intra-club correctness boundary — see `backend/docs/TENANT.md`). (2) **PostgreSQL RLS ACTIVE**: runtime = `app_user`, `FORCE` policies on all `club_id` tables keyed on the `app.club_id` GUC (`TenantConnectionContext`; workers set it from the message `clubId`); migrations/ops = `clubscheduler` via the Doctrine `admin` connection (**superadmin door, bypasses RLS**). (3) Club/User (no `club_id`) scoped in their providers/processors. See `backend/docs/TENANT.md` + `docs/security/rls.md`. ⚠️ Listener before auth = historical cross-club leak (fixed — never move it back). Guarded by `TenantIsolationTest`, `TenantJwtIsolationTest`, `RlsIsolationTest`, `OnboardingFlowTest`.
- **Concurrency**: backend `ClubGenerationLock` (Redis `SETEX NX` + release token); engine per-club `asyncio.Lock`. Guarded by `ConcurrentGenerationTest`.
- **Async generation**: `GenerateScheduleController` → `GenerateScheduleMessage` → `GenerateScheduleHandler` (frozen snapshot → POST engine → import results → Mercure publish). Symfony Messenger over Redis, `messenger-worker` container.
- **Backend↔engine contract**: engine Pydantic schemas ⇄ backend payload; version in `engine/CONTRACT_VERSION`. **No codegen — synced manually.** Guarded by `ContractSchemaTest`.
- **Solver**: CP-SAT, **no relaxation fallback** (all HARD constraints in every attempt; the objective is optimised in two lexicographic phases — placement then chaining, phase 2 capped at 10 s). Phase-1 budget = **adaptive tiers 60/180/600 s** by problem size (`n_teams×n_venues` ≤50/≤200/else), with the payload `solver_timeout_seconds` (default 650) as a **ceiling only** — never the actual budget. Seed from `solver_seed`. INFEASIBLE → `status="failed"` + diagnostics (see `docs/architecture/adr-0001-single-pass-solve.md`, amended 2026-07-07).

## 7. Workflow rules (orchestrator)

All custom agents/skills are **manual / user-triggered**. No hidden automation, with one pre-existing exception documented in `docs/project-map.md` (the `code-review-graph` PostToolUse hook).

**Git discipline (non-negotiable).** **NEVER commit directly on `main`** — always branch first (feature/fix/**docs & specs included**), commit on the branch, open a PR. **NEVER merge a PR without the user's explicit go** — the user keeps the hand on everything that lands on `main`. Push the branch freely (no CI gating), but stop at "PR ready, waiting for your go" and never run `gh pr merge` before it. Applies to **every** change, doc-only ones too.

**Two lanes.** Pick the lane BEFORE starting and say which one applies:
- **Full lane** (default for any feature, behaviour change, API/schema change, or anything touching a structuring axis §7.1).
- **Light lane** — only if ALL true: ≤2 files, no behaviour/API/schema change, no structuring axis touched (typo, label, doc, tiny fix). Cycle: implement → relevant tests green locally → doc check declaration → `/code-review` → PR → user go.

**Full lane cycle:**
1. **Need validation (mandatory, before any plan):** reformulate the need in 3–6 lines + open ambiguities + what I will NOT do. **User validates or corrects — no `/plan` before that.**
2. `/plan` injecting boundaries §2, conventions §5, scope checklist §9 (the built-in `Plan`/`Explore` subagents do **not** read `CLAUDE.md`). Optional `contrarian-review` on the plan. User validates the plan.
3. Implement **strictly in scope** (no opportunistic refactor).
4. **Non-regression (mandatory if a structuring axis §7.1 is touched):** add/extend a test guarding the axis in the same PR (`--group phase1`, engine invariant/golden, or e2e).
5. **Tests green locally before proposing merge** (blocking tests + the new NR tests + zone suite). CI is a double-check and does NOT block the merge.
6. Change summary + **doc check (mandatory):** either run `documentation-update`, or state explicitly "no doc impacted because …". Never skip silently.
7. **`/code-review` on every PR** (+ `/security-review` if the change touches auth/data/external integrations).
8. PR → **user's explicit go** → merge.

### 7.1 Structuring axes (closed list — NR test required when touched)

tenant isolation (filter/listener/voters) · generation pipeline (controller→messenger→engine→import→Mercure) · **constraint semantics** (a constraint entered in the UI must be honored by the solver — semantic smoke, not just COMPLETED) · planning lifecycle (VALIDATED/reopen/baseline + edit locks) · backend↔engine contract (schemas/CONTRACT_VERSION) · auth & memberships (register/login/approval/roles). Extending this list = user decision.

**Engine/backend changes — mandatory final verification:** the solver smoke-test `backend/scripts/smoke-solver.sh` drives create→generate→poll and asserts a schedule reaches `COMPLETED` (diagnostics/warnings acceptable — the point is the CP-SAT solver responded and produced a plan). It runs inside `validation-runner`. `generate-schedule-test.sh` is a *mock* (fake `curl`) and does **not** count.

## 8. Documentation rules

`CLAUDE.md` = short index; `docs/` = detail; **one canonical home, no duplication**. Root `AGENTS.md` is a pointer to this file; nested `backend/AGENTS.md` & `engine/AGENTS.md` hold package-level detail. Update only via the `documentation-update` skill when behaviour / architecture / conventions / APIs actually changed. Structural decisions → ADR in `docs/architecture/adr-index.md`. Update `specs/courantes/` per the triggers in `specs/README.md`.

## 9. Scope checklist — inject verbatim into every `/plan`; the produced plan must fill these literally

- besoin reformulé et ambiguïtés identifiées avant de planifier ;
- zone ou sous-projet concerné (engine / backend / frontend, etc.) ;
- dossiers autorisés et dossiers interdits pour cette feature ;
- fichiers probablement modifiés et fichiers de tests probablement modifiés ;
- documentation à mettre à jour si le plan est exécuté ;
- conditions qui exigeraient de revenir demander une validation (changement de zone, dépendance inter-zone non prévue) ;
- confirmation explicite qu'aucun refactoring hors scope n'est prévu ;
- **axes structurants (§7.1) touchés → test de non-régression prévu dans la même PR** (lequel, dans quel groupe) ;
- si la zone touche **engine ou backend**, la section vérification inclut le **smoke-test solveur** (`backend/scripts/smoke-solver.sh`, planning attendu en `COMPLETED`).

## 10. Gotchas (top)

1. Backend & engine commands fail on the host — they must run inside the containers. Frontend dev is host-only.
2. PHPUnit = `vendor/bin/phpunit` (PHPUnit 11) everywhere (CI, `Makefile`, `composer test`). `make phpunit` adds `--group phase1`. The suite needs the test DB — run `make db-init-test` first (CI brings it up via `docker compose up -d --wait`).
3. `contracts/` and the top-level `tests/` dir are empty placeholders (cross-stack tests live in `backend/tests/`).
4. Frontend is rebuilt + **active** — indexed by the graph (only its build artifacts `dist`/`node_modules`/`storybook-static` are ignored). Tenant is resolved server-side from the JWT: the frontend sends **no** `X-Club-Id` header.

**Pointers:** `docs/project-map.md` · `docs/testing/testing-strategy.md` · `docs/technical-debt.md` · `docs/cleanup-candidates.md` · `docs/architecture/adr-index.md` · `specs/README.md`
