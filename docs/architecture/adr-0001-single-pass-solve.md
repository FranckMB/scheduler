# ADR-0001 — Single-pass solve, no silent fallback

- Status: accepted   Date: 2026-07-01
- Amended: 2026-07-07 — clarifies "single pass" vs the two-PHASE lexicographic
  objective optimisation now in production (see Amendment below). 2026-07-10 —
  generation complexity cap (A10). 2026-08-17 — the lexicographic objective grows a
  third, optional tier: generation **stability** (P3-21).
- Zone: engine (`app/main.py`, `app/solver/`)

## Context

The CP-SAT model includes two "social" hard constraints that can make an
otherwise-feasible instance INFEASIBLE: coach rest-day (every coach ≥ 1 rest day)
and salarié distribution (≥ 1 employed coach per day).

The codebase carries the plumbing for a **two-pass fallback**: a second solve that
relaxes those two constraints (`skip_rest_day_and_distribution=True` in
`add_level_1_hard_constraints`, surfaced via `fallback_used` in `build_result`).
That fallback is **not wired into production** — `main.py` always calls the solver
once with all hard constraints and passes `fallback_used=False`. Only the tests
exercise the relaxed path.

## Decision

Keep a **single solve pass with all HARD constraints active**. If the solver returns
INFEASIBLE, `build_result` produces `status="failed"` with conflict diagnostics —
the engine never silently drops rest-day or distribution constraints to force a
result. The phase-1 budget is **adaptive** (60/180/600 s tiers by `n_teams×n_venues`), with the payload `solver_timeout_seconds` (default 650 s) acting as a ceiling only; the chaining phase adds at most `CHAINING_PHASE_MAX_SECONDS` (10 s). *(Wording corrected 2026-07-07 — the original claimed the full payload budget applied.)*

The fallback parameters (`skip_rest_day_and_distribution`, `fallback_used`) are kept
in the signatures as a documented, tested extension point, but remain dormant.

## Consequences

- An over-constrained instance **fails loudly** (with diagnostics) instead of
  returning a schedule that quietly violates the social constraints — honest and
  predictable for the caller.
- Turning the fallback on later is a deliberate, separate change (its own ADR):
  it would alter solver behaviour, output semantics and diagnostics, so it is a
  feature decision, not a cleanup.

## Alternatives considered

- **Auto two-pass fallback** (relax on INFEASIBLE, produce a degraded plan):
  rejected as the default — it silently drops constraints the club asked for, and
  a degraded plan presented as success is misleading. Left as an opt-in extension
  point for a future, explicit decision.

## Amendment (2026-07-07) — "single pass" ≠ "single solver call"

Production now runs a **two-phase lexicographic optimisation** (`main.py`,
"Phase 1/Phase 2" comments): phase 1 solves the PLACEMENT objective (chaining
terms built but excluded); phase 2 locks the phase-1 objective value as a hard
bound (`placement_expression >= value`), warm-starts from the phase-1 solution
and maximises the chaining bonus under a hard time cap (10 s). Phase 2 runs on
OPTIMAL **or FEASIBLE** phase-1 outcomes: after a phase-1 timeout the locked
bound is the best-FOUND placement value, not a proven optimum — chaining can
then only improve on that incumbent, never degrade it.

The **search effort** is adaptive alongside the budget: `_adaptive_workers` mirrors
the timeout tiers — 1 worker below a complexity of 200 (deterministic, the golden
fixtures depend on it), 8 above. The single worker *finds* the optimum quickly on
dense soft-preference problems but cannot *prove* it; the portfolio closes the
proof. Accepted consequence: above the threshold the **assignment** is no longer
deterministic — the objective **value** is. This changes neither the feasibility
guarantee nor the no-relaxation decision.

This does **not** contradict the decision of this ADR — and the ADR's title must
be read accordingly:

- **"Single pass" means: one attempt with ALL hard constraints active, no
  relaxation fallback.** Neither phase drops rest-day, distribution or any other
  HARD constraint; INFEASIBLE still fails loudly with diagnostics
  (`status="failed"`). The dormant `skip_rest_day_and_distribution` extension
  point remains dormant.
- The two phases are an **objective-layering technique** (placement solved
  first — proven optimal when phase 1 completes, best-found on timeout;
  chaining best-effort on top), not a second chance at feasibility. Phase 2
  can only improve chaining, never degrade the locked placement value.

Any future change that relaxes constraints between attempts remains a separate,
explicit ADR as decided above.

