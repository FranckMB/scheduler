"""Constraint-matrix semantic tests — GENERATED from ``constraint_matrix.MATRIX``.

Non-regression for the §7.1 "constraint semantics" axis (audit P0.1,
ENG-10/11/12/13): every combination the wizard offers is either honored (hard
or soft, asserted on the real production pipeline), rejected with an explicit
diagnostic, or not offered at all (locked by the wizard's own Vitest test).

Scenario vocabulary (mirrors the matrix docstring): two venues ``good``/``bad``,
two days (1 = good Monday, 3 = bad Wednesday), two starts (17:00 bad, 20:00
good for min-start rules). Each cell solves a MIXED scenario (both options
available) and asserts:
- HONORED_HARD → the placement never violates the rule; additionally a
  representative "only the forbidden option exists" scenario must NOT place in
  violation.
- HONORED_SOFT → the objective picks the preferred option; additionally the
  solver STILL PLACES when only the dispreferred option exists (the assertion
  that would have caught ENG-11's escalation to hard).
- WARNING → ``diagnostics`` carries a ``constraint_not_honored`` entry.
"""

from __future__ import annotations

from typing import Any

import pytest

from tests.semantic.constraint_matrix import MATRIX, Expectation, MatrixCell
from tests.support import make_payload, make_venue, solve_payload, team_coach

GOOD_VENUE = "venue-good"
BAD_VENUE = "venue-bad"
GOOD_DAY = 1
BAD_DAY = 3


def _team(team_id: str = "t", sessions: int = 1) -> dict[str, Any]:
    return {
        "id": team_id,
        "sportCategoryId": "cat",
        "priorityTierId": 3,
        "name": team_id,
        "sessionsPerWeek": sessions,
        "isActive": True,
    }


def _fill(config: dict[str, Any]) -> dict[str, Any]:
    """Resolve the {good_venue}/{bad_venue} placeholders of a matrix config."""
    filled: dict[str, Any] = {}
    for key, value in config.items():
        if value == "{good_venue}":
            filled[key] = GOOD_VENUE
        elif value == "{bad_venue}":
            filled[key] = BAD_VENUE
        else:
            filled[key] = value
    return filled


def _constraint(cell: MatrixCell, config: dict[str, Any]) -> dict[str, Any]:
    scope_target = {"TEAM": "t", "COACH": "coach-1", "CLUB": None}[cell.scope]
    return {
        "id": f"mx-{cell.case_id}",
        "scope": cell.scope,
        "scopeTargetId": scope_target,
        "family": cell.family,
        "ruleType": cell.rule_type,
        "name": cell.case_id,
        "config": ({**config, "coachId": "coach-1"} if cell.scope == "COACH" else config),
        "sortOrder": 0,
        "isActive": True,
    }


def _mixed_payload(cell: MatrixCell) -> dict[str, Any]:
    """Both the good and the bad option exist — the rule decides the outcome."""
    config = _fill(cell.config)
    constraints = [_constraint(cell, config)]
    if cell.scope == "COACH":
        constraints.append(team_coach("tc", "t", "coach-1"))
    if cell.family == "TIME":
        venues = [make_venue(GOOD_VENUE, [(GOOD_DAY, "17:00"), (GOOD_DAY, "20:00")])]
    else:
        venues = [
            make_venue(GOOD_VENUE, [(GOOD_DAY, "18:00")]),
            make_venue(BAD_VENUE, [(BAD_DAY, "18:00")]),
        ]
    return make_payload(teams=[_team()], venues=venues, constraints=constraints)


def _only_bad_payload(cell: MatrixCell) -> dict[str, Any]:
    """Only the dispreferred/forbidden option exists."""
    config = _fill(cell.config)
    constraints = [_constraint(cell, config)]
    if cell.scope == "COACH":
        constraints.append(team_coach("tc", "t", "coach-1"))
    if cell.family == "TIME":
        venues = [make_venue(GOOD_VENUE, [(GOOD_DAY, "17:00")])]  # violates minStartTime 19:00
    else:
        venues = [make_venue(BAD_VENUE, [(BAD_DAY, "18:00")])]
    return make_payload(teams=[_team()], venues=venues, constraints=constraints)


def _slots(result: dict[str, Any]) -> list[dict[str, Any]]:
    return [s for s in result["slots"] if s["teamId"] == "t"]


def _violates(cell: MatrixCell, slot: dict[str, Any]) -> bool:
    """Does a placed slot violate the cell's rule? Thresholds are DERIVED from
    the cell's own config so editing a matrix cell can never desynchronize the
    predicate from the scenario."""
    key = cell.config_key
    config = _fill(cell.config)
    if key == "minStartTime":
        return str(slot["startTime"])[:5] < str(config["minStartTime"])
    if key in ("forbiddenDays", "unavailableDays"):
        return int(slot["dayOfWeek"]) in set(config[key])
    if key in ("preferredVenueId", "forcedVenueId"):
        return slot["venueId"] != config[key]
    if key == "forbiddenVenueId":
        return slot["venueId"] == config["forbiddenVenueId"]
    if key == "forcedDays":
        return int(slot["dayOfWeek"]) not in set(config["forcedDays"])
    raise AssertionError(f"no violation predicate for {key}")


