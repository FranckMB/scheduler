# Handoff to Claude Code — Frontend Rebuild Entry Point

Last verified @ 2026-06-30

> **THIS IS THE SINGLE DOCUMENT Claude Code reads first when starting the
> frontend rebuild.** It points to every spec, fixes every version, states every
> constraint, and warns about every trap. Read it top to bottom before touching
> any file.

---

## Start Here — 7 Spec Paths

All seven documents below are required reading. They are listed in priority
order — read them in sequence.

| # | Document | Path | Role |
|---|----------|------|------|
| 1 | Frontend Strategy | `specs/courantes/frontend-strategy.md` | TDD mandate, stack versions fixed, anti-patterns banned, infrastructure reuse rules |
| 2 | Frontend Spec | `specs/courantes/frontend-spec.md` | Forward spec: routes, state management, HTTP client, Mercure SSE, TanStack Query, Zustand, API contract |
| 3 | Frontend Components | `specs/courantes/frontend-components.md` | Forward spec: pages (Login, Register, ScheduleView, Diagnostics, TierList), shared components, layouts, ARIA, lock levels, reference day anchoring |
| 4 | Frontend Wizard | `specs/courantes/frontend-wizard.md` | Forward spec: 4-step onboarding wizard "Draft hybride", state machine, auto-save, ARIA, test cases |
| 5 | OpenAPI Snapshot | `specs/courantes/openapi-snapshot.json` | Frozen API contract — 40 paths, 20 API Platform resources, 2 custom controllers. Source of truth for endpoint paths, schemas, response shapes |
| 6 | RAZ vs Rebuild | `specs/evolution/raz-vs-rebuild.md` | Open debate: Option A (RAZ pur), B (rebuild selectif), C (hybride). **No option is pre-selected.** Claude Code must read this and decide at execution time |
| 7 | Backend Gaps | `specs/evolution/backend-gaps.md` | 7 gaps (G1-G7) between frontend specs and backend reality. Blockers, workarounds, and delegated backend actions |

### What Claude Code Should Read First — Numbered Priority List

1. **`specs/courantes/frontend-strategy.md`** — Read FIRST. Sets the TDD mandate, fixes the stack versions, bans anti-patterns, defines raz scope and infrastructure preservation. Everything else follows from here.
2. **`specs/courantes/frontend-spec.md`** — Read SECOND. The forward spec for the entire frontend: 10 routes, state management strategy, HTTP client config, Mercure SSE, TanStack Query conventions, Zustand stores, API contract consumption.
3. **`specs/courantes/frontend-components.md`** — Read THIRD. Page-by-page and component-by-component spec outside the wizard: Login, Register, ScheduleView, Diagnostics, TierList, shared UI, layouts, ARIA, lock levels, reference day anchoring.
4. **`specs/courantes/frontend-wizard.md`** — Read FOURTH. The 4-step onboarding wizard spec: Infrastructure, Ressources, Contraintes, Recapitulatif. State machine, auto-save, ARIA, test cases. **Do NOT reopen the wizard decision.**
5. **`specs/courantes/openapi-snapshot.json`** — Read FIFTH. The frozen API contract. Use it to verify every endpoint path, schema, and response shape. **Paths use snake_case** (see backend-gaps.md G6).
6. **`specs/evolution/raz-vs-rebuild.md`** — Read SIXTH. The open debate between RAZ pur, rebuild selectif, and hybride. **No option is pre-selected in this handoff.** Claude Code must evaluate the criteria and confirm its choice before starting.
7. **`specs/evolution/backend-gaps.md`** — Read SEVENTH. The 7 gaps between frontend specs and backend reality. Know which endpoints are missing, which shapes are unstable, and which features have no backend support.

---

## Stack Versions Fixed

The following versions are **frozen** for the entire rebuild. No major or minor
version bump without explicit decision and full re-verification (tests + tsc +
build).

| Package | Version | Role |
|---------|---------|------|
| react | 19.2 | Framework UI — `createRoot` mandatory, no `ReactDOM.render` |
| react-dom | 19.2 | DOM rendering |
| vite | 8.1 | Build tool / dev server — `@tailwindcss/vite` plugin |
| typescript | ~6.0 | Typing — `strict: true`, `noUncheckedIndexedAccess: true`, `exactOptionalPropertyTypes: true` |
| tailwindcss | 4.3 | CSS utility-first — CSS `@theme` config, NO `tailwind.config.js` |
| @tanstack/react-query | 5.100 | Server state — no `onSuccess` (removed in v5) |
| zustand | 5.0 | Client state — `migrate()` requires null check |
| ky | 2.0 | HTTP client — fetch-based, hooks API |
| @fullcalendar/core | 6.1 | Calendar — `timeGridWeek`, lun-sam, `firstDay: 1` |
| @dnd-kit/core | 0.5 | Drag & drop — accessible, keyboard navigable |

