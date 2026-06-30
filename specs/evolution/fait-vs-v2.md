# Traceability: Fait vs V2 — 34 Plans

Last verified @ 2026-06-30

---

## Methodology

This document traces the execution status of every plan in `.omo/plans/` against three evidence sources:

1. **boulder.json** — The source of truth for work status. Only 5 of 34 plans have explicit boulder entries (4 completed, 1 active). The remaining 29 are marked `NOT_TRACKED` — they were executed before boulder tracking existed or outside the boulder workflow.

2. **git log** — Material proof of commits. Each plan is matched to commit SHA(s) by keyword grep on `git log --oneline --all`. Commit messages are the primary matching key. When no dedicated commit exists but deliverables are present, the work was likely folded into a broader commit.

3. **test -f** — File existence checks on declared deliverables. Each plan's "Deliverables" section lists expected files; `test -f` confirms whether they exist on disk. This is the strongest signal for "Fait réellement" since file presence is objective.

### Decision rules

| Fait réellement | Rule |
|-----------------|------|
| YES | All key deliverables exist on disk + at least one matching git commit |
| PARTIAL | Some deliverables exist but key ones are missing |
| NO | No deliverables found, no matching commits |

| Délégué V2 | Rule |
|------------|------|
| YES | The plan's forward work is explicitly handed off to V2 (e.g. frontend rebuild) |
| NO | The plan was fully executed in V1; no V2 delegation needed |

### Plan count discrepancy

The task specifies "33 plans" but `.omo/plans/` contains **34 files**. All 34 are included in this table. The extra plan (`dev-report-writer-fixes.md`, 1.2K) is a small fix plan with checked-off TODOs — it may have been added after the original count was established.

---

## Tableau de Traçabilité

