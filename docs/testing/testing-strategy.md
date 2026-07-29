# Testing Strategy — ClubScheduler

Scope: backend + engine. The rebuilt frontend has its own tests (Vitest + RTL unit/integration with `vi.mock`, Playwright e2e in `frontend/tests/e2e`, and the container screenshot pipelines). Companion to [`/CLAUDE.md`](../../CLAUDE.md) §4 and [`../project-map.md`](../project-map.md).

---

## 1. CI pipeline (`.github/workflows/ci.yml`)

Order and dependencies:

```
lint ──┐
       ├─► blocking-tests ──► {unit-tests, e2e}
phpstan┘
engine-tests ───────────────────────────────────► build-docker
blocking-tests ─────────────────────────────────┘
frontend            (lint + tsc -b + vite build + vitest) — parallel, no needs, does NOT gate build-docker
dependency-audit    (composer/npm/pip audit, A18)          — parallel, no needs, does NOT gate build-docker
rector              (dry-run, style gate P4-24)            — parallel, no needs, gates NOTHING… but BLOCKS the merge
engine-perf         (dense solve < 60 s)                   — main only
```

**Trois jobs isolés sans `needs`** (`rector`, `dependency-audit`, `frontend`) : un signal qui peut
rougir sur un commit qui n'a rien changé (une règle Rector élargie par un bump, une advisory publiée
ce matin) ne doit pas prendre en otage `blocking-tests` — donc l'isolation tenant/RLS — ni
`build-docker`, donc la livraison d'un correctif de sécurité. **Rougir ≠ ne rien bloquer** : `rector`
et `dependency-audit` sont des **required status checks** de `main`, ils bloquent le merge sans gater
aucun job. Même raison pour laquelle `SymfonyStackAlignmentTest` tourne dans `unit-tests` et non dans
le gate bloquant.

All PHP test jobs first **create + migrate the test DB** (`doctrine:database:create --if-not-exists` + `migrations:migrate`, `--env=test`) and run phpunit with `-e APP_ENV=test` on the `docker compose exec` — the containers default to `APP_ENV=dev` (root `.env` env_file) and `phpunit.xml.dist`'s `<server APP_ENV=test>` is not `force`d, so the real env var must be set explicitly.

| Job | What it runs |
|-----|--------------|
| `lint` | `docker compose config` + `make -n help` |
| `phpstan` (job name: **PHPStan & CS-Fixer**) | `composer phpstan` (level 8) **+ `composer cs-fix -- --dry-run --diff`** — needs postgres + redis. CS-Fixer vit ici, et non dans `lint`, parce que ce job a déjà le conteneur PHP que `lint` n'a pas (jusqu'au 2026-07-17 CS-Fixer ne tournait **nulle part** en CI, et `main` a été mergée rouge dessus deux fois) |
| `rector` (**Rector (style gate)**) | `composer rector -- --dry-run` (P4-24). Job **dédié, sans `needs`**, dépendance d'aucun autre — mais le contexte « Rector (style gate) » fait partie des **required status checks de `main`** (depuis le 2026-07-27), donc **il bloque le merge**. Corriger en local : `docker compose exec php-fpm sh -c 'cd /app/backend && composer rector'` (`make -C backend rector` est un dry-run : il montre, il ne fixe pas) |
| `blocking-tests` | the `--group phase1` security/queue/contract tests — **gate for the rest of the PHP suite**. `Unit/Entity/UserInterfaceContractTest` (axe auth — `eraseCredentials` présente sur `User` ET `SuperAdmin` ; sans lui dans le gate, une re-suppression passe tous les checks requis), `TenantIsolationTest`, `SeasonIsolationTest`, `SeasonReadonlyTest`, `MatchTenantIsolationTest`, `TenantCacheIsolationTest`, `ConcurrentGenerationTest`, `ContractSchemaTest`, `RlsIsolationTest`, `ClubAccessTest`/`UserSelfOnlyTest`/`ImportAuthorizationTest` (SEC-01/02/04), `MercureHardeningTest` (SEC-05/06), `ManagementRoleTest` (SEC-07), `ApiRateLimitTest` (SEC-11), `SuperAdminAccessTest` (SA0), `EngagedTeamGuardTest` (périmètre engagé), `PeriodPlanBirthTest` (ADR-0002) — **`CLAUDE.md` §4 est la liste canonique** (ne pas figer un compte ici, il dérive) |
| `unit-tests` | full PHPUnit `tests/` (does NOT gate build-docker) |
| `e2e` | Playwright (full stack + Vite), needs blocking-tests |
| `engine-tests` | `pytest` + `ruff check .` + `mypy` (in the engine container) |
| `frontend` | `npm run lint` (dont `eslint-plugin-jsx-a11y`, §4bis) + `tsc -b` + `vite build` + `vitest` (parallel, no needs) |
| `dependency-audit` | `composer audit` / `npm audit --audit-level=high` / `pip-audit` (A18, blocking, parallel, no needs) |
| `build-docker` | `docker compose build` (needs **blocking + engine** tests only) |

