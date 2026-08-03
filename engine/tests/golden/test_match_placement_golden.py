"""Golden of the match placement (P1-4 PR D): seed 42 + 1 worker ⇒ bit-stable
output. Pins the EXACT placements of the realistic weekend so any change to
the weights (D5), the candidate geometry or the solver parameters shows up as
a diff a human must acknowledge — the weights are product decisions (ADR-0003).

The pinned optimum reads: a gap-free chain 13:15→22:15 on Mateo ending on the
manual 20:30 anchor, PNM→SF1 chained back-to-back (their declared link), DF2
opening the day. PNM trades its exact 15:30 habit for the chain + compaction —
the documented SOFT arbitration, not a bug.
"""

from __future__ import annotations

from datetime import time

from app.schemas.match_input_schema import MatchPlacementInputSchema
from app.solver.match_placement import solve_match_placement
from tests.semantic.test_match_placement_semantics import wire_payload


def test_realistic_weekend_golden_placements() -> None:
    result = solve_match_placement(MatchPlacementInputSchema.model_validate(wire_payload()))

    assert result["unplaced"] == []
    placements = {p["matchId"]: (p["venueId"], p["kickoff"]) for p in result["placements"]}
    assert placements == {
        "m-df2": ("mateo", time(13, 45)),
        "m-sf1": ("mateo", time(16, 0)),
        "m-pnm": ("mateo", time(18, 15)),
    }
