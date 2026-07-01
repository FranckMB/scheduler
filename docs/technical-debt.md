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

### ✅ B3 — `TenantCacheIsolationTest` implemented — RESOLVED 2026-07-01
- **Was:** the blocking `phase1` test body just `markTestSkipped('… deferred to Phase 2')` — a no-op gate giving false coverage on tenant cache isolation.
- **Fix:** implemented two real unit tests against `CacheInvalidationListener` with `ArrayAdapter` pools: (1) modifying a club A entity purges only club A's `club.{id}.{schedule_input,tenant_data,schedule_snapshot}` keys in both the tenant and schedule pools while club B's keys survive (cross-tenant isolation); (2) an entity with no resolvable club id purges nothing. No `src/` change.
- **Verified:** 2 tests / 13 assertions green, CS-Fixer + PHPStan (level 8) clean.

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

### ✅ B7 — Fixture LOISIR tag — RESOLVED 2026-07-01
- **Was:** `backend/src/DataFixtures/BasketballInit.php` "De Barros Annexe - Préféré loisir" used a hardcoded `'targetTag' => 'LOISIR'`; plain `LOISIR` no longer exists (split into `LOISIR_ADULTE` / `LOISIR_JEUNE` by `fee099e`), so `ScheduleConstraintBuilder::resolveTagToTeamIds` logged `Tag 'LOISIR' not found … constraint will be ignored` and silently dropped it.
- **Fix:** replaced the single constraint with a loop over `TeamLevel::LOISIR_ADULTE` / `TeamLevel::LOISIR_JEUNE`, using `$loisirLevel->value` as the tag (the **enum**, not a hardcoded string — the root cause of the drift). Two constraints now created, one per loisir level.
- **Verified:** fixtures reload OK, CS-Fixer + PHPStan clean, `smoke-solver.sh` → COMPLETED, and the `Tag 'LOISIR' not found` warning is gone.
- **Follow-up (out of B7 scope):** six other `targetTag` strings in the fixture are still hardcoded (`EMB`, `FEMININE`, `REGIONAL`, `DEPARTEMENTAL`, `SENIOR`, `JEUNE`) — all currently valid tags, but the level-based ones could likewise use the `TeamLevel` enum to prevent future drift.

> Backend findings B6/B7 were discovered on 2026-07-01 while resolving B2 (CI repair exposing the real test state). The stale `TeamTagServiceTest` (expected 20 tags, wrong `LOISIR` assertion) found at the same time was **fixed** in that pass, not left as debt.

---

## Engine

### ✅ E1 — Redundant `add_level_2_objective` aliases removed — RESOLVED 2026-07-01
- **Was:** `apply_level_2_objective`, `add_objective`, `set_level_2_objective` (pure pass-throughs) in `objective.py`, exported via its `__all__` and (`apply_*`) `solver/__init__.py`; no internal or test caller.
- **Fix:** deleted the three defs and trimmed the `__all__` / `solver/__init__.py` exports. `make -C engine lint` clean + 138 pytest passed after removal.

### ✅ E2 — Solver helpers de-duplicated into `helpers.py` — RESOLVED 2026-07-01
- **Was:** `constraints.py` and `objective.py` each carried near-identical `_get`, `_scalar_id`, `_var`, `_team_id` (+ a distinct `_MISSING` sentinel), with **subtle divergences** — the real hazard was a fix landing in only one copy. Key divergence: `objective._get` skipped `None` values (treated as absent) while `constraints._get` returned them; field-name/tuple-index lists also differed (`literal`, `teamId`, `x`, `priority_tier*`).
- **Fix:** extracted `engine/app/solver/helpers.py` (`MISSING`, `get_field`, `scalar_id`, `assignment_var`, `assignment_team_id`). The `None`-skip divergence is encoded as an explicit `get_field(..., skip_none=...)` param — `constraints.py` delegates with `skip_none=False`, `objective.py` with `skip_none=True` — so **each caller's exact behaviour is preserved**. Field lists and the tuple-index map are the union of both (a strict superset per caller). The shared `MISSING` sentinel also fixes the cross-module identity hazard.
- **Deliberately NOT merged:** `_normalise_assignments` / `_assignment_from_mapping_item` have **different return contracts** (constraints → `AssignmentVariable` with schedule-slot-key detection; objective → plain `dict`) — kept per-module (documented in `helpers.py`).
- **Verified:** `make -C engine lint` clean (ruff+mypy+bandit), 138 pytest passed, `smoke-solver.sh` → COMPLETED with the **same score 9051** (behaviour unchanged end-to-end).

### ✅ E3 — Two-pass fallback: single-pass decision formalized — RESOLVED 2026-07-01 (see ADR-0001)
_The dormant two-pass fallback is an intentional, documented single-pass design; captured in [`architecture/adr-0001-single-pass-solve.md`](architecture/adr-0001-single-pass-solve.md) + a code pointer in `app/main.py`. The `skip_rest_day_and_distribution` / `fallback_used` parameters are kept as a tested opt-in extension point. Original finding below._

### 🟦 E3 (original) — Two-pass fallback strategy defined but never activated in production
- **Where:** `skip_rest_day_and_distribution` (`constraints.py:117`), `fallback_used` (`result_builder.py:33`); `main.py:125` passes `fallback_used=False` hardcoded and never sets `skip_rest_day_and_distribution=True` outside tests.
- **Proof:** only `tests/` exercise the fallback path; production solve always runs pass 1.
- **Action:** document *why* the fallback is dormant (intentional?) in a code comment, or wire it into the solve path. Decide — don't leave it ambiguous. Candidate ADR.

### ✅ E4 — Misleading "unused" comment removed — RESOLVED 2026-07-01
- **Was:** `constraints.py` `min_sessions_by_team` carried `# unused — kept for API compatibility` though it **is** used in `add_min_sessions_constraints()`.
- **Fix:** removed the false comment.

### ✅ E5 — Stale solver-timeout doc corrected — RESOLVED 2026-07-01
- **Was:** `engine/app/solver/AGENTS.md` said "Timeout — 10s hardcoded in `main.py`"; the real default is payload-driven `solver_timeout_seconds` = 650s.
- **Fix:** corrected the line (650s, payload-driven, `max_time_in_seconds`, points to ADR-0001).

### ✅ E6 — PREFERRED TIME TODOs moved to backlog — RESOLVED 2026-07-01
- **Was:** two `# TODO: PREFERRED TIME not implemented` in `objective.py` + `constraints.py`.
- **Fix:** replaced both with a pointer to a new backlog entry in `specs/evolution/features-futures.md` ("Backlog — PREFERRED TIME"), which lists both code sites.

> No dead modules, no obsolete files, no import cycles in `engine/`.

---

## Suggested priority
1. **B4** (low — extract a Mercure notifier only if a 3rd publisher appears) → 2. **B6** (PHPUnit 11 deprecations, non-blocking). *(B1, B2, B3, B7, E1, E2, E3, E4, E5, E6 resolved 2026-07-01.)*

All actions above require an explicit, scoped plan before any change — none are pre-approved.
