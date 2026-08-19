# Amateo — Frontend Agent Context

> React 19 · Vite 8 · TypeScript ~6.0 · Tailwind 4. The web UI of the club-scheduling
> platform. Rebuilt from scratch and **active** — every path below exists in `src/`.
>
> Canonical detail lives in `README.md` (role & boundaries) and
> `docs/frontend-spec.md` (routes, state, API contract). This file is the
> agent cheat-sheet: what breaks, what is a trap, what is non-negotiable.

---

> ⚑ Les pièges qui rendent un test **vert à tort** (image tooling, `dist` cuit,
> `tsc --noEmit`, jsdom sans moteur de mise en page) sont AUSSI dans
> [`.claude/rules/frontend.md`](../.claude/rules/frontend.md), **chargé automatiquement** dès
> qu'un fichier de `frontend/` est touché — ce fichier-ci ne l'est pas.

## Boundaries (never cross)

- Talks to the backend **only** via `/api/*`. **Never contacts the engine directly** —
  generation goes through `POST /api/schedules/{id}/generate` and the backend calls the
  engine. There is deliberately **no `/engine` proxy** in `vite.config.ts` (FRT-17).
- Sends **no `X-Club-Id` header**: the tenant is resolved server-side from the JWT
  membership (see `../backend/docs/TENANT.md`). A spoofed header would 403 anyway.
- Sends `X-Season-Id` **only** when the manager has explicitly picked a season
  (`seasonStore`); absent = the server derives the current season. The server validates it
  either way — it is never trusted client-side.
- API URIs are **snake_case** (`/api/team_coaches`, `/api/venue_training_slots`,
  `/api/priority_tiers`, `/api/schedule_slot_templates`…).
- Always relative URLs. **Never hardcode a host** — `prefix: "/api"` uses the Vite proxy in
  dev and Nginx in prod.

---

## Layout

```
frontend/
├── src/
│   ├── main.tsx                 # Entry: Sentry init, pre-paint theme, createRoot
│   ├── index.css                # Tailwind 4 @theme tokens + --accent slots
│   ├── app/                     # Shell & routing
│   │   ├── router.tsx           # createBrowserRouter + per-route `lazy` (see below)
│   │   ├── RootShell.tsx        # Technical root: carries the navigation-pending net
│   │   ├── RouteErrorBoundary.tsx / ErrorBoundary.tsx
│   │   ├── AppLayout.tsx        # Header (club logo = home link) + account menu
│   │   ├── AuthGuard.tsx        # Token / membership / onboarding gates
│   │   ├── SeasonSelector.tsx · SeasonTransitionBanner.tsx · ReadonlySeasonBanner.tsx
│   │   └── providers.tsx · DevClock.tsx · seasonTransition.ts
│   ├── features/                # One folder per domain, each `{api,queries,store}.ts`
│   │   ├── admin/               # Superadmin console (/admin) — own session client
│   │   ├── auth/                # Login · register · verify-email · password · waiting
│   │   ├── club/                # /club hub: identity (logo/accent), FFBB info, requests
│   │   ├── coach-wishes/        # #10 doléances: modal, campaign, PUBLIC page, radar badge
│   │   ├── cockpit/             # / home: season-plan banner, month calendar, radar
│   │   ├── legal/               # /confidentialite
│   │   ├── matches/             # /matchs: weekend grid, conflict radar, FBI import
│   │   ├── planning/            # /planning work loop: WeekGrid, toolbar, exports
│   │   ├── profile/             # /profile
│   │   ├── season-transition/   # Season pivot banner + re-dating dialog
│   │   └── wizard/              # 6-step data entry (see `lib/steps.ts`)
│   ├── shared/
│   │   ├── api/                 # client.ts (ky) · collection.ts (JSON-LD) · errors.ts
│   │   ├── components/ui/       # Primitives (shadcn-style) — see "Primitives that matter"
│   │   ├── hooks/               # useApplyTheme · useApplyClubTheme
│   │   ├── lib/                 # readState, teamTiers, color, palette, duration, …
│   │   └── stores/              # authStore · themeStore · seasonStore · toastStore · transitionUiStore
│   └── test/                    # Vitest setup + render helpers + a11y suite
├── tests/e2e/                   # Playwright (auth, journey, matches, a11y-contrast, …)
├── vite.config.ts               # Plugins, `@/` alias, dev proxies
├── vitest.config.ts             # jsdom, globals, setup, excludes tests/e2e
├── eslint.config.js             # Flat config — jsx-a11y is BLOCKING (see below)
└── Makefile                     # All tooling is Dockerized
```

