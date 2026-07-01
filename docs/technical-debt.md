# Technical Debt Audit — ClubScheduler (engine + backend)

Perimeter: **backend + engine only** (frontend excluded — slated for deletion + React rebuild). This is a whole-perimeter audit, not a diff. **Every item carries hard evidence** (file:line + proof). Items without proof are not listed. *"Unclear to a reader" is never evidence.* **No deletion or refactor has been performed** — this is analysis only.

Classification: 🟥 delete · 🟧 refactor · 🟦 document · 🟩 keep (listed when previously suspected but proven fine).

---

## Backend

### ✅ B1 — Rector aligned to PHP 8.4 — RESOLVED 2026-07-01
- **Was:** `backend/rector.php:9` `->withPhpVersion(80300)` vs `composer.json` `"php": ">=8.4"`.
- **Fix:** bumped to `->withPhpVersion(80400)`. Dry-run clean (161 files, no unexpected rewrite). The `backend/AGENTS.md` "intentional 8.3" note is now stale — to correct in that file.

### ✅ B2 — PHPUnit unified on `vendor/bin/phpunit` — RESOLVED 2026-07-01
- **Was:** three divergent, partly-broken PHPUnit paths — CI hardcoded `vendor/bin/.phpunit/phpunit-9.6-0/phpunit` (bridge absent → **path missing → CI failed before running any test**), `composer test` called the missing `vendor/bin/simple-phpunit`, `phpunit.xml.dist` schema pointed at `phpunit-11.5-0`.
- **Fix:** all aligned on the direct `vendor/bin/phpunit` (PHPUnit **11.5.55**, the `phpunit/phpunit ^11` dev-dep) — `.github/workflows/ci.yml` (6 lines), `composer.json` `test` script, and `phpunit.xml.dist` schema (`vendor/phpunit/phpunit/phpunit.xsd`). `symfony/phpunit-bridge` intentionally not reintroduced.
- **Side effect:** the now-functional CI exposed a stale `TeamTagServiceTest` (**fixed** in the same pass) and the deprecation / fixture debt below (B6/B7).

### 🟦 B3 — `TenantCacheIsolationTest` is a blocking job but skipped
- **Where:** `backend/tests/Security/TenantCacheIsolationTest.php` (~18 lines) — skipped with "Cache isolation test deferred to Phase 2"; still listed in CI `blocking-tests`.
- **Proof:** the test body skips; CI runs it as a gate.
- **Action:** implement the cache-isolation assertions (the `cache.tenant` pool exists) or remove it from `blocking-tests` so the gate reflects real coverage. Document the chosen path.

### 🟦 B4 — Duplicate Mercure publish logic across handlers (low)
- **Where:** `GenerateScheduleHandler::publishProgress` (~lines 362–376) and `ExportPdfHandler::publishProgress` (~lines 59–71).
- **Proof:** both build `sprintf('club:%s:schedule:%s', …)`, validate non-empty, and `hub->publish(new Update(...))`; payloads differ.
- **Action:** acceptable today (2 handlers, distinct payloads). Extract a small `MercureScheduleNotifier` only if a 3rd publisher appears. Documented, not urgent.

### 🟩 B5 — `DevScheduleReportWriter` excluded from autowiring (not debt)
- **Where:** `backend/config/services.yaml:22` excludes `../src/Service/DevScheduleReportWriter.php`.
- **Proof:** intentional dev-only tool; exclusion is by design. **Keep.**

> No dead controllers (all 7 are routed), no orphan entities/services, no `TODO/FIXME/HACK` in `backend/src/`.

### 🟦 B6 — 9 PHPUnit 11 deprecations in the test suite
- **Where:** whole backend suite. `php vendor/bin/phpunit tests/ --group phase1` reports `PHPUnit Deprecations: 9` (and the full suite reports 9 too).
- **Proof:** counter emitted by PHPUnit 11.5.55. Detail block is suppressed by the current `phpunit.xml.dist` (no `displayDetailsOnTestsThatTriggerDeprecations`); run `php vendor/bin/phpunit tests/ --display-deprecations` in the `php-fpm` container to enumerate. Typical PHPUnit 11 deprecations: doc-comment metadata instead of `#[Attributes]`, deprecated assertions.
- **Context:** surfaced only after B2 repaired the CI PHPUnit path — the previously-broken `phpunit-9.6-0` binary never ran, so these were invisible.
- **Action:** enable deprecation detail, migrate the flagged test code to PHPUnit 11 attributes/APIs. Own plan (engine-cleanup-analogue for backend tests). Low priority — non-blocking.