OFFERED = [c for c in MATRIX if c.expected in (Expectation.HONORED_HARD, Expectation.HONORED_SOFT)]
WARNED = [c for c in MATRIX if c.expected is Expectation.WARNING]
NOT_OFFERED = [c for c in MATRIX if c.expected is Expectation.NOT_OFFERED]


@pytest.mark.parametrize("cell", OFFERED, ids=lambda c: c.case_id)
def test_mixed_scenario_rule_steers_the_placement(cell: MatrixCell) -> None:
    """Hard AND soft: with both options available, the rule decides."""
    result = solve_payload(_mixed_payload(cell))

    assert result["status"] == "completed"
    placed = _slots(result)
    assert placed, f"{cell.case_id}: team not placed in a mixed scenario"
    for slot in placed:
        assert not _violates(cell, slot), f"{cell.case_id}: rule not honored on {slot}"


@pytest.mark.parametrize(
    "cell",
    [c for c in OFFERED if c.expected is Expectation.HONORED_SOFT],
    ids=lambda c: c.case_id,
)
def test_soft_rule_never_blocks_feasibility(cell: MatrixCell) -> None:
    """A preference must yield when only the dispreferred option exists
    (the assertion that would have caught ENG-11's hard escalation)."""
    result = solve_payload(_only_bad_payload(cell))

    assert result["status"] == "completed"
    assert _slots(result), f"{cell.case_id}: a soft rule blocked the placement"


@pytest.mark.parametrize(
    "cell",
    [c for c in OFFERED if c.expected is Expectation.HONORED_HARD],
    ids=lambda c: c.case_id,
)
def test_hard_rule_never_places_in_violation(cell: MatrixCell) -> None:
    """When only the forbidden option exists, a HARD rule leaves the team
    unplaced (with diagnostics) — never placed in violation."""
    result = solve_payload(_only_bad_payload(cell))

    for slot in _slots(result):
        assert not _violates(cell, slot), f"{cell.case_id}: hard rule violated on {slot}"


@pytest.mark.parametrize("cell", WARNED, ids=lambda c: c.case_id)
def test_unhonorable_rule_emits_an_explicit_diagnostic(cell: MatrixCell) -> None:
    result = solve_payload(_mixed_payload(cell))

    entries = [d for d in result.get("diagnostics", []) if d.get("type") == "constraint_not_honored"]
    assert entries, f"{cell.case_id}: expected a constraint_not_honored diagnostic"


@pytest.mark.parametrize("cell", NOT_OFFERED, ids=lambda c: c.case_id)
def test_not_offered_cells_are_locked_by_the_ui_test(cell: MatrixCell) -> None:
    """Documented as not offered — the wizard Vitest test freezes the UI offer;
    nothing to assert engine-side."""
    assert not cell.offered_by_ui


# --- ENG-13 (dedicated): multiple coach constraints are UNIONed ----------------

def test_multiple_coach_constraints_union_not_last_wins() -> None:
    venues = [make_venue("v", [(1, "18:00"), (3, "18:00"), (5, "18:00")])]
    constraints = [
        team_coach("tc", "t", "coach-1"),
        {
            "id": "cu-1", "scope": "COACH", "scopeTargetId": "coach-1",
            "family": "COACH_AVAILABILITY", "ruleType": "HARD", "name": "indispo lundi",
            "config": {"coachId": "coach-1", "unavailableDays": [1]}, "sortOrder": 0, "isActive": True,
        },
        {
            "id": "cu-2", "scope": "COACH", "scopeTargetId": "coach-1",
            "family": "COACH_AVAILABILITY", "ruleType": "HARD", "name": "indispo mercredi",
            "config": {"coachId": "coach-1", "unavailableDays": [3]}, "sortOrder": 0, "isActive": True,
        },
    ]
    result = solve_payload(make_payload(teams=[_team()], venues=venues, constraints=constraints))

    assert result["status"] == "completed"
    days = {int(s["dayOfWeek"]) for s in _slots(result)}
    assert days == {5}, f"both Monday AND Wednesday must be blocked, got {days}"


# --- ENG-12 (dedicated): legacy BONUS rows are honored as PREFERRED ------------

def test_legacy_bonus_facility_behaves_as_preferred() -> None:
    venues = [make_venue(GOOD_VENUE, [(1, "18:00")]), make_venue(BAD_VENUE, [(3, "18:00")])]
    constraints = [{
        "id": "bonus-1", "scope": "TEAM", "scopeTargetId": "t",
        "family": "FACILITY", "ruleType": "BONUS", "name": "legacy bonus",
        "config": {"forbiddenVenueId": BAD_VENUE}, "sortOrder": 0, "isActive": True,
    }]
    result = solve_payload(make_payload(teams=[_team()], venues=venues, constraints=constraints))

    assert result["status"] == "completed"
    placed = _slots(result)
    assert placed and all(s["venueId"] == GOOD_VENUE for s in placed)