Feature stores live at `features/<x>/store.ts` (`wizard`, `planning`, `matches`, `admin`);
cross-cutting ones at `shared/stores/`.

---

## Commands

**All tooling runs in Docker**; the host needs only Docker, Docker Compose and Make.

```bash
cd frontend
make install     # Build the Node tooling image
make dev         # Dockerized Vite dev server (5173)
make build       # Production image (tsc + Vite + Nginx, served on 8081)
make lint        # ESLint + TypeScript, in Docker
make test        # make lint, then Vitest
make exec        # Shell inside the tooling image
make start | stop | logs | shell | status   # Docker Compose helpers
```

### ⚠ Trap: never `tsc --noEmit`

`make lint` runs `npm run lint && npx tsc -b --force`. The root `tsconfig.json` is a
**solution file** (`"files": []` + `references`), so `tsc --noEmit` sees **zero files**: it
exits 0 having checked nothing, while CI (which runs `tsc -b`) fails on the errors it
skipped. `--force` is also required — a stale `tsbuildinfo` short-circuits the check.

### ⚠ Trap: an e2e run can validate the PREVIOUS build

The `frontend` compose service **builds its own image** (`docker/frontend/Dockerfile`, Nginx
on 8081) — `dist` is **not** a bind mount, and `frontend-tooling` is a COPY image with no
mount either. So `npx vite build` inside the tooling container writes into that container
and is thrown away: the app served on 8081 does not move, and an e2e launched afterwards
**passes against the old bundle**. Before any e2e that must see your change:

```bash
docker compose build frontend && docker compose up -d --force-recreate frontend
```

Only `frontend-dev` (profile `dev`, port 5173) mounts `./frontend` — that is the hot-reload
path, not what the e2e targets. Found the hard way on P4-43: the journey spec went green
while a screenshot showed the old toolbar.

E2E Playwright **is** fully Dockerized: `make -C frontend e2e` (compose profile `tools`,
service `e2e`) — it needs the stack **and** `make -C frontend dev` running. The target also
carries the superadmin preflight (it seeds the account and exports its TOTP secret); without
it the `/admin` specs SKIP explicitly rather than fail.

---

## Routing — split by route, and the three nets that make it safe

`app/router.tsx` builds a data router where **everything except `/login` and the guards is
`lazy`**. Motivation: a single chunk used to ship on every first visit — superadmin console
and wizard included — even for a coach opening nothing but a public doléances page.

Eager on purpose: `LoginPage` (entry path), `AuthGuard`, `AdminGuard` (their code must be
present to decide).

Splitting is only safe because of three nets. **Removing any of them trades the gain for a
silent outage — do not drop them when adding a route:**

| Net | Without it |
|-----|-----------|
| `errorElement` (root + nested under `AppLayout`) | A 404 chunk (deploy mid-session) replaces the **whole app** with the router's unstyled English screen, invisible to Sentry. The nested one keeps header/nav/banners alive when a single page's chunk fails. |
| `HydrateFallback` | react-router renders `null` → **blank page** on any direct open or F5 of a lazy route. |
| Pending indicator (`useNavigation`, in `AppLayout`) | A navigation click gives **no feedback at all** until the chunk lands. |