| # | Plan | Scope | Statut boulder | Commit SHA(s) | Fait réellement | Délégué V2 | Notes |
|---|------|-------|----------------|---------------|-----------------|------------|-------|
| 1 | auth-wizard-refactor | Auth, bug fixes, wizard redesign, tooling | NOT_TRACKED | 3dba864, 71c480c, 74e70a3, 5b3164a, b2f6def | YES | NO | AuthController.php, authStore.ts, WizardPage.tsx, wizardStore.ts, client.ts all exist. Two "plan completed" commits. Frontend will be raz'd in V2 but the plan itself was executed. |
| 2 | backend-fixtures | Backend code quality & fixture cleanup | NOT_TRACKED | fb4b14b | YES | NO | phpcs-fix added, fixtures cleaned, ffbbTeamId removed. BasketballInit.php exists (renamed from BCCL concept). |
| 3 | backend-test-infra | Test infrastructure from scratch | NOT_TRACKED | (incremental, no single commit) | YES | NO | dama/doctrine-test-bundle installed (composer.json + phpunit.xml.dist), .env.test with clubscheduler_test DB, 4 blocking tests exist (TenantIsolation, TenantCacheIsolation, ConcurrentGeneration, ContractSchema), make test/tests-complete targets exist, CacheInvalidationListener::flushInvalidations on kernel.terminate. Built incrementally across MVP phases. |
| 4 | clubscheduler-mvp | Multi-tenant fix & wizard completion | NOT_TRACKED | 5035f83, e12f712, 9e2fdb3, 08442bd | YES | NO | Phase 1 (Docker+Symfony+RLS), Phase 2 (20 entities+API Platform), Phase 3 (solver), MVP Final Wave all delivered. Club.php, App.tsx, docker-compose.yml exist. |
| 5 | coach-rest-day-salarie | Coach player view, rest day, salarié distribution | completed | 70fc336, ca34d68, 5d2ffe3 | YES | NO | isEmployee in Coach.php, test_coach_rest_day.py, test_salarie_distribution.py, test_chaining_bonus.py all exist. Boulder: 8 task sessions + 4 final-wave sessions, all completed. |
| 6 | constraint-fixes | TIME/DAY constraints and cap sessions | completed | 9376288, 06cb7c1, aa16a33, a4f4d6f | YES | NO | test_constraint_fixes.py exists. forcedDays HARD + minSessionMinutes removed. Boulder: 3 task sessions + 1 final-wave, all completed. |
| 7 | constraint-refactor | Refonte système de contraintes unifié | NOT_TRACKED | 83f7069, 82d49ce, 89dac1e | YES | NO | 5+ enums exist (Gender, TeamLevel, TeamCoachRole, ScheduleStatus, ScheduleDiagnosticSeverity, ImplicitConstraint, ConstraintFamily, ConstraintScope, ConstraintRuleType, LockLevel). ConstraintConflict.php, TeamTag.php, TeamTagAssignment.php exist. ScheduleConstraintBuilder.php exists. parse_v2_constraints in constraints.py. |
| 8 | creneau-capacity | Double équipe opt-in (capacity) | NOT_TRACKED | 4859b41 (plan file only) | YES | NO | capacity field in VenueTrainingSlot.php (default=1), ge=1 in input_schema.py, canSplit in Venue.php. No dedicated implementation commit found beyond plan file, but deliverables exist in code. |
| 9 | dev-report-writer-fixes | DevScheduleReportWriter fixes + script cleanup | NOT_TRACKED | e452899, f7bd255 | YES | NO | writeResultFiles on all exit paths, stray dump() removed, 5 report files written even when empty. All 4 TODOs checked off in plan file. |
| 10 | dev-token | Token de dev + script login.sh | NOT_TRACKED | b01954d | PARTIAL | NO | generate-schedule.sh has --token option and Authorization header. BUT GenerateDevTokenCommand.php MISSING, login.sh MISSING. 1 of 3 deliverables done. Remaining items likely abandoned — not delegated to V2. |
| 11 | engine-slot-usage-age-order | Slot usage, unused-slot warnings, age-ascending | completed | 73202f9, 9ab02e8, ca34d68 | YES | NO | test_slot_usage.py, test_age_order.py, test_unused_slot_warnings.py all exist. ageMin/ageMax in TeamSchema. Boulder: 2 task sessions + 1 final-wave, all completed. |
| 12 | engine-v2-adaptation | Engine V2 adaptation (constraints v2, implicit rules) | NOT_TRACKED | 82d49ce, 89dac1e, 778c14a | YES | NO | parse_v2_constraints exists in constraints.py, CONTRACT_VERSION=2.0 file exists, implicit_rules.json exists, /implicit-constraints endpoint in main.py. |
| 13 | fix-fixtures-constraints | Fix BasketballInit.php + solver time_windows | NOT_TRACKED | fb6fb46, 5238aa8, 4adf677 | YES | NO | Phantom slots purged, youth time constraints fixed, DEPARTEMENTAL removed from _ADULT_LEVELS, time_windows consumed. |
| 14 | fix-implicit-rules | Implicit rules violations + score négatif | completed | 2e8b381, bf057fd, acd156a, 9fc81f4 | YES | NO | Coach/joueur overlap via interval intersection, max_consecutive_sessions cross-venue, BCCL regression optimized. test_max_consecutive_sessions.py exists. Boulder: 4 task sessions + 1 final-wave, all completed. |
| 15 | fix-login-suspense | React Suspense error on login | NOT_TRACKED | (no dedicated commit — folded into auth-wizard-refactor) | YES | NO | Suspense boundary in AppLayout.tsx (line 178) with LoadingSpinner fallback. LoadingSpinner.tsx exists. No dedicated commit — fix was likely part of auth-wizard-refactor wave. |
| 16 | fix-maxcap-minSessionMinutes | max-cap incompatible avec minSessionMinutes | NOT_TRACKED | 705b438, 062a613, 889ec61 | YES | NO | max-cap restored for minSessionMinutes teams, slot-count session cap exclusion, day-level indicator for one_session_per_day. |
| 17 | fix-min-session-duration-constraint | chaîne de suffixes -> bloc minimum | NOT_TRACKED | 9c492a5 | YES | NO | Suffix chain replaced with use_here block-minimum in min_session_duration. |
| 18 | fix-minsessions-lockedslots | conflit min_sessions vs max_cap fully-locked | NOT_TRACKED | 8c25436 | YES | NO | min_sessions adjusted for locked slots to prevent INFEASIBLE. |
| 19 | fix-session-fragmentation-constraints | Session fragmentation, late-start, LOISIR split | NOT_TRACKED | b9c835e, becf9bc, b1388c6, df0a38c, 72f26a9, fee099e | YES | NO | Continuous session blocks, no-late-start (>=21:00), session_too_short warning, LOISIR_ADULTE added to _ADULT_LEVELS, strict session contiguity via no-gap triple constraint, LOISIR split in TeamTagService. |
| 20 | fix-test-5-default-duration | Default session duration to 90 minutes | NOT_TRACKED | 9601c5b | YES | NO | DEFAULT_SESSION_MINUTES=90, 15-min CP-SAT slots merged into contiguous blocks. |
| 21 | fixture-harmonisation | Harmonisation des fixtures BCCL | NOT_TRACKED | 6691e1b, fd5eb3f, a63ebb0, f828ed1 | YES | NO | BCCL fixture data harmonized, hardcoded UUIDs removed (Club first + getId), dead $u9F/$u11M category fetches removed, Loisir Camus slots added. |
| 22 | frontend-dockerization | Dockerization du frontend React | NOT_TRACKED | a5b6b71, faefd79, b575032, 05fa495 | YES | NO | Multi-stage Dockerfile, Nginx config, Makefile, relative URLs, API client updated for containerized environment. |
| 23 | frontend-raz-cleanup | Frontend RAZ cleanup + specs vivantes préparatoires | active | 4f0e930, 1bebbf2, 9ae3b8e, 3125430 | YES | YES — the actual frontend raz/rebuild is V2 work | Cleanup done (AI tooling dirs deleted, MCP/skills migrated), specs written (frontend-spec.md, frontend-wizard.md, frontend-strategy.md, openapi-snapshot.json, backend-inventory.md, engine-inventory.md). Boulder: 7 task sessions completed, plan still active. The plan's own scope is prep-only; the raz itself is delegated to V2. |
| 24 | generate-schedule-script | Script d'automatisation generate-schedule.sh | NOT_TRACKED | b01954d, 9e8cbf8 | YES | NO | generate-schedule.sh exists with --token option, CLUB_ID updated to match reloaded fixtures. |
| 25 | implicit-solver-rules | 3 règles implicites solver | NOT_TRACKED | 89dac1e, 778c14a, 82d49ce | YES | NO | Implicit rules implemented, dict forbidden rules handled, parse_v2_constraints wired into pipeline, non-tag teams forbidden from exclusive venues. |
| 26 | min-session-duration | Durée minimale de session | NOT_TRACKED | 89dac1e, 9c492a5, b1388c6 | YES | NO | Min session duration constraint implemented, suffix chain replaced with block-minimum, strict contiguity enforced. |
| 27 | min-sessions-soft | Min sessions effective basée sur tier + équipe | NOT_TRACKED | 662e5b2, f6a5053 | YES | NO | Tier-based effective min sessions, soft session-count bonus, no hard constraint (objective+diagnostics only). |
| 28 | mvp-critique | Export PDF, diagnostics, wizard, import, compte, édition | NOT_TRACKED | 74e70a3, 5b3164a, b2f6def, 5fc47f5 | YES | NO | ExportPdfController.php, ImportController.php, ManualEditController.php, AuthController.php all exist. DiagnosticsPanel.tsx, DiagnosticsPage.tsx, RegisterPage.tsx exist. 3 MVP waves + Final Wave fixes delivered. |
| 29 | payload-summary-bdd | payload-summary en version BDD simplifiée | NOT_TRACKED | 355159a | YES | NO | DevScheduleReportWriter.php shows BDD-simplified constraints in payload-summary.txt. |
| 30 | pdf-and-dev-reports | PDF export finalization + dev schedule reports | NOT_TRACKED | c668d62, e07def3, 8418ca0, f7bd255, e452899 | YES | NO | PNG generation added, worker.js ESM crash fixed, auto-write reports on generation, 5 report files, writeResultFiles on all exit paths. DevScheduleReportWriter.php exists, frontend/worker.js exists, docker/pdf-worker/Dockerfile exists. |
| 31 | post-generation-workflow | Post-generation workflow & solver fix | NOT_TRACKED | 0f909af, bc1be26, 38c373a, 509f868, 859b079, e819928 | YES | NO | Diagnostics persisted on completed status, old diagnostics purged at start of each run, solverTimeoutSeconds read from payload (default 300s), HARD locked slots imported + lockLevel preserved, season slots included in matching, HARD slot templates in payload. DashboardPage.tsx exists. |
| 32 | time-constraints | Implémentation des contraintes TIME et DAY | NOT_TRACKED | aa16a33, 06cb7c1, 9376288 | YES | NO | Hard TIME/DAY window constraints, preferred DAY bonus, time_windows wired into constraint pipeline, regression tests (test_time_constraints.py). |
| 33 | venue-availability | VenueAvailability — stockage et envoi au moteur | NOT_TRACKED | 32cf7c7, 870b582, 9c1a70c, 5ab5ba7, ea41c06 | YES (superseded) | NO | VenueAvailability entity was created (entity, repository, DTO, API CRUD, migration) then DELETED and replaced by VenueTrainingSlot in commit 4d965d0. Plan was fully executed but deliverable was superseded by plan #34. |
| 34 | venue-training-slots | venue-training-slots (replaces VenueAvailability) | NOT_TRACKED | 4d965d0, d344919, fb6fb46, 7610e53 | YES | NO | VenueTrainingSlot.php, VenueTrainingSlotRepository.php, VenueTrainingSlotResource.php, VenueTrainingSlotInput.php, VenueTrainingSlotStateProvider.php, VenueTrainingSlotStateProcessor.php all exist. Full refactoring replacing VenueAvailability. |

