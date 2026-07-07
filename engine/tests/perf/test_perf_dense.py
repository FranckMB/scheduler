"""Performance gate (PERF-01): the reference dense clubs must solve within budget.

Marked ``perf`` so it is EXCLUDED from the default suite (addopts -m 'not perf')
and run only on CI main. Without this gate a solver regression (a new quadratic
encoding, an unbounded constraint) would silently blow the 3-minute MVP exit
criterion until a client complains.

Ratchet (2026-07-07): the multi-worker fix (``_adaptive_workers``) closes the
optimality proof on the stall-prone tier in seconds instead of burning the full
600 s budget (dense: 296 complexity, BCCL: 441). The budgets below are tightened
to 60 s so a regression back to the single-worker prove-stall (measured 612 s on
BCCL) fails the gate instead of merely running long. 60 s (vs the ~2-10 s real
solve) leaves ample margin for a slow/variable CI runner.
"""

from __future__ import annotations

import json
import pathlib
import time

import pytest

from tests.support import solve_payload

FIXTURES_DIR = pathlib.Path(__file__).resolve().parents[1] / "fixtures"

# Post-fix ratchet: the large tier (complexity > 200) proves optimal in seconds
# with the 8-worker portfolio. 60 s catches any regression to the prove-stall.
LARGE_CLUB_BUDGET_SECONDS = 60.0


@pytest.mark.perf
def test_dense_club_completes_under_budget() -> None:
    """Dense club (37 teams · 8 gyms = 296): large tier, 8 workers."""
    with open(FIXTURES_DIR / "dense_club.json", encoding="utf-8") as f:
        data = json.load(f)

    start = time.monotonic()
    result = solve_payload(data, timeout=int(LARGE_CLUB_BUDGET_SECONDS))
    elapsed = time.monotonic() - start

    assert result["status"] == "completed"
    assert len(result["slots"]) > 0
    assert elapsed < LARGE_CLUB_BUDGET_SECONDS, (
        f"dense club took {elapsed:.1f}s, over the {LARGE_CLUB_BUDGET_SECONDS:.0f}s budget"
    )


@pytest.mark.perf
def test_bccl_regression_completes_under_budget() -> None:
    """BCCL (49 teams · 9 gyms = 441, 55 soft venue preferences) — the exact
    profile that stalled the single default worker for 612 s. Must now finish
    well under budget with the multi-worker optimality proof."""
    with open(FIXTURES_DIR / "bccl_regression.json", encoding="utf-8") as f:
        data = json.load(f)

    start = time.monotonic()
    result = solve_payload(data, timeout=int(LARGE_CLUB_BUDGET_SECONDS))
    elapsed = time.monotonic() - start

    assert result["status"] == "completed"
    assert len(result["slots"]) > 0
    assert elapsed < LARGE_CLUB_BUDGET_SECONDS, (
        f"BCCL took {elapsed:.1f}s, over the {LARGE_CLUB_BUDGET_SECONDS:.0f}s budget "
        "(regression to the single-worker prove-stall?)"
    )
