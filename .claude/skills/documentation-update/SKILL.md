---
name: documentation-update
description: Refresh the living docs before opening a PR — general + per-subproject READMEs, CLAUDE.md index, per-zone docs/, ADRs, and specs/ reconciliation (roadmap = open only, etat-des-lieux = delivered). Verifies claims against code (no drift), bans volatile counts, enforces evolution→courantes graduation. No filler.
---

## Documentation Update

**Run before opening EVERY PR** (CLAUDE.md §7 step 6 — both lanes). The docs are alive: a PR that fixes, adds or removes something has documentation to update **somewhere**. Finding nothing is a conclusion you reach by looking, not an assumption you start from — and if you truly find nothing, say which files you checked and why they are unaffected.

Goal: a developer landing in `backend/` vs `engine/` vs `frontend/` grasps the scope, how work is done *there* (each zone works differently), and where the core-business docs live — and the evolution stays legible.

**The two-file rule (refonte 2026-07-31) — never blur it:**
- [`specs/evolution/roadmap.md`](../../../specs/evolution/roadmap.md) holds **ONLY what is still open** (bugs, evolutions, technical debt, parking, vision). Nothing delivered stays there.
- [`specs/courantes/etat-des-lieux.md`](../../../specs/courantes/etat-des-lieux.md) holds **what is delivered**: the capability map, the **closed decisions** (deliberate abandons — they are what stops a settled question from being re-opened every three months), and the dated delivery traces.

A delivered item **moves**: it is deleted from the roadmap and gains a trace line in the état des lieux, with its behaviour documented in the receiving `courantes/` spec. Never both. Never neither.

**Prime directive: a doc that lies is worse than no doc.** This project is agent-driven; a false fact in CLAUDE.md/AGENTS.md/project-map is injected into every future plan. Accuracy beats completeness.

### Where each doc lives (one canonical home — never copy across)

| File | Audience | Holds |
|------|----------|-------|
| `README.md` (root) | human | project purpose, problem solved, architecture sketch, quickstart, layout, links to subproject READMEs |
| `<zone>/README.md` | human | that zone's scope + what/why, **command recap**, **project recap** (structure/entry points), pointers to its structuring/business docs |
| `<zone>/AGENTS.md` | agent | **pointers + zone-only gotchas.** NOT a re-description of commands, flows, tooling or counts already in CLAUDE.md / project-map / zone README — duplication here is the #1 historical cause of doc rot (audit 2026-07-03, DOC-02). If a fact exists elsewhere, link it. |
| `CLAUDE.md` (root) | agent | short operational index (< ~200 lines), facts not obvious from filenames |
| `<zone>/docs/` | both | deep-dives (business rules, how-to guides, structuring mechanisms). **Single dir per zone** — `doc/` merged into `docs/` 2026-07-11; never recreate a `doc/` |
| `docs/` (root) | both | cross-cutting: project-map, glossary, testing, architecture/ADRs |
| `specs/evolution/roadmap.md` | both | **the OPEN only**: prioritized backlog (P1-P4), deliberate-keep debt, parking, unpriced vision. A delivered line does not live here |
| `specs/courantes/etat-des-lieux.md` | both | **the DELIVERED**: capability map (pointers, not behaviour), **closed decisions**, dated delivery traces |
| `specs/` | both | living product specs (see reconciliation below) |

Rule: **README points, it does not recopy.** If a fact is in `docs/` or `AGENTS.md`, the README links to it. Every added line must carry a fact a future reader would otherwise get wrong.

### Anti-drift rules (hard)