### Testing stack (also frozen)

| Package | Version | Role |
|---------|---------|------|
| vitest | 3.2 | Test runner |
| @testing-library/react | 16.1 | Component rendering + DOM queries |
| @testing-library/user-event | 14.6 | Interaction simulation |
| jsdom | 26.0 | DOM environment |
| msw | 2.8 | HTTP network mock |
| Playwright | latest | E2E tests (4 existing specs in `frontend/tests/e2e/`) |

### Locking rules

1. `package.json` must use exact versions (no `^` or `~` for the packages above) via `npm install --save-exact`.
2. `package-lock.json` is the source of truth.
3. Any version change = dedicated commit + full test re-run + `tsc --noEmit` + `npm run build`.

> Reference: `specs/courantes/frontend-strategy.md` §2.

---

## Wizard Decision — Draft Hybride 4 Steps

The onboarding wizard is a **"Draft hybride" 4-step** design. This decision is
**FIXED and CLOSED**. Do NOT reopen the debate.

| Step | Name | Content |
|------|------|---------|
| 1 | Infrastructure | Venues + availability slots + closures |
| 2 | Ressources | Teams + coaches + Excel import (column mapping, paste-rows) |
| 3 | Contraintes | Permanent constraints + tier list drag & drop (S/A/B/C/D) |
| 4 | Recapitulatif | Global review + Zod validation + submit (`onboardingCompleted: true`) |

### Why 4 and not 6

The original v3 spec had 6 steps. Steps for Club, Salles, and Coaches were too
fragmented. The gestionnaire enters salles and coaches in the same
"Infrastructure" / "Ressources" session. Priorities (tier list) are scheduling
constraints, not onboarding data — they go in step 3.

### Do NOT reopen

> The wizard decision was made in T12 and confirmed across T11, T13, T14, T18,
> and T19. The 4-step "Draft hybride" pattern is binding. Any attempt to
> redesign the wizard structure (6 steps, free-form, stepper strict) is out of
> scope for the rebuild plan.

> Reference: `specs/courantes/frontend-wizard.md` §1, `specs/courantes/frontend-spec.md` §6.1.

---

## RAZ Scope Definition

The raz (reset to zero) applies to **frontend source code only**. Infrastructure
files are preserved in ALL options (A, B, and C).

### Raz applies to (SOURCE)

- `frontend/src/` — all components, hooks, stores, routes, styles, types, utils
  (74 files: 5 shared + 58 features + others)

### Raz does NOT apply to (PRESERVE)

| File | Role | Action |
|------|------|--------|
| `docker/frontend/Dockerfile` | Docker image (multi-stage Node 20 -> Nginx 1.27) | **PRESERVE as-is** |
| `docker/frontend/nginx.conf` | Nginx config (proxy `/api` -> backend, `/.well-known/mercure` -> hub, `/engine` -> engine, SPA fallback, security headers, asset cache) | **PRESERVE as-is** |
| `frontend/package.json` | Dependency manifest | **PRESERVE base** — update versions per stack table above, do not raz |
| `frontend/Makefile` | Docker helpers (start, stop, logs, shell) | **PRESERVE as-is** |
| `frontend/vite.config.ts` | Vite config + dev proxy (`/api` -> localhost:8080, `/.well-known/mercure` -> localhost:3000, `/engine` -> localhost:8000) | **PRESERVE** — reuse or adapt proxy config |
| `frontend/AGENTS.md` | Frontend agent context | **PRESERVE as-is** |
| `frontend/index.html` | HTML entry point | **PRESERVE or adapt** |
| `frontend/tsconfig*.json` | TypeScript config | **Adapt if needed** |
| `frontend/package-lock.json` | Lockfile | **Regenerate** after package.json update |

### Absolute rule

> `docker/frontend/Dockerfile` and `docker/frontend/nginx.conf` are NEVER
> deleted, in any option. These files represent validated deployment
> infrastructure, not application source code. Any raz operation must
> explicitly exclude them.

> Reference: `specs/courantes/frontend-strategy.md` §4, `specs/evolution/raz-vs-rebuild.md` "Distinction Infra-vs-Source".

---

## Constraints

### Do NOT touch backend or engine source

- **`backend/` source is OFF-LIMITS.** No modifications to PHP code, entities,
  migrations, controllers, or config.
