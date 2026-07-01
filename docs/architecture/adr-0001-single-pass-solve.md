# ADR-0001 — Single-pass solve, no silent fallback

- Status: accepted   Date: 2026-07-01
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
result. The full `solver_timeout_seconds` (default 650s) applies to that single pass.

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