1. **Verify before you write.** Any factual claim you add or keep in a touched doc must be checked against the code *now* (read the file, grep the config). Never propagate a claim just because the doc already said it.
2. **No volatile counts.** Never write "N controllers", "N entities", "N fixtures", "N tests" in any doc — these rot in days. Describe *where* things live (`src/Controller/`), not how many there are. If you find a count while editing, delete or replace it with a location.
3. **Security-critical facts require a code citation.** Tenant listener priority, RLS status, auth/firewall behaviour, lock mechanics: when a touched doc states one, quote the source (`file:line` or config key) in the doc itself. If doc and code disagree, the code wins and the doc is fixed in the same pass.
4. **Claimed ≠ implemented.** Docs must state what the code *does*, not what is planned. If a mechanism is prepared but inactive (e.g. an RLS template never executed), the doc must say "prepared, NOT active" explicitly. Aspirational statements go to `specs/evolution/`, nowhere else.
5. **Cross-file consistency.** When you change a fact, grep for its other occurrences (`grep -rn` on the key term across `CLAUDE.md`, `docs/`, `*/AGENTS.md`, `specs/courantes/`) and fix or remove them all — half-updated facts created the priority-7/8 contradiction (DOC-01).
6. **Stamp what you verified.** Files carrying `Last verified @ <sha|date>`: bump the stamp **only for files whose claims you actually re-checked this pass** — a stamp is a proof of verification, not a courtesy.

### Drift sweep (mandatory, cheap)

Before writing, for every doc file you are about to touch **and** the zone `AGENTS.md` of the affected zone(s):
- extract its 3–6 strongest factual claims (priorities, statuses "stub/implemented", file paths, commands, versions);
- verify each against the code;
- fix anything wrong **even if unrelated to the current feature** — you are the last line of defence against rot.
If the sweep finds more than trivial drift, say so in the change summary (it is a signal the docs were not maintained).

### Per-subproject README — required sections (adapt to the zone's real style)

Each `<zone>/README.md` must let a newcomer answer "what is this, how do I work here, where's the hard stuff":
1. **Scope & role** — 1–2 paragraphs: what this zone owns, its boundaries (what it must never do — e.g. engine never calls backend, frontend never calls engine directly).
2. **Command recap** — the commands that matter *for this zone*, and the note that backend/engine run **inside Docker** while frontend dev runs **on the host**.
3. **Project recap** — entry point(s), main structure, key mechanisms in one glance.
4. **Structuring / business docs** — a pointer list to the docs that explain the core: e.g. backend → `scripts/generate-schedule.sh` (how to drive a generation), tenant isolation (`docs/TENANT.md`, `docs/RLS.md`), the constraints model, `docs/commands.md`, `docs/ffbb-api.md`; engine → `docs/business.md`, `docs/nominal-flow.md`, `docs/solver-errors.md`, `docs/constraint-vocabulary.md`; frontend → feature workflow (planning work-loop, wizard), component/UX conventions. Transverse vocabulary → `docs/glossary.md` (root).

Respect the distinct working style per zone — don't flatten them into one template. The backend README reads like an API/persistence service, the engine like a solver, the frontend like a UI app.

### specs/ reconciliation (initiales → courantes → evolution)

Three buckets, distinct meaning — keep them true:
- **`initiales/`** — origin specs (v2/v3). **Frozen**: never edit. They are the starting point; the evolution is read as the delta `initiales` → `courantes`.
- **`courantes/`** — what the app does **today**. Must reflect reality: if a courante spec no longer matches the code, **update it**; if the feature was removed, **delete it**. When an `evolution` item ships, its behaviour lands here.
- **`evolution/`** — what the app will do **later** (future/vision). When an item is delivered, **remove it from evolution** (it has graduated into `courantes`).

**Purge des items livrés (mandatory each run) — the MOVE, in four steps.** For every item delivered since the last pass:
1. **Delete the backlog line** (P*x-y*, DOC-n, SEC-n…) from `roadmap.md`. Do **not** flip it to ✅ and leave it there — the roadmap holds no ✅. The id is never reused: a numbering hole means delivered, by design.
2. **Add one trace line** to `etat-des-lieux.md` §3 (date · id · subject · pointer to the `courantes/` file that now holds the behaviour). A delivered item MUST leave a dated trace — deletion without trace is the failure mode this step blocks.
3. **Update the capability map** (`etat-des-lieux.md` §1) if the delivery changed what the app can do. It is a **map**: pointers and one-liners, never behavioural detail.
4. **Detail file** — if a `specs/evolution/*.md` detail file is now fully delivered, delete it (history lives in git) and drop its entry from the roadmap header's active-files list.

**Closed decisions (`etat-des-lieux.md` §2).** When work settles a question **against** an obvious-looking option ("abandoned", "assumed as-is", "deliberately not done"), record it there with its *why*. This is not bookkeeping: an undocumented abandon gets re-proposed every few months. A closed decision is worth as much as a delivery.

