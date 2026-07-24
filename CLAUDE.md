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

Backend, engine and frontend tooling run **inside Docker** (their Makefiles wrap Docker Compose).

```bash
make start | stop | install | test | lint        # root orchestration (docker compose, reads .env)
make bootstrap             # JWT keypair + create/migrate the dev DB — idempotent; `.installed` runs it
                           # on first install, so re-run it by hand after a pull adds migrations
cd backend && make test    # CS-Fixer + PHPStan(lvl8) + PHPUnit (--group phase1)
cd backend && make phpstan | cs-fix | rector | migration-diff | migration-migrate | jwt-keys | db-init
cd engine  && make test    # pytest + ruff + mypy   |  make format
make -C frontend dev        # Dockerized Vite :5173 (proxies /api + /.well-known/mercure — never /engine)
```

## 4. CI order (`.github/workflows/ci.yml`)

`{lint, phpstan} → blocking-tests → {unit-tests, e2e (Playwright, full stack + Vite)}` · the `phpstan` job runs **PHPStan *and* CS-Fixer** (`--dry-run`, since 2026-07-17 — it has the PHP container the `lint` job lacks; `lint` = docker-compose + Makefile only) · `engine-tests`, `frontend` (typecheck `tsc -b` + `vite build` + `vitest`) **and** `dependency-audit` (SEC A18 — `composer audit` / `npm audit --audit-level=high` / `pip-audit`, **blocking, no needs**, ne gate PAS build-docker) run in parallel from the start (no needs) · `build-docker` needs **[blocking-tests, engine-tests] only** — unit-tests, e2e, frontend and dependency-audit do NOT gate it · `engine-perf` gate runs on main only.

**blocking-tests** (must pass first, all `--group phase1`): `Security/TenantIsolationTest`, `Security/SeasonIsolationTest` (multi-season scoping + X-Season-Id validation), `Security/SeasonReadonlyTest` (archived-season writes → 409), `Security/MatchTenantIsolationTest` (match entities tenant+season scoped), `Security/TenantCacheIsolationTest`, `Queue/ConcurrentGenerationTest`, `CrossStack/ContractSchemaTest`, `Security/RlsIsolationTest` (RLS enforced at the DB), `Security/{ClubAccessTest,UserSelfOnlyTest,ImportAuthorizationTest}` (SEC-01/02/04 tenant-API lockdown), `Security/MercureHardeningTest` (SEC-05/06), `Security/ManagementRoleTest` (SEC-07 management-role gate on cockpit writes), `Security/ApiRateLimitTest` (SEC-11 per-user API throttle), `Security/SuperAdminAccessTest` (SA0 MFA/session/CSRF/admin-DB boundary), `Security/EngagedTeamGuardTest` (périmètre engagé : une équipe qui joue ne peut être ni supprimée ni changer de niveau), `Security/PeriodPlanBirthTest` (ADR-0002 amendé 2026-07-24 : le plan naît du geste **d'Adapter** — `POST /schedule_plans` ou semaine cochée au picker ; matérialiser une période ne crée rien ; la découpe supprime le plan-bloc ; `cutoff`/`mutualisation` n'en portent jamais ; l'identité d'une période à plan OU à semaines est gelée). Detail: `docs/testing/testing-strategy.md`.

## 5. Conventions (essentials)

- **Backend:** PHPStan level 8 (Doctrine+Symfony ext) · CS-Fixer `@Symfony` + `@PHP84Migration` + risky + Yoda + strict comparisons · Rector targets PHP **8.4** (aligned with composer `>=8.4`) · PHPUnit runs directly via `vendor/bin/phpunit` (PHPUnit 11, the `phpunit/phpunit` dev-dep) — same binary in CI, `Makefile`, and `composer test`.
- **Engine:** ruff (line 120, py312, double quotes, LF) · mypy `strict` + `pydantic.mypy` plugin (`ortools.*` ignored) · pytest (`-ra`) + golden fixtures + invariants + hypothesis.

## 6. Critical mechanisms