# --- Review NR: two soft avoid-day rules must BOTH steer (no mutual cancel) ----

def test_two_soft_avoid_day_rules_union_not_cancel() -> None:
    venues = [make_venue("v", [(1, "18:00"), (3, "18:00"), (5, "18:00")])]
    constraints = [
        {
            "id": "ad-1", "scope": "TEAM", "scopeTargetId": "t",
            "family": "DAY", "ruleType": "PREFERRED", "name": "éviter lundi",
            "config": {"forbiddenDays": [1]}, "sortOrder": 0, "isActive": True,
        },
        {
            "id": "ad-2", "scope": "TEAM", "scopeTargetId": "t",
            "family": "DAY", "ruleType": "PREFERRED", "name": "éviter mercredi",
            "config": {"forbiddenDays": [3]}, "sortOrder": 0, "isActive": True,
        },
    ]
    result = solve_payload(make_payload(teams=[_team()], venues=venues, constraints=constraints))

    assert result["status"] == "completed"
    days = {int(s["dayOfWeek"]) for s in _slots(result)}
    assert days == {5}, f"both avoided days must steer away, got {days}"


# --- Review NR: two availableDays whitelists INTERSECT (never block every day) -

def test_two_available_days_whitelists_intersect_not_block_everything() -> None:
    venues = [make_venue("v", [(1, "18:00"), (2, "18:00"), (5, "18:00")])]
    constraints = [
        team_coach("tc", "t", "coach-1"),
        {
            "id": "av-1", "scope": "COACH", "scopeTargetId": "coach-1",
            "family": "COACH_AVAILABILITY", "ruleType": "HARD", "name": "dispo lun+mar",
            "config": {"coachId": "coach-1", "availableDays": [1, 2]}, "sortOrder": 0, "isActive": True,
        },
        {
            "id": "av-2", "scope": "COACH", "scopeTargetId": "coach-1",
            "family": "COACH_AVAILABILITY", "ruleType": "HARD", "name": "dispo mar+ven",
            "config": {"coachId": "coach-1", "availableDays": [2, 5]}, "sortOrder": 0, "isActive": True,
        },
    ]
    result = solve_payload(make_payload(teams=[_team()], venues=venues, constraints=constraints))

    # Intersection = Tuesday only; the union-of-complements bug blocked EVERY
    # day (INFEASIBLE for a schedulable club).
    assert result["status"] == "completed"
    days = {int(s["dayOfWeek"]) for s in _slots(result)}
    assert days == {2}, f"whitelists must intersect to Tuesday, got {days}"


# --- Review NR: cockpit venue_closed rows never raise a false warning ---------

def test_cockpit_venue_closed_marker_raises_no_false_warning() -> None:
    venues = [make_venue("v", [(1, "18:00")])]
    constraints = [{
        "id": "vc-1", "scope": "FACILITY", "scopeTargetId": "v",
        "family": "FACILITY", "ruleType": "HARD", "name": "Gymnase fermé",
        "config": {"type": "venue_closed"}, "sortOrder": 0, "isActive": True,
    }]
    result = solve_payload(make_payload(teams=[_team()], venues=venues, constraints=constraints))

    assert result["status"] == "completed"
    warnings = [d for d in result.get("diagnostics", []) if d.get("type") == "constraint_not_honored"]
    assert warnings == [], "the closure marker is enforced via the backend expansion — no false alarm"


# --- Review NR: a soft avoid-venue must not displace another team's preference -

def test_soft_avoid_venue_does_not_outbid_an_explicit_preference() -> None:
    # One contested slot at venue Y: team B explicitly prefers Y, team A merely
    # avoids X. The complement-bonus bug tied both at the same weight.
    venues = [make_venue("venue-y", [(1, "18:00")]), make_venue("venue-x", [(3, "18:00")])]
    constraints = [
        {
            "id": "avoid-x", "scope": "TEAM", "scopeTargetId": "a",
            "family": "FACILITY", "ruleType": "PREFERRED", "name": "A évite X",
            "config": {"forbiddenVenueId": "venue-x"}, "sortOrder": 0, "isActive": True,
        },
        {
            "id": "prefer-y", "scope": "TEAM", "scopeTargetId": "b",
            "family": "FACILITY", "ruleType": "PREFERRED", "name": "B préfère Y",
            "config": {"preferredVenueId": "venue-y"}, "sortOrder": 0, "isActive": True,
        },
    ]
    result = solve_payload(make_payload(teams=[_team("a"), _team("b")], venues=venues, constraints=constraints))

    assert result["status"] == "completed"
    y_teams = {s["teamId"] for s in result["slots"] if s["venueId"] == "venue-y"}
    assert "b" in y_teams, "the explicit preference must win the contested slot"