- **`engine/` source is OFF-LIMITS.** No modifications to Python code, schemas,
  solver, or config.
- The rebuild is frontend-only. Backend and engine are consumed via their
  existing APIs.

### Contracts LOCKED via openapi-snapshot.json

- The OpenAPI snapshot at `specs/courantes/openapi-snapshot.json` is the
  **frozen API contract**. It was captured at backend SHA `6e35a6ce` on
  2026-06-30 (168 KB, 40 paths, 20 API Platform resources, 2 custom controllers).
- The frontend must consume endpoints exactly as documented in the snapshot.
- **Paths use snake_case** for API Platform resources (e.g.,
  `/api/priority_tiers`, `/api/schedule_diagnostics`,
  `/api/schedule_slot_templates`). Custom controllers use kebab-case
  (`/api/schedules/{id}/generate`, `/api/schedules/{id}/export-pdf`). See
  `backend-gaps.md` G6 for the full discrepancy table.

### Escalate gaps, do NOT modify backend

When the frontend spec requires an endpoint or field that does not exist in the
backend:

1. **Check `specs/evolution/backend-gaps.md`** — the gap may already be
   documented (G1 through G7).
2. **Do NOT modify backend code** to fill the gap.
3. **Implement the frontend with a workaround** if possible:
   - G1 (draft endpoint): use `sessionStorage` fallback only for wizard
     auto-save.
   - G3 (venue closures): disable the closures UI until backend support exists.
   - G4 (`/api/register`, `/api/me`): use the endpoints per
     `backend-inventory.md` §3 — they work but are not in the OpenAPI snapshot.
   - G5 (manual-edit sub-routes): use `PUT /api/schedule_slot_templates/{id}`
     with `lockLevel`, `temporaryLock`, `temporaryLockFor` fields instead.
   - G6 (snake_case vs kebab-case): use the actual OpenAPI paths (snake_case)
     in the ky client.
   - G7 (`onboardingCompleted`): the field IS present in the OpenAPI
     (`Club.onboardingCompleted: boolean`, camelCase). Use
     `PUT /api/clubs/{id}` with `{ onboardingCompleted: true }`.
4. **Document any new gap** discovered during the rebuild in
   `specs/evolution/backend-gaps.md` — do not silently work around it.

---

## TDD Directive — MANDATORY

**TDD is MANDATORY for the frontend rebuild plan. Write tests FIRST, watch them
fail (RED), implement minimal to pass (GREEN), then refactor (REFACTOR).**

No exceptions. Every component, hook, store, route, and API integration must
follow the RED -> GREEN -> REFACTOR cycle before being considered deliverable.

### Cycle

| Step | Action | Exit criterion |
|------|--------|----------------|
| **RED** | Write the unit/integration test BEFORE any implementation. Run it — it must fail for the right reason (missing assertion, missing import, type error). | Console output shows the expected failure, not an unrelated compilation error. |
| **GREEN** | Implement the minimal code to pass the test. No untested defensive code. no unrequested features. | All tests in the cycle pass (exit 0). |
| **REFACTOR** | Improve structure (extraction, renaming, typing) without changing behavior. Re-run tests after each refactor. | Tests still green after refactor. |

### Required test scope

- **UI components**: render tests (React Testing Library) — props, states, ARIA.
- **Custom hooks**: `renderHook` + lifecycle scenarios.
- **Zustand stores**: state, actions, `persist`/`migrate`.
- **TanStack Query**: test with `QueryClient`, mock `ky`, verify
  `useQuery`/`useMutation` + error handling.
- **Routes**: navigation tests (React Router memory router), auth guards,
  redirects.
- **API integration**: mock `ky` via MSW, verify payloads and headers.

### Test tools (versions fixed in Stack Versions Fixed above)

- **Vitest 3.2** + **@testing-library/react 16.1** for unit/integration tests.
- **MSW 2.8** for HTTP network mocking.
- **Playwright** for E2E tests (4 existing specs in `frontend/tests/e2e/` —
  assess reusability based on chosen raz option).

### Anti-patterns banned (from frontend-strategy.md §3)

1. `ReactDOM.render(...)` — use `createRoot(container).render(...)`.
2. `onSuccess` in `useQuery`/`useMutation` (TanStack Query v5) — use `useEffect`
   on `data`/`isSuccess`, or `select`.
3. `migrate()` without null check in Zustand 5 — check
   `if (persistedState === null) return initialState`.
4. `@apply` in Tailwind v4 components — use utility classes directly or extract
   a reusable React component.
5. `eslint-config-prettier` not last in `extends` — always last.
6. `tailwind.config.js` — use CSS `@theme { ... }` in `src/index.css` instead.

