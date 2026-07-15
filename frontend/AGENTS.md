# ClubScheduler — Frontend Agent Context

> React 18 + Vite 8 + TypeScript ~6.0. UI for club scheduling platform.

---

## Architecture

```
frontend/
├── src/
│   ├── app/
│   │   ├── router.tsx        # React Router config
│   │   ├── routes.tsx        # Lazy-loaded routes
│   │   └── AppLayout.tsx     # Sidebar + header layout
│   ├── features/
│   │   ├── auth/             # Login, JWT auth store (Zustand)
│   │   ├── wizard/           # 4-step club setup wizard
│   │   │   ├── wizardStore.ts          # Zustand + persist (localStorage)
│   │   │   ├── components/
│   │   │   │   ├── VenueStep.tsx
│   │   │   │   ├── CoachStep.tsx
│   │   │   │   ├── TeamStep.tsx
│   │   │   │   └── SummaryStep.tsx
│   │   ├── schedule/
│   │   │   ├── pages/
│   │   │   │   ├── ScheduleViewPage.tsx    # FullCalendar + Mercure SSE
│   │   │   │   └── DiagnosticsPage.tsx
│   │   │   ├── components/
│   │   │   │   ├── ExportPdfButton.tsx
│   │   │   │   └── DiagnosticsPanel.tsx
│   │   │   ├── api/
│   │   │   │   └── useScheduleDiagnostics.ts
│   │   │   ├── useSchedule.ts              # React Query hooks
│   │   │   └── types.ts                    # ScheduleSlot, LockLevel
│   │   └── priorities/
│   │       ├── TierListPage.tsx           # Drag & drop priority tiers
│   │       ├── TierColumn.tsx
│   │       ├── TeamCard.tsx
│   │       └── priorityApi.ts
│   ├── shared/
│   │   ├── api/
│   │   │   └── client.ts           # ky HTTP client + interceptors
│   │   ├── components/
│   │   │   ├── ErrorBoundary.tsx
│   │   │   └── LoadingSpinner.tsx
│   │   └── lib/
│   │       └── queryClient.ts      # TanStack Query config
│   └── main.tsx                  # Entry point
├── public/
├── index.html
├── vite.config.ts              # Vite + proxy + TailwindCSS
├── eslint.config.js            # Flat config (typescript-eslint, react-hooks, react-refresh)
└── package.json
```

---

## Key Conventions

- **Feature-based organization** — each feature owns its pages, components, API hooks, and state.
- **HTTP client** is `ky` (not axios/fetch) with Bearer token injection and 401 → logout redirect.
- **State**: Zustand for auth (with persist middleware); TanStack Query for server state.
- **API calls** use **relative URLs** (`/api/*`) — never hardcode `localhost:8080`.
- **TypeScript** ~6.0.2 with strict mode (via `tsconfig.json`).
- **UI**: Tailwind CSS + FullCalendar 6 + `@dnd-kit` for drag-and-drop.
- **Build**: Vite 8 with `@tailwindcss/vite` plugin. Alias `@/` → `src/`.

---

## Toolchain

- **ESLint** flat config (`eslint.config.js`) — `@eslint/js`, `typescript-eslint`, `react-hooks`, `react-refresh`.
- **Lint command**: `npm run lint && npx tsc --noEmit` (the Makefile `make lint` does both).
- **Build**: `npm run build` runs `tsc -b && vite build`.
- **Dev server**: `make dev` starts Dockerized Vite on port 5173.

---

## Commands

**All frontend tooling runs in Docker**; Node/npm are not required on the host:

```bash
cd frontend
make install             # Build the Node tooling image
make dev                 # Dockerized Vite dev server (port 5173)
make build               # Production frontend image (tsc + Vite + Nginx)
make lint                # ESLint + TypeScript in Docker
make test                # Vitest in Docker
```

Docker helpers (Makefile):
```bash
cd frontend
make start               # docker compose up -d (all services)
make stop                # Stop frontend container
make logs                # Follow frontend logs
make shell               # Open shell in frontend container
make status              # Show frontend container status
```

---

## Dev vs Production Proxy

**Dev** (`make dev`):
- Vite proxies `/api` → the Docker `nginx` service
- Vite proxies `/.well-known/mercure` → the Docker `mercure` service
- There is no `/engine` proxy: the frontend never calls the engine directly

**Production**:
- Frontend Nginx container (port 8081) proxies `/api` → backend Nginx.
- Frontend Nginx container proxies `/.well-known/mercure` → Mercure hub.

**Never hardcode `localhost:8080`** in API calls. Always use relative URLs.

---

## Mercure SSE (Real-time)

- Frontend subscribes to `/.well-known/mercure?topic=club:{clubId}:schedule:{scheduleId}`.
- `ScheduleViewPage.tsx` uses `useMercureSubscription` hook with `EventSource`.
- On message: invalidates React Query cache and refreshes the calendar.
- Uses `import.meta.env.VITE_MERCURE_URL` (defaults to `/.well-known/mercure` in production).

---

## Wizard State

- `wizardStore.ts` uses Zustand with `persist` middleware (localStorage key: `wizard-storage`).
- Stores: venues (slots + closures), coaches, teams (with constraints).
- Auto-save via `autoSave()` action — posts to API sequentially.
- Validation per step: at least one venue slot, one coach, one team.

---

## Gotchas

1. **Tooling runs in Docker** — do not invoke host Node/npm; use the frontend Make targets.
2. **Never hardcode localhost** — API calls must use relative URLs (`/api/*`).
3. **ky client** — not axios, not fetch. Uses `prefix: '/api'` and Bearer token injection.
4. **401 handling** — ky interceptor clears Zustand auth and redirects to `/login`.
5. **FullCalendar events** — mapped from `ScheduleSlot` objects with day-of-week logic. Uses a reference Monday to anchor events.
6. **Lock levels** — `NONE` (gray), `SOFT` (orange), `HARD` (red). Rendered as colored dots in calendar events.
7. **Drag & drop** — priority tiers use `@dnd-kit/core` + `@dnd-kit/sortable`. Tier labels are S, A, B, C, D.
8. **Export PDF** — `ExportPdfButton` calls `POST /api/schedules/{id}/export-pdf`. The backend handler is a stub.

---

## Quick Reference

| Task | Command |
|------|---------|
| Dev server | `make -C frontend dev` |
| Build | `make -C frontend build` |
| Lint | `make -C frontend lint` |
| Install | `make -C frontend install` |
| Start stack | `cd frontend && make start` |
| Stop stack | `cd frontend && make stop` |
| Shell | `cd frontend && make shell` |
