# Solver — Agent Context

> OR-Tools CP-SAT optimization. 4-file pipeline: model → constraints → objective → result_builder.

## Structure

| File | Role | Lines |
|------|------|-------|
| `model.py` | `ScheduleCpModel` — boolean variables + helper methods | ~230 |
| `constraints.py` | 11 hard constraints (Level 1) | ~400 |
| `objective.py` | Weighted objective function (Level 2) | ~200 |
| `result_builder.py` | Solution → `ScheduleOutputSchema` | ~150 |

## Pipeline

```
ScheduleInputSchema → build_model() → add_constraints() → add_objective() → Solve() → build_result()
```

## Key Conventions

- **Pure functions** — No side effects. Input in, output out.
- **Boolean variables** — `x[team, venue, day, slot]` ∈ {0, 1}
- **Hard constraints** — Level 1. Must be satisfied (feasibility).
- **Soft objective** — Level 2. Maximizes weighted score. Fixed T24 weights.
- **Timeout** — from the input payload `solver_timeout_seconds` (default 650s), applied in `main.py` (`solver.parameters.max_time_in_seconds`). See ADR-0001 (single-pass solve).

## Critical Gotchas

1. **Variable indexing** — `SlotKey = tuple[str, str, int, str]` (team, venue, day, slot). Must match exactly.
2. **Hard locks** — `HARD_LOCK_LEVEL` variables are forced to 1. Cannot be violated.
3. **MVP stubs** — `travel_feasibility` and `required_bridge` return 0 constraints (always satisfied).
4. **Score formula** — `SCORE_FORMULA_VERSION = "T24_LEVEL_2_FIXED_WEIGHTS_V4"`. Changing weights requires version bump.
5. **Constraint aliases** — `constraints.py` has 5 aliases for `add_hard_constraints` (backward compatibility).

## Anti-Patterns

- **Never** modify `solver.parameters.max_time_in_seconds` without updating tests
- **Never** change T24 weights without bumping `SCORE_FORMULA_VERSION`
- **Never** add side effects to constraint functions — they must be pure
- **Never** call `cp_model.CpSolver().Solve()` directly — use the pipeline

## Quick Reference

| Task | Location |
|------|----------|
| Fix infeasibility | `constraints.py` → check hard constraints (Level 1) |
| Improve score | `objective.py` → adjust weights (requires version bump) |
| Add new constraint | `constraints.py` → add function + call in `add_level_1_hard_constraints()` |
| Test golden path | `tests/golden/` → add fixture + test |