> Reference: `specs/courantes/frontend-strategy.md` §1 and §3.

---

## Mercure SSE Integration Warning

The frontend consumes Mercure Server-Sent Events for real-time schedule
generation and PDF export status updates.

### Critical rules

1. **Use native `EventSource`** — the browser auto-reconnects. Do NOT add a
   library for reconnection.
2. **Topic format**: `club:{clubId}:schedule:{scheduleId}` — always use this
   exact format.
3. **URL**: `/.well-known/mercure?topic=club:{clubId}:schedule:{scheduleId}` —
   relative URL, proxied by Vite dev server (-> localhost:3000) or Nginx in
   production. **NEVER hardcode `localhost:3000`** or any absolute Mercure hub
   URL.
4. **One EventSource per active schedule** — close on component unmount
   (`es.close()` in the `useEffect` cleanup).
5. **Invalidate React Query on SSE event** — the SSE handler calls
   `queryClient.invalidateQueries({ queryKey: ['schedules', scheduleId] })` and
   `queryClient.invalidateQueries({ queryKey: ['schedule_slot_templates', scheduleId] })`.
   Do NOT mutate cache directly except for the real-time status field
   (`queryClient.setQueryData(['schedule-status', scheduleId], data)`).
6. **No polling fallback** — if SSE disconnects, the user waits for
   auto-reconnect. The `generating` spinner stays visible.

### Expected SSE events

| Event | Source | Frontend action |
|-------|--------|-----------------|
| `{ status: 'queued' }` | `GenerateScheduleHandler` | Show "En file d'attente" spinner |
| `{ status: 'generating' }` | `GenerateScheduleHandler` | Show "Generation en cours" spinner |
| `{ status: 'done', score, unplaced, warnings }` | `GenerateScheduleHandler` | Invalidate queries, re-fetch schedule + slots, show report |
| `{ status: 'failed' }` | `GenerateScheduleHandler` | Show error + link to diagnostics |
| `{ status: 'pdf_ready', url }` | `ExportPdfHandler` | Enable PDF download button |

### Known pitfall from V1 code

The V1 `ScheduleViewPage` had a hardcoded `localhost:3000` fallback for the
Mercure URL (line 51). `DashboardPage` used the correct relative URL. The
rebuild must use the relative URL `/.well-known/mercure` everywhere — no
hardcoded hostnames.

> Reference: `specs/courantes/frontend-spec.md` §5.

---

## JSON-LD Envelope Warning

API Platform serves collections in JSON-LD format (`application/ld+json`), not
plain JSON arrays. The frontend must parse the JSON-LD envelope correctly.

### Collection shape

```json
{
  "hydra:member": [ /* items on current page */ ],
  "hydra:totalItems": 42,
  "hydra:view": {
    "hydra:next": "/api/teams?page=2",
    "hydra:previous": null
  }
}
```

### Item shape

Each item in `hydra:member` has:

- `@id` — the IRI of the resource (e.g., `/api/teams/abc-123`)
- `@type` — the resource type (e.g., `Team`)
- All resource fields (e.g., `id`, `name`, `priorityTierId`, ...)

### ky configuration

- **Set the `Accept` header** to `application/ld+json` in the ky instance, or
  let ky negotiate automatically (API Platform responds with JSON-LD by
  default).
- **Parse `hydra:member`** to extract the array of items from every collection
  response.
- **Use `useInfiniteQuery`** for long lists (teams, diagnostics) with
  `hydra:view.hydra:next` for pagination.
- **Use `useQuery`** for short collections (priority tiers, sport categories).

### TypeScript type

```typescript
type HydraCollection<T> = {
  'hydra:member': T[];
  'hydra:totalItems': number;
  'hydra:view'?: {
    'hydra:next'?: string;
    'hydra:previous'?: string;
  };
};
```

> Reference: `specs/courantes/frontend-spec.md` §7 (TanStack Query Strategy, pagination JSON-LD).

---

## Tenant Isolation

The frontend operates in a multi-tenant context. Each user belongs to exactly
one club (MVP). The tenant context is transparent to the gestionnaire — they
never see `club_id` or `season_id` concepts.

### JWT + X-Club-Id header

| Header | Source | Injection |
|--------|--------|-----------|
| `Authorization: Bearer {jwt}` | `authStore.token` (Zustand, persisted in localStorage) | ky `beforeRequest` hook |
| `X-Club-Id: {uuid}` | `authStore.clubId` (hydrated from `/api/me` after login) | ky `beforeRequest` hook |
| `X-Season-Id: {uuid}` | `authStore.seasonId` (hydrated from `/api/me` or active season) | ky `beforeRequest` hook |