Known, accepted trade-off (documented in the file): the data router resolves the `lazy` of
**all matched routes** before rendering any, so an anonymous visitor on `/planning`
downloads the page before being redirected to `/login`. That JS is public and carries no
data; avoiding it would mean duplicating the auth decision into a per-route `loader`.

### Routes

| Route | Auth | Notes |
|-------|------|-------|
| `/login` | public | The only eager page |
| `/register` · `/verify-email/:token` · `/forgot-password` · `/reset-password/:token` · `/waiting` | public | Register is 202 + email link; verify sets the auth **cookie** (SEC-16 — no token in the body) |
| `/confidentialite` | public | Privacy policy |
| **`/doleances/:token`** | **public, NO login** | #10 — flat route, deliberately **outside `AuthGuard`**. A coach fills in availability from a personal tokenised link. |
| `/admin/login` · `/admin` | SA0 session | Superadmin console behind `AdminGuard` → `AdminShell`. Separate identity — a club JWT never crosses this firewall. |
| `/` | required | **Cockpit** (temporal home), not the planning |
| `/planning` · `/matchs` · `/wizard` · `/club` · `/profile` | required | Under `AuthGuard` → `AppLayout` |
| `*` (authed) | required | Redirects to `/`, not the raw error boundary |

---

## Data & state

- **Server state → TanStack Query 5. Client state → Zustand 5.** Never store a query result
  in Zustand; never fetch outside Query.
- **HTTP is `ky`** (`shared/api/client.ts`) — not axios, not raw fetch. `beforeRequest`
  injects the optional `X-Season-Id` — **no `Authorization` header any more**: the JWT is an
  httpOnly cookie set by the server (SEC-16, `docs/security/jwt-cookie.md`), so the client
  only carries `credentials`. `afterResponse` clears auth on a 401 (except on `/api/login`,
  where 401 means bad credentials) and self-heals a stale season on a `403` carrying
  `X-Season-Rejected`.
  ⚠ **There is no `beforeError` hook and there must not be one**: ky 2.x consumes the error
  body itself and exposes it as `error.data` before any consumer runs. Re-reading
  `error.response` throws *"body stream already read"* — every error reader must use
  `error.data`.
- **The superadmin console has its own client** (`features/admin/api.ts`): `adminApi`, prefix
  `/api/admin`, `credentials: "same-origin"`, and it **deliberately never touches the club auth
  store**. The two identities stay separate cookies on separate paths (`/api/admin` session vs
  the club `BEARER` cookie scoped to `/api`).
- **Collections are JSON-LD with the key `member`** (API Platform 4 — *no* `hydra:` prefix).
  `collection()` unwraps it; `collectionAll()` pages via `?page=N` and dedupes by `id`.
  There is **no `useInfiniteQuery`** anywhere.

### Taille de texte : plancher 12 px, sauf dans les grilles

Le corps de texte descend à `text-xs` (0,75 rem = **12 px**) et pas en dessous — pas
d'échelle arbitraire en `text-[10px]`. **Exception assumée : les GRILLES**
(`WeekGrid`, `WeekendGrid`, `TypicalWeekendGrid`, `ReservationGrid`, `MonthCalendar`,
`VenueAvailabilityGrid`, **`ClubViewTable`** — ajoutée le 2026-08-19, audit A11Y-16 : née
avec P3-20, elle suivait déjà la convention sans figurer dans la liste), où la densité est la fonction : y agrandir le texte impose
des lignes plus hautes, donc du défilement dans un écran fait pour tenir en un coup
d'œil. Décision fondateur du 2026-08-08, avec son pourquoi dans
`specs/courantes/etat-des-lieux.md` §2.

⚠ Ne pas confondre avec une exigence WCAG : **aucun plancher de taille n'existe** en
2.2 (1.4.4 demande le zoom 200 % sans perte, pas une taille mini). C'est une barre de
qualité — donc un ajout en `text-[9px]` hors grille se discute, il ne se refuse pas
au nom d'une norme.

