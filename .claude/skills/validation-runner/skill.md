---
name: Validation Runner
description: After an implementation, runs the targeted tests for the changed zone PLUS the cross-zone integration/contract tests, and explicitly justifies any test that could not be run. Its value over a plain `make test` is choosing the right targeted + cross-zone tests and reporting non-runnable ones. Invoke manually.
---

## Validation Runner

Run **only when the user asks**, after an implementation that stayed within a validated plan's scope.

### Why this exists (vs a bare `make test`)
It does not blindly run everything. It (a) selects the **targeted** tests for the zone that actually changed, (b) adds the **cross-zone** integration/contract tests when a boundary is touched, and (c) **justifies** every test it could not run instead of silently skipping.

### Steps
1. **Detect changed zone(s)** — engine / backend / frontend — from the diff (use `detect_changes` from `code-review-graph` if helpful).
2. **Targeted tests for the changed zone:**
   - **Backend:** `cd backend && make test` (lint + phpunit `--group phase1`). For security/contract-touching changes, also run the blocking tests: `tests/Security/TenantIsolationTest.php`, `tests/Security/TenantCacheIsolationTest.php`, `tests/Queue/ConcurrentGenerationTest.php`, `tests/CrossStack/ContractSchemaTest.php`.
   - **Engine:** `cd engine && make test` (pytest + ruff + mypy).
3. **Cross-zone tests** — when a change touches the backend↔engine contract (Pydantic schemas ⇄ API Platform resources), run `ContractSchemaTest` — it is the guardrail for the manually-synced contract.
4. **Report** — pass/fail per suite, plus an explicit justification for any suite that could not run (stack not up, missing service, etc.).
5. **Solver smoke-test — MANDATORY when the change touches `engine/` or `backend/`.** Run `backend/scripts/smoke-solver.sh`. It drives the real end-to-end path (create schedule → trigger generation → poll) and **asserts a schedule reaches `COMPLETED`**. Diagnostics/warnings in the result are acceptable — the pass criterion is that the CP-SAT solver responded and a plan was produced. The script is self-sufficient (mints a dev JWT via `lexik:jwt:generate-token`, ensures the JWT keypair + dev fixtures + a consuming `messenger-worker`, and recovers from a stale-upstream nginx 502). ⚠️ `generate-schedule-test.sh` is a *mock* unit test of the wrapper (fake `curl`) — it does NOT exercise the solver; never use it as the smoke-test.

### Rules
- Backend and engine tests run **inside Docker** (their Makefiles wrap `docker compose exec`). Ensure the stack is up (`make start`) or report clearly that it is not — do not pretend a skipped suite passed.
- Do not invent test paths. The PHPUnit binary is `vendor/bin/phpunit` (PHPUnit 11), wired into `make phpunit` (which includes `--group phase1`). The suite needs the test DB — run `make db-init-test` first if it is not set up.
- `blocking-tests` must pass before the rest of the PHP suite is meaningful (CI order).
- The solver smoke-test needs async generation to run: `smoke-solver.sh` starts/restarts `messenger-worker` itself (a queued message left unconsumed keeps the schedule `PENDING`).