### Rules

1. **Never hardcode `localhost:8080`** in API calls. Use relative URLs (`/api/*`)
   exclusively. The Vite dev proxy handles routing in development; Nginx handles
   it in production.
2. **All requests go through the configured ky instance** — no raw `fetch()` in
   components. The ky instance injects tenant headers automatically from the
   Zustand auth store.
3. **401 -> automatic logout** — the ky `afterResponse` hook detects 401,
   calls `authStore.logout()`, and redirects to `/login`.
4. **No club selector UI** — one user = one club in MVP. `clubId` and
   `seasonId` are stored in Zustand after login and injected transparently.
5. **Season selector is implicit** — the active season is used by default. No
   explicit season switcher in the MVP.

> Reference: `specs/courantes/frontend-spec.md` §4 (HTTP Client Strategy), §6.6 (Multi-tenant transparent), §9 (Headers obligatoires).

---

## RAZ vs Rebuild — OPEN DECISION

> **No option is pre-selected in this handoff document.**

The debate between Option A (RAZ pur), Option B (rebuild selectif), and
Option C (hybride) is documented objectively in
`specs/evolution/raz-vs-rebuild.md`. The decision is explicitly deferred to
Claude Code at execution time.

### What Claude Code must do

1. Read `specs/evolution/raz-vs-rebuild.md` in full.
2. Evaluate the 7 decision criteria against the actual state of the codebase
   at execution time:
   - Residual frustration with `shared/`
   - API contract stability
   - Need for quick demo
   - Existing tests to preserve
   - Perceived technical debt level
   - Time budget allocated
   - Confidence in existing Mercure integration
3. Answer the 4 open questions by inspecting the actual code:
   - Is `authStore.ts` compatible with Zustand 5?
   - Is the ky client on ky 1.x or 2?
   - Where does the Mercure integration live (`shared/` or `features/`)?
   - Are the 4 E2E Playwright specs reusable with the new DOM structure?
4. **Confirm the chosen option** before starting any raz operation.

### User preference (context, not decision)

During planning interviews, RAZ PUR was discussed as one possible direction.
This is noted as context, **not** as a final decision. Claude Code must make
its own independent evaluation.

### What this handoff does NOT do

- Does NOT recommend Option A, B, or C.
- Does NOT pre-select any option.
- Does NOT bias the criteria weighting.
- Does NOT resolve the debate.

> Reference: `specs/evolution/raz-vs-rebuild.md` — full debate, criteria, and open questions.

---

## Quick Reference — Critical Paths

| What | Path |
|------|------|
| Frontend source (raz scope) | `frontend/src/` |
| Docker frontend infra (PRESERVE) | `docker/frontend/Dockerfile`, `docker/frontend/nginx.conf` |
| Vite config (PRESERVE/adapt) | `frontend/vite.config.ts` |
| OpenAPI snapshot (frozen contract) | `specs/courantes/openapi-snapshot.json` |
| Backend inventory (reference) | `specs/courantes/backend-inventory.md` |
| Backend gaps (escalation) | `specs/evolution/backend-gaps.md` |
| RAZ vs rebuild debate | `specs/evolution/raz-vs-rebuild.md` |
| Agent context | `AGENTS.md` (repo root), `frontend/AGENTS.md` |

---

## Cross-References

| Document | Relationship |
|----------|-------------|
| `specs/courantes/frontend-strategy.md` | TDD mandate, stack versions, anti-patterns, infra reuse — the strategic complement to this handoff |
| `specs/courantes/frontend-spec.md` | Forward spec: routes, state, HTTP, Mercure, Query, Zustand, API contract |
| `specs/courantes/frontend-components.md` | Forward spec: pages, shared components, layouts, ARIA, lock levels, day anchoring |
| `specs/courantes/frontend-wizard.md` | Forward spec: 4-step wizard, state machine, auto-save, ARIA |
| `specs/courantes/openapi-snapshot.json` | Frozen API contract (168 KB, 40 paths, backend SHA `6e35a6ce`) |
| `specs/evolution/raz-vs-rebuild.md` | Open debate — Claude Code must decide |
| `specs/evolution/backend-gaps.md` | 7 gaps (G1-G7) — blockers, workarounds, delegated actions |
| `specs/courantes/backend-inventory.md` | Backward inventory of backend (20 resources, 7 controllers, security, Mercure, pagination) |
| `AGENTS.md` | Repo-level agent context (commands, architecture, gotchas) |
| `.omo/plans/frontend-raz-cleanup.md` | Source plan (interviews tours 2-3, Metis review) |