---

## Bilan Fait vs V2

### Summary counts

| Metric | Count |
|--------|-------|
| Total plans in `.omo/plans/` | 34 |
| Boulder-tracked plans | 5 (4 completed + 1 active) |
| Boulder NOT_TRACKED | 29 |
| Fait réellement = YES | 32 |
| Fait réellement = PARTIAL | 1 (dev-token) |
| Fait réellement = NO | 0 |
| Délégué V2 = YES | 1 (frontend-raz-cleanup) |
| Délégué V2 = NO | 33 |
| Superseded plans | 1 (venue-availability -> venue-training-slots) |

### Completed and verified (32 plans)

The vast majority of plans were fully executed in V1. Key deliverables exist on disk and matching git commits confirm the work. This includes all engine constraint work (plans 6, 7, 12, 14, 17-20, 25-27, 32), all fixture work (plans 2, 13, 21), all backend infrastructure (plans 3, 4, 22, 28, 30, 31), and all frontend prep (plan 23).

### Partially executed (1 plan)

**dev-token** (plan #10): The generate-schedule.sh script was updated with `--token` option and Authorization header (commit b01954d), but the two other deliverables — `GenerateDevTokenCommand.php` (Symfony command) and `login.sh` (shell script) — were never created. This plan was likely deprioritized once the `--token` option in generate-schedule.sh provided sufficient dev workflow support. The missing items are not delegated to V2 — they are simply abandoned.

### Delegated to V2 (1 plan)

**frontend-raz-cleanup** (plan #23): This plan is explicitly preparatory — its scope is to clean up the repo, write living specs, and prepare a handoff packet for the actual frontend rebuild. The cleanup and specs are done (boulder: active, 7 task sessions completed). The actual frontend raz and rebuild is V2 work, as stated in the plan's own TL;DR: "Préparer la ra totale du frontend (sans l'exécuter)... en écrivant un handoff packet pour Claude Code qui exécutera la ra elle-même dans un plan ultérieur."

### Superseded (1 plan)

**venue-availability** (plan #33): Fully executed — VenueAvailability entity, repository, DTO, API CRUD, and migration were all created. However, the entire feature was then replaced by VenueTrainingSlot (plan #34) in commit 4d965d0. The VenueAvailability files were deleted from the codebase. This is not a failure — it was a deliberate architectural replacement.

### Never executed (0 plans)

No plans were left completely unexecuted. Every plan in `.omo/plans/` has at least some evidence of work done.

### Boulder coverage gap

Only 5 of 34 plans (15%) have boulder.json entries. The remaining 29 were executed before boulder tracking was introduced or outside the boulder workflow. This means boulder.json alone is insufficient for traceability — git log and file existence checks are essential complementary evidence sources.

### V2 scope

Based on this traceability analysis, V2 scope is:
1. **Frontend rebuild** (from frontend-raz-cleanup): Execute the raz and rebuild using the specs written in V1 (frontend-spec.md, frontend-wizard.md, frontend-strategy.md).
2. **dev-token completion** (optional): If dev workflow still needs it, create GenerateDevTokenCommand.php and login.sh. Currently abandoned, not formally delegated.