All PHP jobs invoke `vendor/bin/phpunit` (PHPUnit 11, the direct `phpunit/phpunit` dep) — same binary as `Makefile` and `composer test`.

---

## 2. Backend tests (`backend/tests/`)

Layout: `Unit/` (Entity, Enum, Service — no DB) · `Integration/Api/` · `Security/` · `Queue/` · `CrossStack/` — **et sept dossiers HORS des testsuites déclarées** : `Api/`, `Command/`, `Double/`, `EventListener/`, `MessageHandler/`, `OpenApi/`, `Validator/`.

⚠️ **Le piège** : `phpunit.xml.dist` ne déclare que trois testsuites (`Unit`, `Integration`, `Contract`), or le job CI `unit-tests` lance **`phpunit tests/`, le dossier entier**. Valider en local avec `make -C backend test` (testsuite `Unit` seule) ou `make -C backend phpunit` (`--group phase1` seul) **laisse ces sept dossiers hors de vue** — deux échecs y ont dormi jusqu'à la CI. **Avant de pousser : `make -C backend tests-complete`**, miroir exact de la CI.

Groups (PHP attributes): `#[Group('phase1')]`, `#[Group('integration')]`, `#[Group('contract')]`, `#[Group('unit')]`. Test isolation via DAMA DoctrineTestBundle; bootstrap `tests/bootstrap.php`.

### Blocking guardrails (`phase1`)
| Test | Asserts |
|------|---------|
| `Security/TenantIsolationTest` | 403 on another club's data · 200 on own club · 403 when membership inactive · 200 with no `X-Club-Id` |
| `Security/TenantCacheIsolationTest` | Implemented (B3, resolved 2026-07-01) — 2 real tests: cache invalidation isolates clubs; entity without `club_id` purges nothing. |
| `Queue/ConcurrentGenerationTest` | 2nd `ClubGenerationLock` acquire for same club fails · different clubs acquire concurrently · wrong token cannot release |
| `CrossStack/ContractSchemaTest` (`phase1`+`contract`) | engine payload shape valid (version, clubId, seasonId, teams, venues, coaches, constraints, trainingSlots, sportCategoryId, scopeTargetId…) · POSTs to the real engine when reachable, else skips |
| `Security/SuperAdminAccessTest` | club JWT rejected · password without TOTP rejected · MFA session isolated from tenants · disabled/expired admin rejected · logout protected by CSRF · IP rate limit · runtime DB role has no admin-table privilege |

`ContractSchemaTest` is the **only** guardrail for the manually-synced backend↔engine contract (no codegen). Any change to engine Pydantic schemas or the backend payload must keep it green.

---

## 3. Engine tests (`engine/tests/`)

- **Unit by feature/constraint:** `test_constraints.py`, `test_objective.py`, `test_result_builder.py`, `test_coach_rest_day.py`, `test_salarie_distribution.py`, `test_max_consecutive_sessions.py`, `test_age_order.py`, `test_chaining_bonus.py`, `test_engine.py` (endpoints), …
- **Golden / integration** (`tests/golden/`): full solves on real club fixtures (`simple_club.json`, `dense_club.json`, `bccl_regression.json`, …) with expected outputs; `test_two_pass.py` guards the **single-pass invariant** (ADR-0001) — the dormant relaxation fallback is NOT wired into production, so the test pins its absence.
- **Invariants** (`tests/invariants/test_invariants.py`): post-solve checks — no team/coach overlaps, venue capacity respected, hard locks honored.
- **Fixtures** (`tests/fixtures/`): JSON club configs (simple, medium, dense, bccl_regression, overlap_*, no_rest_*, vacation_week, impossible, score_hard_only_teams…) — `ls engine/tests/fixtures/` for the current set.
- Property-based tests via hypothesis; `pytest-timeout` guards runaway solves.