**Dette technique (same pipeline):** actionable debt lives as **P4-x backlog lines in the roadmap** (proof `file:line` required); deliberate "won't fix" keeps live in roadmap **§Dette — keeps délibérés** with their rationale. When a debt is resolved: same MOVE ritual. New debt found during any pass → new P4 line, never a separate file.

**Graduation check (mandatory each run, opposable):** for **every item delivered this run**, either **name the `courantes/` file that receives the behaviour** (create or extend it in the same pass) or **state in the change summary why no graduation applies**. Silence is not an option — "updated the roadmap line" alone is the failure mode this check blocks (2026-07-06: school-holidays + public-holidays imports fully specced in roadmap notes, nothing in courantes, snapshot stale across 2 PRs). Then also sweep `specs/evolution/` for older delivered items (merged PR, code present) and graduate them. The audit found shipped features still sitting in evolution/ months later (DOC-04).

**A roadmap line is a line, not a spec.** It holds the problem, its proof (`file:line`), the decision if one was taken, and the traps a future implementer would step into. If you are writing the *solution's* behavioural detail (filters, natural keys, endpoints, edge cases) into a roadmap line, stop: that content belongs in a `specs/evolution/` detail file while open, and in `courantes/` once delivered.

**New open items.** A bug or a need surfaced by the founder or by a review gets a roadmap line **in the right bucket**, with a **verified** proof (`file:line`, read the code — never from memory), an impact and an effort. If the need is not yet framed, say so in the line rather than inventing a solution.

**API-change trigger:** if the work changed any API Platform resource, custom controller route, or DTO exposed over HTTP, regenerate `specs/courantes/openapi-snapshot.json` from the running backend and update `openapi-snapshot.meta.md` (SHA + date). A stale snapshot silently poisons the frontend type-gen pipeline. ⚠ A custom Symfony `#[Route]` is **invisible to the export** unless declared in `App\OpenApi\CustomRoutesOpenApiFactory` — a new custom route means a factory entry **plus** the regen; regenerating alone silently misses it.

No `archive/` bucket — nothing is lost (git + `initiales` hold the history). When reconciling, flag files that are neither current nor future (e.g. one-off process/handoff notes) and propose removing them; do not silently delete large specs — surface the list first.

### Steps
1. **Decide what genuinely changed** among: business behaviour, architecture, conventions, public APIs, subproject scope. **Look before concluding** — list the docs you checked. "Nothing changed" is a finding to justify, not a default.
2. **Drift sweep** on every doc you will touch + affected zone `AGENTS.md` (see above).
3. **READMEs** — refresh the root README and any affected `<zone>/README.md` per the required sections above.
4. **CLAUDE.md** — update only non-obvious facts; keep it a short index (< ~200 lines).
5. **Zone docs** (`<zone>/docs`, `docs/project-map.md`, `docs/testing/`, `docs/technique/`) — update the affected deep-dives; add a how-to/business doc if a new structuring mechanism appeared.
6. **ADR** — if a structural decision was made, add one and reference it in `docs/architecture/adr-index.md`.
7. **specs/** — reconcile per the rules above: update stale `courantes`, **run the graduation check**, **run the MOVE** (roadmap line deleted + trace in `etat-des-lieux.md` §3 + map §1 refreshed), record any **closed decision** in §2, add roadmap lines for newly found open items, apply the API-change trigger, delete removed features, surface dead process notes for confirmation.
8. **Change summary** — list files touched, facts corrected by the drift sweep, and **one line per item delivered this run: → receiving courantes file, or the explicit reason no graduation applies**.

### Doc-only PRs

A PR that touches no code gets no `/code-review` (it would have nothing to review). It gets a **completeness check** instead, written into the PR description: what moved, where it landed, and the proof that nothing was lost. `/code-review` stays mandatory on every PR that touches code.

### Rules
- **No filler.** A future agent must get something wrong without the line.
- **One canonical home**, README points to detail, detail is not copied back.
- **Never edit `specs/initiales/`.** Reconcile `courantes`/`evolution` only.
- **Deletions are surfaced, not silent** — list what you propose to remove and why before removing large specs/docs.