## Amendment (2026-07-10) — generation complexity cap (A10)

The single-pass solve has no relaxation fallback, so an over-large problem simply
runs the full solver timeout (adaptive 60/180/600 s) and holds the club's single
generation slot the whole time. To bound this "generation bomb" **without touching
the solver or its no-relaxation guarantee**, a complexity cap is enforced at two
boundaries (generous — ~10× a large FFBB club; only a genuine bomb trips them):

- **Backend pre-check** (`GenerationComplexityGuard`, called by
  `GenerateScheduleController` before dispatch): counts the club/season's teams,
  venues, coaches, availability slots and **permanent** constraints (the exact set
  the base-plan payload carries — dated overlay rows are excluded), and rejects
  with **422** before the message is ever queued — teams ≤ 200, venues ≤ 50,
  coaches ≤ 200, slots ≤ 3000, constraints ≤ 500, and `teams × venues ≤ 2000` (the
  dominant CP-SAT model-size driver).
- **Engine input schema** (`input_schema.py` `max_length` on every request list,
  plus a `model_validator` bounding the TOTAL availability slots across venues):
  defense-in-depth — an oversized payload reaching `/generate` by any path is
  rejected with 422 before CP-SAT builds. Dimensions the backend does not count
  (slot_templates, priority_tiers, per-team tags) are bounded here only; they
  reject instantly at validation, so the generation lock is released in
  milliseconds — not a DoS.

This is a defensive input bound, not a solver-behaviour change: it neither relaxes
constraints nor alters the single-pass decision above.

## Amendment (2026-08-17) — third lexicographic tier: generation stability (P3-21)

The two-phase objective-layering technique (2026-07-07 amendment) grows a **third**,
strictly-lower tier inside phase 2: **stability**, i.e. "at equal score, prefer the
placement the previous generation already chose." An optional input field,
`previousAssignments` (contract 2.11, `engine/app/schemas/input_schema.py`), lists the
prior generation's `(teamId, venueId, dayOfWeek, startTime)` placements; each CP-SAT
variable whose key matches one earns `STABILITY_TERM_WEIGHT = 1` in phase 2
(`build_stability_terms`, `objective.py`).

The separation from chaining is **lexicographic by construction**, not by chance:
phase 2 maximises `placement + CHAINING_STABILITY_MULTIPLIER(4096) × chaining +
stability`. The maximum possible stability mass is `STABILITY_TERM_WEIGHT × cap(
previousAssignments) = 1 × 2000 = 2000`, strictly below `4096` — the smallest possible
chaining increment (one `CHAINING_TIER_WEIGHTS["D"] = 1` point, amplified by the
multiplier) always outweighs the *entire* stacked stability mass. Stability can
therefore never overturn a chaining trade-off, let alone a placement one (placement is
already locked to the phase-1 optimum before phase 2 runs, per the 2026-07-07
amendment) — it only breaks EXACT ties on (placement, chaining). A HARD-locked slot
has no CP-SAT variable at all (`build_model` skips it), so it can never be double-paid
by the stability term.

Because phase 2's raw `ObjectiveValue()` would otherwise carry the `4096×` multiplier
and the stability mass, the reported score is recomputed at the *original* weights
(placement + natural-weight chaining, stability excluded — `model.
reported_score_override`, read by `result_builder.build_result` in place of
`solver.ObjectiveValue()` when stability terms were used) so that
`SCORE_FORMULA_VERSION` stays meaningful and unchanged.

`previousAssignments` absent or empty takes the historical phase-2 objective (`placement
+ chaining`) byte-for-byte — this amendment adds a third tier that stays inert on any
first generation, exactly as A10 (2026-07-10) added a dormant-until-tripped input bound:
neither relaxes a HARD constraint nor changes the no-relaxation decision above.

The backend PR that feeds this field (P3-21 PR B, same day) emits `previousAssignments`
from the version the manager is *looking at* when they regenerate — `sourceScheduleId`
on `GenerateScheduleMessage`, set by `RegenerateController` to `$source->getId()`, never
to the plan's latest version. `GenerateScheduleHandler` resolves it (explicit source of
the same lineage, else the plan's latest `COMPLETED` version, else none) and injects it
into the solver payload *after* `snapshotHash` is computed — the previous placement is a
convergence preference, not a structure fact, so it must never enter the hash that gates
the "structure changed" signal. Detail: `specs/courantes/backend-inventory.md` §route
`regenerate`, `specs/courantes/engine-inventory.md` §POST /generate, §5 Solver.
