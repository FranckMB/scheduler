"""Performance gate (PERF-01): the reference dense club must solve within budget.

Marked ``perf`` so it is EXCLUDED from the default suite (addopts -m 'not perf')
and run only on CI main. Without this gate a solver regression (a new quadratic
encoding, an unbounded constraint) would silently blow the 3-minute MVP exit
criterion until a client complains.
"""

from __future__ import annotations

import json
import pathlib
import time

import pytest

from tests.support import solve_payload

FIXTURES_DIR = pathlib.Path(__file__).resolve().parents[1] / "fixtures"

# MVP exit criterion (spec §14.1): the reference club (41 teams · 8 gyms) must
# generate in < 3 minutes. 180 s hard budget, measured on the CI runner.
DENSE_CLUB_BUDGET_SECONDS = 180.0


@pytest.mark.perf
def test_dense_club_completes_under_budget() -> None:
    with open(FIXTURES_DIR / "dense_club.json", encoding="utf-8") as f:
        data = json.load(f)

    start = time.monotonic()
    result = solve_payload(data, timeout=int(DENSE_CLUB_BUDGET_SECONDS))
    elapsed = time.monotonic() - start

    assert result["status"] == "completed"
    assert len(result["slots"]) > 0
    assert elapsed < DENSE_CLUB_BUDGET_SECONDS, (
        f"dense club took {elapsed:.1f}s, over the {DENSE_CLUB_BUDGET_SECONDS:.0f}s budget"
    )