### ⚠ axe ne voit PAS un champ nommé par son seul `placeholder`

Mesuré le 2026-08-07 sur `<input placeholder="…" />` nu : axe-core rend `violations: []`
et classe `label` **et** `label-title-only` dans `passes` — HTML-AAM autorise `placeholder`
comme source de nom de dernier recours, donc axe a techniquement raison. Conséquence
pratique : `expect(await axe(container)).toHaveNoViolations()` **ne garantit pas** qu'un
champ a un nom utilisable (le placeholder disparaît à la première frappe, et l'AT n'annonce
plus que « zone de texte »). C'est ainsi qu'A11Y-10 a survécu à un test qui prétendait
couvrir l'écran.

Donc : pour un champ, assertion EXPLICITE du nom —
`screen.getByRole("textbox", { name: "…" })` — en plus de la passe axe, jamais à sa place.

### Generation status = SSE, polling as fallback (FRT-04)

`shared/lib/scheduleStream.ts` holds the ONE `EventSource` per session (ref-counted
singleton): auth via `GET /api/mercure/auth` (httpOnly cookie + `topicTemplate` — the front
never knows its clubId), subscription to the template itself, events invalidate the
react-query caches. `features/planning/queries.ts` and `features/wizard/queries.ts` keep
their poll but degrade it (2.5 s stream down → 15 s stream connected) — the publisher is
best-effort, so polling must never die. Details & security contract:
`docs/security/mercure.md` (root). `WaitingApprovalPage` still polls `/api/me` every 5 s.

### Wizard store = UI only

`features/wizard/store.ts` holds the current step, the furthest step reached, the mode
(`season` | `period`) and `calendarEntryId` — **nothing else**, persisted at `version: 4`.
There is **no draft blob and no `autoSave()`**: every team/venue/coach/constraint is
POST/PUT/DELETE'd immediately via TanStack mutations. "Suivant" only validates and navigates.

---

## Primitives that matter (`shared/components/ui/`)

Beyond the obvious (`button`, `input`, `select`, `card`, `modal`, `menu`, `accordion`), three
carry product rules — reuse them instead of rolling your own:

- **`delete-confirm`** — destructive confirmation that *announces its impacts* ("N réservations
  seront retirées"). Deleting without stating what it takes away is the bug it exists to prevent.
- **`load-error-hint`** — "the read failed, here is a retry". Pairs with `readState` below.
- **`team-select`** — every team picker in the app (constraints, coaches, matches, FBI import)
  goes through it: optgroups by rank, same order as the Teams step. Reranking a team updates
  the order **everywhere**.

### `shared/lib/readState.ts` — the anti-"credible emptiness" rule

react-query's flags are transient and reading them as settled truths is a whole bug family.
`readState()` collapses them into three states on a single criterion — *do we have data?*

- `loading` — nothing to show yet (first load);
- `failed` — the read failed **and** there is nothing cached: the only case where a screen may
  give way to an error;
- `ready` — we have data, even stale, even after a failed background refetch.

Two consequences to respect: `isError` on a **background** refetch must not destroy a working
screen, and `data ?? []` during a first load fabricates a **credible emptiness** ("no slots",
"no settings") that makes a manager re-enter data (duplicates) or validate a period they
believe empty.

---

## Gotchas

1. **Tooling is Dockerized** — do not invoke host Node/npm; use the Make targets.
2. **`tsc --noEmit` is a no-op here** — see the trap above. Always `tsc -b --force`.
3. **Accessibility is blocking, not advisory.** `eslint.config.js` re-severities the whole
   `jsx-a11y` recommended set to `error` via the single `A11Y_LEVEL` knob (WCAG 2.2 AA
   guardrail). Flip it to `warn` only to temporarily unblock a large refactor. There is also
   an a11y unit suite (`src/test/a11y.test.tsx`) and a Playwright contrast spec.
4. **Migration anti-patterns are ESLint-enforced**, not just documented — e.g. a
   `no-restricted-syntax` rule bans `ReactDOM.render`. See `docs/frontend-strategy.md` §3.
5. **The theme is applied before React's first paint** (`main.tsx`, `readPersistedThemeMode`).
   Without it the tree renders light, then an effect flips `.dark` — a flash of the wrong
   theme plus a `transition-colors` animation that leaves surfaces at sub-AA colours (A11Y-06).
   The pre-paint class and `useApplyTheme` share the same predicate and storage shape so they
   can never disagree.
6. **Sentry is errors-only** (`main.tsx`): no APM, no replay, `tracesSampleRate: 0` — the free
   tier quota is deliberately preserved. No DSN = init skipped, SDK inert.
   ⚠ **Switching it on takes TWO changes, not one** (P4-65): set `VITE_SENTRY_DSN` at build
   time **and** allow the DSN's ingest host in `connect-src` (`docker/frontend/csp.conf`,
   which allows no third party). The DSN alone initialises the SDK while the browser drops
   every send **silently**. `frontend/tooling/sentryCspGuard.ts` (called from `vite.config.ts`)
   now **fails the build** on that combination; it is inert while no DSN is set. INF-01.
