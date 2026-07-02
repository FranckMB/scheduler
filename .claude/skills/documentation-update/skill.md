---
name: Documentation Update
description: After a feature, selectively refresh the docs so a human dev and an agent both understand the current state and how it evolved — general + per-subproject READMEs, CLAUDE.md index, per-zone docs/, ADRs, and specs/ reconciliation. No filler. Invoke manually.
---

## Documentation Update

Run **only when the user asks**, after work whose **business behaviour, architecture, conventions, public APIs, or subproject scope actually changed**. Goal: a developer landing in `backend/` vs `engine/` vs `frontend/` grasps the scope, how work is done *there* (each zone works differently), and where the core-business docs live — and the evolution stays legible.

### Where each doc lives (one canonical home — never copy across)

| File | Audience | Holds |
|------|----------|-------|
| `README.md` (root) | human | project purpose, problem solved, architecture sketch, quickstart, layout, links to subproject READMEs |
| `<zone>/README.md` | human | that zone's scope + what/why, **command recap**, **project recap** (structure/entry points), pointers to its structuring/business docs |
| `<zone>/AGENTS.md` | agent | dense package-level cheat-sheet (facts an LLM needs); root `AGENTS.md` just points to `CLAUDE.md` |
| `CLAUDE.md` (root) | agent | short operational index (< ~200 lines), facts not obvious from filenames |
| `<zone>/docs/`, `<zone>/doc/` | both | deep-dives (business rules, how-to guides, structuring mechanisms) |
| `docs/` (root) | both | cross-cutting: project-map, testing, architecture/ADRs, technical-debt |
| `specs/` | both | living product specs (see reconciliation below) |

Rule: **README points, it does not recopy.** If a fact is in `docs/` or `AGENTS.md`, the README links to it. Every added line must carry a fact a future reader would otherwise get wrong.

### Per-subproject README — required sections (adapt to the zone's real style)

Each `<zone>/README.md` must let a newcomer answer "what is this, how do I work here, where's the hard stuff":
1. **Scope & role** — 1–2 paragraphs: what this zone owns, its boundaries (what it must never do — e.g. engine never calls backend, frontend never calls engine directly).
2. **Command recap** — the commands that matter *for this zone*, and the note that backend/engine run **inside Docker** while frontend dev runs **on the host**.
3. **Project recap** — entry point(s), main structure, key mechanisms in one glance.
4. **Structuring / business docs** — a pointer list to the docs that explain the core: e.g. backend → `scripts/generate-schedule.sh` (how to drive a generation), tenant isolation (`docs/TENANT.md`, `docs/RLS.md`), the constraints model; engine → `doc/business.md`, `doc/nominal-flow.md`, `doc/solver-errors.md`; frontend → feature workflow (planning work-loop, wizard), component/UX conventions.

Respect the distinct working style per zone — don't flatten them into one template. The backend README reads like an API/persistence service, the engine like a solver, the frontend like a UI app.

### specs/ reconciliation (initiales → courantes → evolution)

Three buckets, distinct meaning — keep them true:
- **`initiales/`** — origin specs (v2/v3). **Frozen**: never edit. They are the starting point; the evolution is read as the delta `initiales` → `courantes`.
- **`courantes/`** — what the app does **today**. Must reflect reality: if a courante spec no longer matches the code, **update it**; if the feature was removed, **delete it**. When an `evolution` item ships, its behaviour lands here.
- **`evolution/`** — what the app will do **later** (future/vision). When an item is delivered, **remove it from evolution** (it has graduated into `courantes`).

No `archive/` bucket — nothing is lost (git + `initiales` hold the history). When reconciling, flag files that are neither current nor future (e.g. one-off process/handoff notes) and propose removing them; do not silently delete large specs — surface the list first.

### Steps
1. **Decide what genuinely changed** among: business behaviour, architecture, conventions, public APIs, subproject scope. If nothing, say so and write nothing.
2. **READMEs** — refresh the root README and any affected `<zone>/README.md` per the required sections above.
3. **CLAUDE.md** — update only non-obvious facts; keep it a short index (< ~200 lines).
4. **Zone docs** (`<zone>/docs`, `docs/project-map.md`, `docs/testing/`, `docs/technique/`) — update the affected deep-dives; add a how-to/business doc if a new structuring mechanism appeared.
5. **ADR** — if a structural decision was made, add one and reference it in `docs/architecture/adr-index.md`.
6. **specs/** — reconcile per the rules above: update stale `courantes`, graduate shipped `evolution` items, delete removed features, surface dead process notes for confirmation.

### Rules
- **No filler.** A future agent must get something wrong without the line.
- **One canonical home**, README points to detail, detail is not copied back.
- **Never edit `specs/initiales/`.** Reconcile `courantes`/`evolution` only.
- **Deletions are surfaced, not silent** — list what you propose to remove and why before removing large specs/docs.
