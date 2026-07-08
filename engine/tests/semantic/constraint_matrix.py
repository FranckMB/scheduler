"""Machine representation of the UI↔engine constraint matrix (audit P0.1).

Single source of truth for WHAT the wizard offers and HOW the engine must
treat each combination. ``test_constraint_matrix.py`` is GENERATED from this
table (one parametrized case per cell) — any wizard evolution (new family,
ruleType or config key) must update this matrix first, which forces the
matching semantic test to exist. The human-readable twin lives in
``docs/architecture/constraint-matrix.md``.

Expectations:
- HONORED_HARD  — the solver never violates the rule (a violating placement is
                  impossible; over-constrained → unplaced/diagnostic, never a
                  silent violation).
- HONORED_SOFT  — the rule steers the objective (preferred option wins in a
                  mixed scenario) but NEVER blocks feasibility (the solver
                  still places when only the dispreferred option exists).
- WARNING       — the engine cannot honor the rule and says so through a
                  ``constraint_not_honored`` diagnostics entry.
- NOT_OFFERED   — the UI does not offer the combination (locked by the wizard
                  Vitest test); the engine may still normalize legacy rows.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from enum import Enum
from typing import Any


class Expectation(Enum):
    HONORED_HARD = "honored_hard"
    HONORED_SOFT = "honored_soft"
    WARNING = "warning"
    NOT_OFFERED = "not_offered"


@dataclass(frozen=True)
class MatrixCell:
    family: str
    rule_type: str
    config_key: str
    scope: str
    expected: Expectation
    offered_by_ui: bool
    note: str = ""
    # Sample config the wizard would emit for this cell; "{good}"/"{bad}"
    # placeholders are filled by the test scenario builder.
    config: dict[str, Any] = field(default_factory=dict)

    @property
    def case_id(self) -> str:
        return f"{self.family}-{self.rule_type}-{self.config_key}-{self.scope}"


# Scenario vocabulary shared with the test builder: two venues (good/bad), two
# days (1=good Monday, 3=bad Wednesday), two start times (17:00 good, 20:00 bad).
MATRIX: tuple[MatrixCell, ...] = (
    # --- TIME minStartTime/maxStartTime ---------------------------------------
    MatrixCell("TIME", "HARD", "minStartTime", "TEAM", Expectation.HONORED_HARD, True,
               config={"minStartTime": "19:00"}),
    MatrixCell("TIME", "LOCK", "minStartTime", "TEAM", Expectation.HONORED_HARD, True,
               note="LOCK on a time rule = fixed window, enforced like HARD",
               config={"minStartTime": "19:00"}),
    MatrixCell("TIME", "PREFERRED", "minStartTime", "TEAM", Expectation.HONORED_SOFT, True,
               config={"minStartTime": "19:00"}),
    # --- DAY forbiddenDays ------------------------------------------------------
    MatrixCell("DAY", "HARD", "forbiddenDays", "TEAM", Expectation.HONORED_HARD, True,
               config={"forbiddenDays": [3]}),
    MatrixCell("DAY", "LOCK", "forbiddenDays", "TEAM", Expectation.HONORED_HARD, True,
               config={"forbiddenDays": [3]}),
    MatrixCell("DAY", "PREFERRED", "forbiddenDays", "TEAM", Expectation.HONORED_SOFT, True,
               note="ENG-10 fix: soft 'avoid these days' (was a silent placebo)",
               config={"forbiddenDays": [3]}),
    # --- FACILITY preferredVenueId ---------------------------------------------
    MatrixCell("FACILITY", "HARD", "preferredVenueId", "TEAM", Expectation.HONORED_HARD, True,
               note="HARD 'preferred' = forced venue",
               config={"preferredVenueId": "{good_venue}"}),
    MatrixCell("FACILITY", "LOCK", "preferredVenueId", "TEAM", Expectation.HONORED_HARD, True,
               note="ENG-12 fix: LOCK venue = fixed venue (was dead)",
               config={"preferredVenueId": "{good_venue}"}),
    MatrixCell("FACILITY", "PREFERRED", "preferredVenueId", "TEAM", Expectation.HONORED_SOFT, True,
               config={"preferredVenueId": "{good_venue}"}),
    # --- FACILITY forbiddenVenueId ---------------------------------------------
    MatrixCell("FACILITY", "HARD", "forbiddenVenueId", "TEAM", Expectation.HONORED_HARD, True,
               config={"forbiddenVenueId": "{bad_venue}"}),
    MatrixCell("FACILITY", "LOCK", "forbiddenVenueId", "TEAM", Expectation.HONORED_HARD, True,
               config={"forbiddenVenueId": "{bad_venue}"}),
    MatrixCell("FACILITY", "PREFERRED", "forbiddenVenueId", "TEAM", Expectation.HONORED_SOFT, True,
               note="ENG-11 fix: soft 'avoid this venue' (was escalated to a hard ban)",
               config={"forbiddenVenueId": "{bad_venue}"}),
    # --- COACH_AVAILABILITY (UI forces HARD) -----------------------------------
    MatrixCell("COACH_AVAILABILITY", "HARD", "unavailableDays", "COACH", Expectation.HONORED_HARD, True,
               note="ENG-13 fix: multiple constraints on one coach are UNIONed",
               config={"unavailableDays": [3]}),
    MatrixCell("COACH_AVAILABILITY", "PREFERRED", "unavailableDays", "COACH", Expectation.WARNING, False,
               note="UI forces HARD; a legacy soft row is enforced hard + INFO diagnostic",
               config={"unavailableDays": [3]}),
    MatrixCell("COACH_AVAILABILITY", "HARD", "availableDays", "COACH", Expectation.HONORED_HARD, True,
               note="wizard 'coach disponible uniquement' = whitelist (INTERSECTION per coach)",
               config={"availableDays": [1]}),
    # --- Legacy / guard cells ---------------------------------------------------
    MatrixCell("DAY", "BONUS", "forbiddenDays", "TEAM", Expectation.HONORED_SOFT, False,
               note="ENG-12: BONUS removed from the UI; legacy rows normalize to PREFERRED",
               config={"forbiddenDays": [3]}),
    MatrixCell("FACILITY", "BONUS", "forbiddenVenueId", "TEAM", Expectation.HONORED_SOFT, False,
               note="legacy BONUS → PREFERRED (soft avoid)",
               config={"forbiddenVenueId": "{bad_venue}"}),
    MatrixCell("DAY", "HARD", "forbiddenDays", "CLUB", Expectation.WARNING, False,
               note="target-less scope: backend expands CLUB→teams; a stray one warns",
               config={"forbiddenDays": [3]}),
    MatrixCell("FACILITY", "PREFERRED", "preferredVenueId", "CLUB", Expectation.WARNING, False,
               note="target-less facility rule cannot be applied — explicit warning",
               config={"preferredVenueId": "{good_venue}"}),
    # --- FACILITY forcedVenueId / DAY allowedDays (wizard "impose"/"uniquement") --
    # The wizard edit form offers these as always-hard modes so seeded "must play
    # here" / "this day only" rules (SM4→Jean Vilar, Veterans vendredi) round-trip
    # faithfully instead of being downgraded to a soft preference.
    MatrixCell("FACILITY", "HARD", "forcedVenueId", "TEAM", Expectation.HONORED_HARD, True,
               note="wizard 'impose' = forced venue (must play here), always hard",
               config={"forcedVenueId": "{good_venue}"}),
    # ENG-16: the wizard "uniquement" maps to allowedDays (a WHITELIST: the engine
    # forbids every non-listed day) — NOT forcedDays, which only means "at least one
    # session on these days" and leaves the other days open (silently violating
    # "uniquement" for a multi-session team).
    MatrixCell("DAY", "HARD", "allowedDays", "TEAM", Expectation.HONORED_HARD, True,
               note="wizard 'uniquement' = whitelist, only these days allowed, always hard",
               config={"allowedDays": [1]}),
    # --- Understood by the engine but never emitted by the wizard ---------------
    MatrixCell("DAY", "HARD", "forcedDays", "TEAM", Expectation.NOT_OFFERED, False,
               note="engine-only: 'at least one session on these days' (≠ 'only'); the wizard emits allowedDays"),
    MatrixCell("DAY", "PREFERRED", "preferredDays", "TEAM", Expectation.NOT_OFFERED, False,
               note="objective reads it, wizard never emits it (ENG-10 root)"),
    MatrixCell("FACILITY_CAPACITY", "HARD", "maxTeams", "TEAM", Expectation.NOT_OFFERED, False,
               note="emitted by the backend (canSplit), not the wizard"),
)