7. **The club accent is per-club and AA-guarded.** `useApplyClubTheme` reads
   `accentColor`/`accentColorDark`/`accentPalette` from `/api/me` and drives `--accent` /
   `--accent-foreground`; an explicit dark accent is applied as-is in dark mode, otherwise a
   legible derivation of the light one is used.
8. **Engaged teams are read-only on two fields.** `Team.isEngaged` comes **from the server**
   (`TeamResource.isEngaged`) and is never recomputed client-side; `TeamsStep` greys out both
   **deletion** and **level change** for such a team — its matches are filed with the
   federation. Server-side guard: `EngagedTeamGuardTest`. This is a structuring axis
   (`CLAUDE.md` §7.1) — touching it requires a non-regression test.
9. **The planning pointer moves by validating, and by nothing else.** There is no "set as
   main" action (ADR-0002; locked by `PlanningToolbar.test.tsx`). Validating points the plan
   at a version and deletes its sibling versions; reopening un-points it.
10. **A period owns its venue grid.** In wizard period mode the Venues step is **editable**, not
    a read-only summary: the period's slots are a copy taken at plan birth and never unioned
    with the season's own. Same gestures as the season, barre « À poser » included (P4-43).
    See `docs/frontend-wizard.md`.
11. **Accent as TEXT needs a plain background.** `text-accent` clears 4.5:1 (WCAG 1.4.3) only
    on `bg-background`/`bg-card`: over `bg-accent/10` it drops to 4.18:1 in light mode, over
    `bg-muted` to 4.37:1 — even `accent/05` fails. Tint the surface **or** colour the text,
    never both. The token pairs are locked by `tests/e2e/a11y-contrast.spec.ts`; add any new
    text token to its list rather than eyeballing the result.

---

## Quick reference

| Task | Command |
|------|---------|
| Dev server | `make -C frontend dev` |
| Lint + typecheck | `make -C frontend lint` |
| Tests (lint + Vitest) | `make -C frontend test` |
| Build prod image | `make -C frontend build` |
| Tooling shell | `cd frontend && make exec` |

**Pointers:** `README.md` (role, boundaries, delivered features) ·
`docs/frontend-spec.md` (routes, state, API contract) ·
`docs/frontend-wizard.md` (wizard & period mode) ·
`docs/constraint-emission.md` (what the wizard emits, 3-layer alignment) ·
`../specs/courantes/superadmin-auth.md` (`/admin`) ·
`../specs/courantes/types-de-planning.md` (doléances coachs, #10).
