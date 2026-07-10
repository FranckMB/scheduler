# Technical Debt Audit — ClubScheduler (engine + backend)

Perimeter: originally **backend + engine only** (this audit predates the React frontend rebuild). The frontend is now active; deferred **frontend** debt surfaced by the `/audit` editions is tracked in the [Frontend](#frontend) section below. This is a whole-perimeter audit, not a diff. **Every item carries hard evidence** (file:line + proof). Items without proof are not listed. *"Unclear to a reader" is never evidence.* **No deletion or refactor has been performed** — this is analysis only.

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

### ✅ B6 — PHPUnit 11 doc-comment metadata → attributes — RESOLVED 2026-07-01
- **Was:** 9 "Metadata found in doc-comment" deprecations (visible via `--display-phpunit-deprecations`) — 9 `tests/Unit/` classes declared `@group unit` in a class doc-comment, deprecated and removed in PHPUnit 12.
- **Fix:** converted all 9 to the `#[Group('unit')]` attribute (+ `use PHPUnit\Framework\Attributes\Group;`). `--display-phpunit-deprecations` now reports **0**; `--group unit` still selects them (55 tests); full suite 87 tests / 361 assertions green.
- **Follow-up (done 2026-07-01):** `tests/` is not in the CI PHPStan scope (`phpstan.neon` analyses `src` only), but the 12 pre-existing `phpstan analyse tests/Unit` issues were fixed too — mock intersection type on `TeamTagServiceTest::$entityManager`, `EntityRepository<T>` generics in the `ScheduleConstraintBuilder*` tests, and a helper `@return list<string>`. `phpstan analyse tests/Unit` is now clean.

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
- **Fix:** replaced both with a pointer to a backlog entry ("Backlog — PREFERRED TIME"). *(That backlog file, `features-futures.md`, was since absorbed into `specs/evolution/roadmap.md`; PREFERRED TIME itself was delivered 2026-07-03, ENGINE series.)*

> No dead modules, no obsolete files, no import cycles in `engine/`.

---

## Frontend

Deferred items from the `/audit` editions (`specs/audit/AUDIT-*.md`), by explicit decision, after the UX/a11y remediation batch landed the score-moving fixes.

### 🟧 FE1 — UXS-03: three wizard-step components over 400 lines (medium)
`frontend/src/features/wizard/steps/TeamsStep.tsx` (552), `ConstraintsStep.tsx` (498), `VenuesStep.tsx` (413). Each mixes list rendering, inline sub-forms/modals and mutation wiring in one file — high cognitive load, harder review. **Deferred** (user decision): a pure refactor with real regression risk on the most complex components, and lower priority than the pre-GA P0s (observability / backups / RGPD / prod config). *Example split:* extract each step's inline editor/modal (e.g. `TeamRow`/`TeamEditor`, `ColorField` + `SlotEditor` in Venues) into sibling components, leaving the step as list + orchestration. Do it in its own PR with `/code-review`; each extracted piece keeps its existing tests.

### 🟦 FE2 — Register divergence wizard (tu) ↔ cockpit (vous) (low)
The wizard tutoie the manager (dominant voice: ~29 tu / 15 vous), the cockpit/validation flows vouvoie ("Vous pourrez le rouvrir…"). UXC-08 unified **GenerateStep internally to tu** (wizard voice); the broader wizard↔cockpit split is a product-voice decision left open. Pick one register app-wide before GA and sweep. Proof: `PlanningPage.tsx` validate modal (vous) vs `features/wizard/**` (tu).

### 🟩 FE3 — UXC-05 residual amber NOT migrated to the `--warning` token (kept, deliberate)
`MonthCalendar.tsx` keeps hand-picked `amber-700`/`amber-300` for the school-holiday label + `amber-400/15` wash rather than `text-warning`/`bg-warning`. Measured: `--warning` in light mode is 2.9:1 on background (A11Y-06) — using it for the holiday *label text* would FAIL WCAG AA, whereas `amber-700` passes. Migrating would trade a cohérence residual for an a11y regression, so it stays. `app/DevClock.tsx` amber is dev-only tooling (never shipped to managers) — out of scope.

---

## RGPD / données personnelles

### 🟦 DP1 — Fiche club FFBB : contacts président/correspondant (lot B, minimisé)
`PATCH /api/club/info` (lot B) stocke sur `club` des **contacts professionnels** issus de la fiche publique FFBB : nom, téléphone, email du **président** et du **correspondant**. Décision de minimisation (utilisateur) : **aucune adresse de domicile** — seules l'adresse du club et de la salle principale (lieux publics) sont persistées. Ces champs restent des **données personnelles** : à couvrir par la politique de purge/rétention et l'export/suppression sur demande, au même titre que les emails d'adhérents, quand le socle RGPD pré-GA (purge, audit trail, droit à l'effacement) sera implémenté. Pas d'action isolée : à traiter dans le lot RGPD global. Preuve : `Entity/Club.php` (colonnes `president_*`/`correspondent_*`), `Controller/ClubInfoController.php`.

---

## Suggested priority
Only **B4** remains — a deliberate defer (`🟩` keep): extract a shared Mercure notifier only if a 3rd publisher appears; no action now. *(B1, B2, B3, B6, B7, E1, E2, E3, E4, E5, E6 all resolved 2026-07-01.)*

All actions above require an explicit, scoped plan before any change — none are pre-approved.
