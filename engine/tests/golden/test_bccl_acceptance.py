"""BCCL acceptance golden — the ENGINE'S ÉTALON DE CONFIANCE (V10).

Replays the REAL BCCL payload captured at the founder's generation of 2026-08-15
(``bccl_2026_08_15.json``): the densest club in the repository — 50 teams,
9 venues, Σ sessionsPerWeek = 90 for exactly 90 available places (ZERO margin),
coach-players, reservations/locks, and its well-being rules relaxed to PREFERRED
(« Senior + Compétition ≥ 20:00 » included).

This replaces the old ``bccl_regression`` fixture (75 sessions, June 2026), which
predated dozens of shipped lots (adjustable implicit rules, ADULTE/SENIOR/
COMPETITION tags, locks counted inside the rules, combined targets…) and no longer
described the product. A golden that freezes a vanished world protects nothing.

Under V9 this same payload returned OPTIMAL at **89/90** — U15F3 lost its 2nd
session although both its real slots were free — because a stacked comfort
preference (up to 120) outweighed a bare placed session (21). V10 makes filling
dominate comfort (``missing_session`` −1000 + comfort recalibrated below 21), so
the engine now returns **90/90**.

⚠ There is NO ``status == "failed"`` escape here (unlike the old regression test).
At zero margin every team gets EXACTLY its ``sessionsPerWeek``; if this ever falls
back under 90/90, confidence in the product is lost — name the blocking
constraint, do not relax the assertion.
"""

from __future__ import annotations

import asyncio
import json
import pathlib
from collections import Counter
from typing import Any

import pytest

from app.main import build_schedule
from app.schemas.input_schema import ScheduleInputSchema

FIXTURES_DIR = pathlib.Path(__file__).resolve().parents[1] / "fixtures"

# U15F3 — the team the V9 objective starved of its 2nd session (both slots free).
U15F3_ID = "df07d50c-86dd-44f5-bae3-97b5223eec2a"


def _load_fixture(name: str) -> dict[str, Any]:
    with open(FIXTURES_DIR / f"{name}.json", encoding="utf-8") as f:
        return json.load(f)


# ~2 s in practice (8 adaptive workers); 120 s only guards a true blow-up. The
# assignment is not bit-stable (multi-worker on a >200-complexity problem), but the
# placed-session COUNT is — so we assert counts, never an exact placement or score.
@pytest.mark.timeout(120)
def test_bccl_places_all_90_sessions() -> None:
    """90 sessions requested for 90 places → 90 placed, U15F3 keeps both sessions."""
    data = _load_fixture("bccl_2026_08_15")
    result = asyncio.run(build_schedule(ScheduleInputSchema.model_validate(data)))

    assert result.status == "completed", f"expected completed, got {result.status}"
    assert len(result.slots) == 90, (
        f"the densest club must fill 90/90; got {len(result.slots)}. "
        "A missing session at zero margin means filling no longer dominates comfort — "
        "name the blocking constraint before touching this assertion."
    )

    placed = Counter(str(s.team_id) for s in result.slots)
    assert placed[U15F3_ID] == 2, (
        f"U15F3 must keep both weekly sessions (the V9 starvation case), got {placed[U15F3_ID]}"
    )

    # Le remplissage prime sur le confort : atteindre 90/90 à marge nulle avec les
    # règles de bien-être en PREFERRED transgresse forcément certaines d'entre elles.
    # Ces transgressions DOIVENT remonter au gestionnaire (jamais un 90/90 muet).
    assert result.diagnostics, "relaxed-rule transgressions must surface as diagnostics"
