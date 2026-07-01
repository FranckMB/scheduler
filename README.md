# ClubScheduler

**Automated training-schedule generation for basketball clubs (FFBB).**

ClubScheduler builds a club's **per-season weekly training schedule** — placing every
team into a gym time-slot — automatically, with a constraint solver, instead of by hand.

---

## The problem

Every season, a basketball club manager has to fit **dozens of teams** into a **handful of
gyms** across the week. Each placement must respect a tangle of rules at once:

- gyms are only open at certain days/times, and a court can hold a limited number of teams;
- a coach can't be in two places at once, and some coaches are unavailable on given days;
- important teams (senior/élite) get priority over recreational ones;
- teams need a set number of sessions per week, some prefer/avoid a specific gym or time…

Done in a spreadsheet, this is a slow, error-prone combinatorial puzzle. One change
(a gym closes, a coach leaves) forces a manual re-shuffle of everything.

## What ClubScheduler does

1. **Enter the club's data once** (guided wizard): teams + priority ranking, gyms + weekly
   availability, coaches + their teams, and any explicit constraints.
2. **Generate** a full schedule. A **CP-SAT constraint solver** (Google OR-Tools) places
   the teams under the hard rules and optimizes a soft scoring objective (spread, preferences…).
3. **Work the plan**: view it as a week grid (by gym, by coach, or by team), read the
   solver's diagnostics, **lock** the slots you like, tweak, and **regenerate** — the locked
   slots stay put. Promote a plan as the season's **baseline**, and generate secondary plans
   (e.g. holidays) alongside it.

The manager stays in control; the solver does the combinatorial heavy lifting.

## How it fits together

```
 Manager ─▶ Frontend ──/api/*──▶ Backend ──POST /generate──▶ Engine (CP-SAT solver)
   (React)              (Symfony, orchestrates + persists)      (Python, solves)
                            ▲                                        │
                            └──────── results imported ◀────────────┘
                        Backend ──Mercure SSE──▶ Frontend (live generation progress)
```

- **`backend/`** — Symfony 7.4 · API Platform 4.3 · Doctrine ORM. The API + source of truth:
  persists data, enforces **multi-tenant isolation** (one club never sees another's data),
  freezes an input snapshot, calls the engine, imports results, publishes progress.
- **`engine/`** — Python 3.12 · FastAPI · OR-Tools CP-SAT. A stateless solver exposed as
  `POST /generate`. It is **reactive**: it never calls the backend.
- **`frontend/`** — React 19 · Vite · Tailwind 4. The UI: authentication, the **wizard**
  (data entry) and the **work-loop** (view/adjust/regenerate the schedule).

**Hard boundaries:** `frontend → backend` only via `/api/*`; `backend → engine` only via
`POST /generate`; the frontend never calls the engine directly.

## Stack

| Zone | Runtime | Entry point |
|------|---------|-------------|
| `backend/` | PHP 8.4 · Symfony 7.4 · API Platform · Doctrine ORM 3.6 | `public/index.php` |
| `engine/`  | Python 3.12 · FastAPI · OR-Tools CP-SAT | `app/main.py` |
| `frontend/`| TypeScript · React 19 · Vite · Tailwind 4 | `src/main.tsx` |

## Getting started

Backend and engine run **inside Docker**; the frontend dev server runs **on the host**.

```bash
make start      # bring up the stack (docker compose, reads .env)
make test       # run backend + engine test suites
make lint       # code-quality checks
make stop       # stop the stack

cd frontend && npm install && npm run dev   # UI on http://localhost:5173 (proxies /api)
```

Per-zone commands live in `backend/Makefile` and `engine/Makefile` (e.g.
`cd backend && make test`, `cd engine && make test`). A solver smoke test drives a full
create → generate → completed run: `bash backend/scripts/smoke-solver.sh`.

## Layout

```
backend/   PHP API, persistence, async orchestration (Symfony Messenger)
engine/    Python CP-SAT solver (POST /generate)
frontend/  React UI — auth · wizard (data entry) · work-loop (adjust/regenerate)
docker/    container + local-env assets
specs/     living product/spec docs (initiales / courantes / evolution)
docs/      architecture, testing, technical-debt notes
```

## Documentation

- **`CLAUDE.md`** — operational index for the whole repo (stack, boundaries, conventions).
- **`docs/project-map.md`** — detailed map · **`docs/architecture/`** — ADRs.
- **`backend/docs/TENANT.md`** — multi-tenant isolation · **`specs/courantes/`** — current specs.

## Status

Active development (V0). Delivered so far: auth, the planning work-loop, and the data-entry
wizard, backed by the CP-SAT engine. Not deployed anywhere yet.