Run: `cd engine && make test` (pytest + ruff + mypy, inside the engine container).

---

## 4. How to run locally

```bash
make start                                   # bring the stack up first (tests need postgres/redis/engine)
cd backend && make test                      # PHPStan + CS-Fixer + PHPUnit --testsuite Unit (PAS le gate phase1)
cd backend && make phpunit                   # PHPUnit --group phase1 (le gate bloquant)
cd backend && make tests-complete            # phpstan + cs + phpunit tests/ (miroir CI complet — à passer avant push)
cd engine  && make test                      # pytest + ruff + mypy
```

Backend & engine tests run **inside Docker** — running `phpunit`/`pytest` on the host will fail. If the stack is down, `ContractSchemaTest` and other integration tests skip or fail rather than silently passing.

**Frontend e2e (Playwright)** self-heal the stack: a `globalSetup` (`frontend/tests/e2e/global-setup.ts`) runs `docker compose up -d --wait` before any test — it starts any stopped service (a dead `messenger-worker`/`engine` was the recurring flake: the generation never completes → the planning never appears) and blocks until every healthcheck passes. No-op when already healthy; skipped when `E2E_BASE_URL` targets an externally managed stack.

---

## 4bis. Frontend accessibility guardrail (WCAG 2.2 AA)

Two layers, added as **tests** so any frontend change is checked against the norm:

- **Static lint (blocking)** — `eslint-plugin-jsx-a11y` (recommended set) runs inside `npm run lint` (CI `frontend` job) at **`error`**. A single knob `A11Y_LEVEL` in `eslint.config.js` drives warn-vs-block (kept `error` now that the known violations are fixed; flip to `warn` only to temporarily unblock a large refactor). The remapping preserves each rule's tuned options and never re-enables the rules recommended disables. `label-has-associated-control` is told our custom control components (`Input`/`Select`/`TeamSelect`). The few intentional `autoFocus` uses (modal step fields, revealed rename/search inputs) carry a justified inline disable.
- **Structural axe** — `vitest-axe` asserts `toHaveNoViolations()` on the shared primitives (`src/test/a11y.test.tsx`, via `expectNoA11yViolations()` in `src/test/utils.tsx`) and the Modal (focus into panel on open, Escape close, focus restoration WCAG 2.4.3). Component-specific a11y lives in each component's own test where the fixtures already are: `MonthCalendar.test.tsx` (info emojis expose a text alternative, A11Y-05) and `WeekGrid.test.tsx` (venue named as text in every view, not colour only, A11Y-01). jsdom has no layout engine, so axe **skips colour-contrast (WCAG 1.4.3)** — that axis (A11Y-06) is a **follow-up** Playwright/axe pass in a real browser.

Shared modal a11y is one hook — `useModalA11y` (`src/shared/lib/useModalA11y.ts`): focus-trap + initial focus + focus restoration + Escape, applied to both `Modal` and `ConfirmDialog` (the audit's A11Y-03 / FRT-12/13 / UXC-02 came from per-modal divergent handling).

Matcher wiring: runtime `expect.extend` in `src/test/setup.ts`; the vitest-v3 type augmentation is `src/test/vitest-axe.d.ts` (vitest-axe ships only a stale global `Vi.Assertion`).

## 5. Known testing gaps
- **A11Y-06 — le contraste de couleur (WCAG 1.4.3) n'est vérifié par AUCUN test** : jsdom n'a pas de moteur de layout, donc axe **saute la règle** (cf. §4bis). La passe Playwright/axe en vrai navigateur reste à faire — jusque-là, un contraste insuffisant passe la CI.
- **La portée des policies RLS n'est pas gardée** : `RlsIsolationTest` vérifie qu'une table à `club_id` a bien `ENABLE`/`FORCE` et **au moins une** policy — pas ce que cette policy autorise. Une policy `USING (true)` posée par erreur passerait le test. Les exceptions volontaires (SELECT ouvert) doivent donc être justifiées dans `docs/security/rls.md`, qui fait office de garde-fou humain.
- *Résolus* : `TenantCacheIsolationTest` est implémenté (B3) et les 9 dépréciations de doc-comments PHPUnit 11 sont passées en attributs (B6) — 2026-07-01 (historique : git log de `docs/technical-debt.md`, absorbé dans `specs/evolution/roadmap.md` §Dette le 2026-07-11).