### 🟦 B7 — Fixture references removed system tag `LOISIR`
- **Where:** `backend/src/DataFixtures/BasketballInit.php:963` → `'targetTag' => 'LOISIR'`. Plain `LOISIR` no longer exists in the system tags (split into `LOISIR_ADULTE` / `LOISIR_JEUNE` by commit `fee099e`).
- **Proof:** `ScheduleConstraintBuilder::resolveTagToTeamIds` (`src/Service/ScheduleConstraintBuilder.php:524–528`) looks up the tag by name; unknown → logs `Tag 'LOISIR' not found … constraint will be ignored` and **silently drops that CLUB constraint** in the seeded demo data.
- **Action:** point the fixture at a real tag (`LOISIR_ADULTE` and/or `LOISIR_JEUNE`) or remove the constraint. Demo-data only, no prod impact. Own plan.

> Backend findings B6/B7 were discovered on 2026-07-01 while resolving B2 (CI repair exposing the real test state). The stale `TeamTagServiceTest` (expected 20 tags, wrong `LOISIR` assertion) found at the same time was **fixed** in that pass, not left as debt.

---

## Engine

### 🟥 E1 — Three redundant public aliases of `add_level_2_objective`
- **Where:** `engine/app/solver/objective.py:449–464` → `apply_level_2_objective`, `add_objective`, `set_level_2_objective` each just `return add_level_2_objective(*args, **kwargs)`; exported in `objective.py` `__all__` (728–731) and `solver/__init__.py`.
- **Proof:** grep shows **no internal caller** within `engine/`; they only widen the public surface.
- **Action:** delete (and trim `__all__` / `__init__` exports) after confirming no external import relies on them. See `cleanup-candidates.md`.

### 🟧 E2 — Six duplicated solver helpers across `constraints.py` and `objective.py`
- **Where:** `constraints.py` (~933–1224) and `objective.py` (~467–715): `_var`, `_team_id`, `_get`, `_scalar_id`, `_normalise_assignments`, `_assignment_from_mapping_item`, plus a separately-defined `_MISSING = object()` in each (`constraints.py:28`, `objective.py:170`).
- **Proof:** near-identical implementations with **subtle divergences** (e.g. `objective._team_id` also accepts `teamId`; `objective._var` accepts `literal`; the two `_MISSING` sentinels are distinct objects, so cross-module identity checks would silently differ).
- **Risk:** the divergences are the real hazard — a fix applied to one copy won't reach the other.
- **Action:** extract a single `engine/app/solver/helpers.py`, reconcile the field-alias handling deliberately, and import from both. Covered by existing unit tests.

### 🟦 E3 — Two-pass fallback strategy defined but never activated in production
- **Where:** `skip_rest_day_and_distribution` (`constraints.py:117`), `fallback_used` (`result_builder.py:33`); `main.py:125` passes `fallback_used=False` hardcoded and never sets `skip_rest_day_and_distribution=True` outside tests.
- **Proof:** only `tests/` exercise the fallback path; production solve always runs pass 1.
- **Action:** document *why* the fallback is dormant (intentional?) in a code comment, or wire it into the solve path. Decide — don't leave it ambiguous. Candidate ADR.

### 🟦 E4 — Misleading "unused" comment
- **Where:** `engine/app/solver/constraints.py:110` → `min_sessions_by_team: … = None,  # unused — kept for API compatibility`, but it **is** used at `constraints.py:889–890`.
- **Proof:** the parameter is iterated in `add_min_sessions_constraints()`.
- **Action:** remove the false comment.

### 🟦 E5 — Stale solver doc: timeout says 10s, real default is 650s
- **Where:** `engine/app/solver/AGENTS.md:26` → "Timeout — 10s hardcoded in `main.py`." Reality: `input_schema.py:134 solver_timeout_seconds = 650`, applied at `main.py:269` (`max_time_in_seconds`).
- **Proof:** code default is 650 and is payload-driven, not hardcoded to 10.
- **Action:** correct the nested `AGENTS.md` (and any 10s reference) via the `documentation-update` skill. *(Not edited here: onboarding is read-only over application/doc code; this is logged for a follow-up doc pass.)*

### 🟦 E6 — `TODO: PREFERRED TIME not implemented`
- **Where:** `engine/app/solver/objective.py:305` and `engine/app/solver/constraints.py:779`.
- **Proof:** explicit TODOs for an unimplemented feature, duplicated in two places.
- **Action:** track in the backlog (`specs/evolution/`) with an issue id; tie both sites to it.

> No dead modules, no obsolete files, no import cycles in `engine/`.

---

## Suggested priority
1. **E2** (divergent duplicate logic, correctness risk) → 2. **E3 / B3** (decisions to confirm + ADRs) → 3. **E1 / E4 / E5 / E6 / B4 / B7** (cleanups & doc/fixture fixes) → 4. **B6** (PHPUnit 11 deprecations, non-blocking). *(B1, B2 resolved 2026-07-01.)*

All actions above require an explicit, scoped plan before any change — none are pre-approved.