- **Multi-tenant isolation** (backend): 3 layers. (1) Doctrine `TenantFilter` + `TenantFilterListener` (**priority 7, AFTER the firewall**) resolves club from `_club_id` / `X-Club-Id` / else the **authenticated JWT user's active `ClubUser` membership** (the frontend sends no header); spoofed header → 403. The listener also resolves the **season** (`X-Season-Id` validated → 403 if foreign/unknown; else calendar-derived current via `SeasonResolver`, July-15 pivot) and enables the `season_filter` (intra-club correctness boundary — see `backend/docs/TENANT.md`). (2) **PostgreSQL RLS ACTIVE**: runtime = `app_user`, `FORCE` policies on all `club_id` tables keyed on the `app.club_id` GUC (`TenantConnectionContext`; workers set it from the message `clubId`); migrations/ops = `clubscheduler` via the Doctrine `admin` connection (**superadmin door, bypasses RLS**). (3) Club/User (no `club_id`) scoped in their providers/processors. See `backend/docs/TENANT.md` + `docs/security/rls.md`. ⚠️ Listener before auth = historical cross-club leak (fixed — never move it back). Guarded by `TenantIsolationTest`, `TenantJwtIsolationTest`, `RlsIsolationTest`, `OnboardingFlowTest`.
- **Superadmin SA0:** separate global identity (never `User`/`ClubUser`), stateful firewall `/api/admin/**`, password + mandatory TOTP, per-IP throttle, session-CSRF, and fail-closed access audit over the Doctrine `admin` connection. A club JWT never crosses this firewall and the admin session never sets `app.club_id`. Current contract: `specs/courantes/superadmin-auth.md`; UI/metrics remain in `specs/evolution/console-superadmin.md`.
- **Concurrency**: backend `ClubGenerationLock` (Redis `SETEX NX` + release token); engine per-club `asyncio.Lock`. Guarded by `ConcurrentGenerationTest`.
- **Async generation**: `GenerateScheduleController` → `GenerateScheduleMessage` → `GenerateScheduleHandler` (frozen snapshot → POST engine → import results → Mercure publish). Symfony Messenger over Redis, `messenger-worker` container.
- **Backend↔engine contract**: engine Pydantic schemas ⇄ backend payload; version in `engine/CONTRACT_VERSION`. **No codegen — synced manually.** Guarded by `ContractSchemaTest`.
- **FFBB API integration** (lot C, outbound): at club creation `AuthController::verifyEmail` dispatches `PopulateClubFromFfbbMessage` (async) → `FfbbClubPopulator` fills the club + shared `FfbbLeague`/`FfbbCommittee` reference rows (no `club_id`, outside RLS, cache-first) from the public FFBB Meilisearch API, and rehosts logos. **Confined & SSRF-safe**: the two hosts (`api.ffbb.com`, `meilisearch-prod.ffbb.app`) are hard-coded in `FfbbApiClient`/`FfbbLogoFetcher` (never derived from input), the club code is format-validated, redirects disabled; best-effort (failure never breaks register). Same service backs the management-gated `POST /api/club/ffbb-import`. Routes catalogued in `backend/docs/ffbb-api.md`. The frontend never calls FFBB.
- **Solver**: CP-SAT, **no relaxation fallback** (all HARD constraints in every attempt; the objective is optimised in two lexicographic phases — placement then chaining, phase 2 capped at 10 s). Phase-1 budget = **adaptive tiers 60/180/600 s** by problem size (`n_teams×n_venues` ≤50/≤200/else), with the payload `solver_timeout_seconds` (default 650) as a **ceiling only** — never the actual budget. **`num_search_workers` is also adaptive** (`_adaptive_workers`): ≤200 complexity → 1 (deterministic, golden fixtures depend on it), else → 8 (the single worker FINDS the optimum in ~2 s on dense soft-preference problems but can't PROVE it — stalled 612 s on BCCL; the 8-worker portfolio closes the proof in ~2 s, same objective, at the cost of a non-deterministic *assignment* — the *value* stays stable). Seed from `solver_seed`. INFEASIBLE → `status="failed"` + diagnostics (see `docs/architecture/adr-0001-single-pass-solve.md`, amended 2026-07-07).

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
5. **Tests green locally before proposing merge** — run `/validation-runner` (selects the changed zone's targeted suite + cross-zone contract test + the mandatory smoke-solver when engine/backend is touched, and justifies any suite it could not run); it must be green on blocking tests + the new NR tests + zone suite. CI is a double-check and does NOT block the merge.
6. Change summary + **doc check (mandatory):** either run `documentation-update`, or state explicitly "no doc impacted because …". Never skip silently.
7. **`/code-review` on every PR** (+ `/security-review` if the change touches auth/data/external integrations).
8. PR → **user's explicit go** → merge.

### 7.1 Structuring axes (closed list — NR test required when touched)

tenant isolation (filter/listener/voters) · generation pipeline (controller→messenger→engine→import→Mercure) · **constraint semantics** (a constraint entered in the UI must be honored by the solver — semantic smoke, not just COMPLETED) · planning lifecycle (le plan SEASON pointé = le calendrier de la saison ; valider/rouvrir + verrous d'édition — ADR-0002) · **périmètre engagé** (une équipe qui joue en compétition : ni suppression ni changement de niveau — ses matchs sont déposés à la fédération) · backend↔engine contract (schemas/CONTRACT_VERSION) · auth & memberships (register/login/approval/roles). Extending this list = user decision.

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

1. Backend, engine and frontend tooling run in Docker; the host only needs Docker, Docker Compose and Make.
2. PHPUnit = `vendor/bin/phpunit` (PHPUnit 11) everywhere (CI, `Makefile`, `composer test`). ⚠️ **`make phpunit` ne lance QUE `--group phase1`** (le gate bloquant, ~492 tests) et **`make test` que la testsuite `Unit`** — or le job CI `unit-tests` lance **`phpunit tests/`, le dossier entier (~718 tests)**. Les testsuites déclarées ne couvrent pas `Api`, `Command`, `Double`, `EventListener`, `MessageHandler`, `OpenApi`, `Validator` : **valider en local avec `make phpunit` seul laisse ces 7 dossiers hors de vue** (lot C2 : 2 échecs y ont dormi jusqu'à la CI). **Avant de pousser, `make -C backend tests-complete`** — miroir exact de la CI. La suite needs the test DB — `make db-init-test` first (CI brings it up via `docker compose up -d --wait`).
3. `contracts/` and the top-level `tests/` dir are empty placeholders (cross-stack tests live in `backend/tests/`).
4. Frontend is rebuilt + **active** — indexed by the graph (only its build artifacts `dist`/`node_modules`/`storybook-static` are ignored). Tenant is resolved server-side from the JWT: the frontend sends **no** `X-Club-Id` header.

**Pointers:** `docs/project-map.md` · `docs/glossary.md` (termes & clés de payload) · `docs/testing/testing-strategy.md` · `specs/evolution/roadmap.md` (suivi unique : carte + backlog priorisé + dette) · `docs/cleanup-candidates.md` · `docs/architecture/adr-index.md` · `specs/README.md` · commandes backend : `backend/docs/commands.md` · routes FFBB : `backend/docs/ffbb-api.md`

<!-- rtk-instructions v2 -->
# RTK (Rust Token Killer) - Token-Optimized Commands

## Golden Rule

**Always prefix commands with `rtk`**. If RTK has a dedicated filter, it uses it. If not, it passes through unchanged. This means RTK is always safe to use.

**Important**: Even in command chains with `&&`, use `rtk`:
```bash
# ❌ Wrong
git add . && git commit -m "msg" && git push

# ✅ Correct
rtk git add . && rtk git commit -m "msg" && rtk git push
```

## RTK Commands by Workflow

### Build & Compile (80-90% savings)
```bash
rtk cargo build         # Cargo build output
rtk cargo check         # Cargo check output
rtk cargo clippy        # Clippy warnings grouped by file (80%)
rtk tsc                 # TypeScript errors grouped by file/code (83%)
rtk lint                # ESLint/Biome violations grouped (84%)
rtk prettier --check    # Files needing format only (70%)
rtk next build          # Next.js build with route metrics (87%)
```

### Test (60-99% savings)
```bash
rtk cargo test          # Cargo test failures only (90%)
rtk go test             # Go test failures only (90%)
rtk jest                # Jest failures only (99.5%)
rtk vitest              # Vitest failures only (99.5%)
rtk playwright test     # Playwright failures only (94%)
rtk pytest              # Python test failures only (90%)
rtk rake test           # Ruby test failures only (90%)
rtk rspec               # RSpec test failures only (60%)
rtk test <cmd>          # Generic test wrapper - failures only
```

### Git (59-80% savings)
```bash
rtk git status          # Compact status
rtk git log             # Compact log (works with all git flags)
rtk git diff            # Compact diff (80%)
rtk git show            # Compact show (80%)
rtk git add             # Ultra-compact confirmations (59%)
rtk git commit          # Ultra-compact confirmations (59%)
rtk git push            # Ultra-compact confirmations
rtk git pull            # Ultra-compact confirmations
rtk git branch          # Compact branch list
rtk git fetch           # Compact fetch
rtk git stash           # Compact stash
rtk git worktree        # Compact worktree
```

Note: Git passthrough works for ALL subcommands, even those not explicitly listed.

### GitHub (26-87% savings)
```bash
rtk gh pr view <num>    # Compact PR view (87%)
rtk gh pr checks        # Compact PR checks (79%)
rtk gh run list         # Compact workflow runs (82%)
rtk gh issue list       # Compact issue list (80%)
rtk gh api              # Compact API responses (26%)
```

### JavaScript/TypeScript Tooling (70-90% savings)
```bash
rtk pnpm list           # Compact dependency tree (70%)
rtk pnpm outdated       # Compact outdated packages (80%)
rtk pnpm install        # Compact install output (90%)
rtk npm run <script>    # Compact npm script output
rtk npx <cmd>           # Compact npx command output
rtk prisma              # Prisma without ASCII art (88%)
```

### Files & Search (60-75% savings)
```bash
rtk ls <path>           # Tree format, compact (65%)
rtk read <file>         # Code reading with filtering (60%)
rtk grep <pattern>      # Search grouped by file (75%). Format flags (-c, -l, -L, -o, -Z) run raw.
rtk find <pattern>      # Find grouped by directory (70%)
```

### Analysis & Debug (70-90% savings)
```bash
rtk err <cmd>           # Filter errors only from any command
rtk log <file>          # Deduplicated logs with counts
rtk json <file>         # JSON structure without values
rtk deps                # Dependency overview
rtk env                 # Environment variables compact
rtk summary <cmd>       # Smart summary of command output
rtk diff                # Ultra-compact diffs
```

### Infrastructure (85% savings)
```bash
rtk docker ps           # Compact container list
rtk docker images       # Compact image list
rtk docker logs <c>     # Deduplicated logs
rtk kubectl get         # Compact resource list
rtk kubectl logs        # Deduplicated pod logs
```

### Network (65-70% savings)
```bash
rtk curl <url>          # Compact HTTP responses (70%)
rtk wget <url>          # Compact download output (65%)
```

### Meta Commands
```bash
rtk gain                # View token savings statistics
rtk gain --history      # View command history with savings
rtk discover            # Analyze Claude Code sessions for missed RTK usage
rtk proxy <cmd>         # Run command without filtering (for debugging)
rtk init                # Add RTK instructions to CLAUDE.md
rtk init --global       # Add RTK to ~/.claude/CLAUDE.md
```

## Token Savings Overview

| Category | Commands | Typical Savings |
|----------|----------|-----------------|
| Tests | vitest, playwright, cargo test | 90-99% |
| Build | next, tsc, lint, prettier | 70-87% |
| Git | status, log, diff, add, commit | 59-80% |
| GitHub | gh pr, gh run, gh issue | 26-87% |
| Package Managers | pnpm, npm, npx | 70-90% |
| Files | ls, read, grep, find | 60-75% |
| Infrastructure | docker, kubectl | 85% |
| Network | curl, wget | 65-70% |

Overall average: **60-90% token reduction** on common development operations.
<!-- /rtk-instructions -->