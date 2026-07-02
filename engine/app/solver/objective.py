"""Level-2 linear objective for the OR-Tools CP-SAT scheduler model.

The T24 score formula is intentionally fixed. Any change to one of these
weights must be accompanied by a new SCORE_FORMULA_VERSION.
"""

from __future__ import annotations

from collections.abc import Iterable, Mapping, Sequence
from dataclasses import dataclass
from types import MappingProxyType
from typing import Any

from .helpers import MISSING, assignment_team_id, assignment_var, get_field, scalar_id

AssignmentLike = Any
BoolVarLike = Any

SCORE_FORMULA_VERSION = "T24_LEVEL_2_FIXED_WEIGHTS_V4"

LEVEL_2_OBJECTIVE_WEIGHTS = MappingProxyType(
    {
        "S": 10000,
        "A": 1000,
        "B": 100,
        "session_count": 20,
        "preferred": 60,
        "preferred_day": 30,
        "C": 10,
        "D": 1,
        "rest": 3,
    }
)

UNPLACED_PENALTY = 100000


def is_team_satisfied_by_hard_locks(
    team_id: str,
    locked_slots: Iterable[Mapping[str, Any]],
    sessions_per_week: int,
) -> bool:
    """Return True if the team's weekly sessions are fully covered by HARD locks.

    Each entry in *locked_slots* represents one HARD-locked session for a team.
    If the count of HARD locks for *team_id* is greater than or equal to
    *sessions_per_week*, the team is fully satisfied and must NOT receive the
    ``-UNPLACED_PENALTY`` term in the objective.
    """

    hard_count = sum(
        1
        for slot in locked_slots
        if str(slot.get("team_id", "")) == str(team_id)
    )
    return hard_count >= sessions_per_week


# Small INTEGER tiebreaker weights for the same-coach same-venue chaining bonus.
# Two ceilings keep it a *bonus*, never a decider:
#   1. A placed session is worth tier(≥1) + session_count(20) = 21 at minimum,
#      so a chaining weight < 21 can never drop a session to chain others.
#   2. The smallest gap between adjacent tiers' placement values is C−D = 9
#      (30 vs 21), so a weight ≤ 8 can never steal a slot from a higher tier —
#      S/A/B priority (gaps 90/900) is always safe; only the C↔D arbitration,
#      which the club treats as indifferent, can wobble.
# Hence max weight = 8. Order preserved (S>A>B>C>D); the pair's weight is that
# of its highest tier (chaining SF1(S)+U15F(B) → 8, taken on the S).
CHAINING_TIER_WEIGHTS = MappingProxyType(
    {
        "S": 8,
        "A": 6,
        "B": 4,
        "C": 2,
        "D": 1,
    }
)

TIER_WEIGHT_NAMES = ("S", "A", "B", "C", "D")
BONUS_WEIGHT_NAMES = (
    "preferred",
    "preferred_day",
    "rest",
    "session_count",
)

_PRIORITY_TIER_FIELDS = (
    "priority_tier",
    "priorityTier",
    "priority_tier_id",
    "priorityTierId",
    "tier",
    "tier_id",
    "tierId",
)

_PRIORITY_RANK_FIELDS = ("priority_rank", "priorityRank", "tier_rank", "tierRank")

_TIER_ALIASES = {
    "S": "S",
    "A": "A",
    "B": "B",
    "C": "C",
    "D": "D",
    "TIER_S": "S",
    "TIER_A": "A",
    "TIER_B": "B",
    "TIER_C": "C",
    "TIER_D": "D",
}

_ONE_BASED_TIER_IDS = {1: "S", 2: "A", 3: "B", 4: "C", 5: "D"}
_ZERO_BASED_TIER_RANKS = {0: "S", 1: "A", 2: "B", 3: "C", 4: "D"}

_BONUS_FIELD_ALIASES = {
    "preferred": (
        "preferred",
        "is_preferred",
        "isPreferred",
        "preferred_slot",
        "preferredSlot",
        "preferred_venue",
        "preferredVenue",
    ),
    "rest": ("rest", "rest_satisfied", "restSatisfied", "respects_rest", "respectsRest"),
}

_EXPLICIT_BONUS_FIELDS = (
    "soft_bonuses",
    "softBonuses",
    "objective_bonuses",
    "objectiveBonuses",
    "bonus_weights",
    "bonusWeights",
    "bonuses",
)

_MISSING = MISSING


@dataclass(frozen=True)
class Level2ObjectiveStats:
    """Summary of the T24 objective installed on the CP-SAT model."""

    score_formula_version: str
    placement_terms: int
    soft_bonus_terms: int
    total_terms: int
    chaining_bonus: int
    coefficient_by_assignment: Mapping[Any, int]
    # Two-phase support: the placement-only objective expression and the built
    # chaining terms (var, weight). When add_level_2_objective is called with
    # apply_chaining=False, chaining is NOT in the objective yet — the caller can
    # lock placement (placement_expression >= optimum) then optimise chaining in
    # a bounded second pass. Proving optimality of the tiny chaining bonuses in a
    # single objective is what blows up solve time on real datasets.
    placement_expression: Any = None
    chaining_terms: tuple[tuple[Any, int], ...] = ()


def add_level_2_objective(
    model: Any,
    assignments: Iterable[AssignmentLike] | Mapping[Any, BoolVarLike],
    *,
    teams: Iterable[Any] = (),
    soft_terms: Iterable[Any] = (),
    hard_satisfied_team_ids: set[str] | None = None,
    score_formula_version: str = SCORE_FORMULA_VERSION,
    apply_chaining: bool = True,
) -> Level2ObjectiveStats:
    """Maximize the fixed T24 weighted score for candidate placements.

    Each selected placement receives the weight of its priority tier (S/A/B/C/D).
    If the placement also marks soft criteria as satisfied, their fixed bonus
    weights are added to the same linear term. Extra soft literals can be passed
    through soft_terms as (literal, weight_name) pairs or mapping/object
    values with var/weight_name fields.

    When *hard_satisfied_team_ids* is provided, teams whose weekly sessions are
    fully covered by HARD locks are excluded from the ``placed.Not() *
    -UNPLACED_PENALTY`` term — their solver variables are forced to 0 by the
    remaining_sessions constraint, which would otherwise trigger the penalty
    even though the team is effectively placed.
    """

    if score_formula_version != SCORE_FORMULA_VERSION:
        raise ValueError(
            f"unsupported score_formula_version {score_formula_version!r}; "
            f"expected {SCORE_FORMULA_VERSION!r}"
        )

    assignment_list = _normalise_assignments(assignments)
    teams_by_id = _teams_by_id(teams)
    variables: list[BoolVarLike] = []
    coefficients: list[int] = []
    coefficient_by_assignment: dict[Any, int] = {}
    placement_terms = 0
    soft_bonus_terms = 0

    for assignment in assignment_list:
        variable = _var(assignment)
        tier_name = _priority_tier_name(assignment, teams_by_id)
        coefficient = LEVEL_2_OBJECTIVE_WEIGHTS[tier_name]
        active_bonuses = _active_bonus_weight_names(assignment)
        for bonus_name in active_bonuses:
            coefficient += LEVEL_2_OBJECTIVE_WEIGHTS[bonus_name]

        variables.append(variable)
        coefficients.append(coefficient)
        coefficient_by_assignment[_assignment_key(assignment, variable)] = coefficient
        placement_terms += 1
        soft_bonus_terms += len(active_bonuses)

    # Soft bonus: reward every placed session to maximise total session count
    for assignment in assignment_list:
        variable = _var(assignment)
        variables.append(variable)
        coefficients.append(LEVEL_2_OBJECTIVE_WEIGHTS["session_count"])
        soft_bonus_terms += 1

    # Unplaced penalty: objective -= UNPLACED_PENALTY * (1 - placed) per team.
    assignments_by_team: dict[Any, list[BoolVarLike]] = {}
    for assignment in assignment_list:
        team_id = _team_id(assignment)
        if team_id is not None:
            assignments_by_team.setdefault(team_id, []).append(_var(assignment))

    for team_id, team_vars in assignments_by_team.items():
        if hard_satisfied_team_ids is not None and str(team_id) in hard_satisfied_team_ids:
            continue
        placed = model.NewBoolVar(f"placed_{team_id}")
        model.Add(sum(team_vars) >= 1).OnlyEnforceIf(placed)
        model.Add(sum(team_vars) == 0).OnlyEnforceIf(placed.Not())
        variables.append(placed.Not())
        coefficients.append(-UNPLACED_PENALTY)

    for soft_term in soft_terms:
        variable, weight_name = _soft_term_variable_and_weight(soft_term)
        variables.append(variable)
        coefficients.append(LEVEL_2_OBJECTIVE_WEIGHTS[weight_name])
        soft_bonus_terms += 1

    # Placement objective (tiers + session_count + unplaced penalty + soft terms).
    placement_expression = (
        sum(coefficient * variable for variable, coefficient in zip(variables, coefficients))
        if variables
        else 0
    )

    # Chaining terms are BUILT (vars + linking constraints) regardless — a
    # two-phase caller needs them present in the model. Whether they enter the
    # objective now depends on apply_chaining: single-phase (default) folds them
    # into the one Maximize; two-phase (apply_chaining=False) maximises placement
    # only, then the caller locks placement and optimises chaining separately.
    chaining_pairs = add_chaining_bonus(model, assignment_list, teams=teams)

    if apply_chaining and chaining_pairs:
        model.Maximize(placement_expression + sum(weight * variable for variable, weight in chaining_pairs))
    else:
        model.Maximize(placement_expression)

    return Level2ObjectiveStats(
        score_formula_version=SCORE_FORMULA_VERSION,
        placement_terms=placement_terms,
        soft_bonus_terms=soft_bonus_terms,
        total_terms=len(variables) + len(chaining_pairs),
        chaining_bonus=len(chaining_pairs),
        coefficient_by_assignment=coefficient_by_assignment,
        placement_expression=placement_expression,
        chaining_terms=tuple(chaining_pairs),
    )


def add_preferred_day_bonus(
    model: Any,
    x: Mapping[Any, BoolVarLike],
    time_windows: Iterable[Any],
    weights: Mapping[str, int],
) -> list[tuple[BoolVarLike, str]]:
    """Return soft objective terms for preferred-day time windows."""

    del model
    if "preferred_day" not in weights:
        raise KeyError("preferred_day")

    soft_terms: list[tuple[BoolVarLike, str]] = []
    seen_keys: set[Any] = set()

    for time_window in time_windows:
        rule_type = _get(time_window, "ruleType", "rule_type", default=None)
        if rule_type != "PREFERRED":
            continue

        family = _get(time_window, "family", default=None)
        if family != "DAY":
            # PREFERRED TIME not implemented — backlog: specs/evolution/features-futures.md
            continue

        team_id = _scalar_id(
            _get(time_window, "scope_target_id", "scopeTargetId", "team_id", "teamId", default=None)
        )
        if team_id is None:
            continue

        config = _get(time_window, "config", default={}) or {}
        preferred_days = config.get("preferredDays") or config.get("preferred_days") or ()
        preferred_day_numbers: set[int] = set()
        for preferred_day in preferred_days:
            try:
                preferred_day_numbers.add(int(_scalar_id(preferred_day)))
            except (TypeError, ValueError):
                continue

        if not preferred_day_numbers:
            continue

        for slot_key, variable in x.items():
            if slot_key in seen_keys:
                continue
            if not isinstance(slot_key, tuple) or len(slot_key) < 4:
                continue
            if _scalar_id(slot_key[0]) != team_id:
                continue

            try:
                day_of_week = int(_scalar_id(slot_key[2]))
            except (TypeError, ValueError):
                continue

            if day_of_week not in preferred_day_numbers:
                continue

            soft_terms.append((variable, "preferred_day"))
            seen_keys.add(slot_key)

    return soft_terms


def add_chaining_bonus(
    model: Any,
    assignments: Iterable[AssignmentLike] | Mapping[Any, BoolVarLike],
    *,
    teams: Iterable[Any] = (),
) -> list[tuple[BoolVarLike, int]]:
    """Build SOFT bonus terms for same-venue back-to-back coaching sessions.

    For each pair of consecutive slots (A, B) in the same venue on the same
    day where A.end == B.start, and for each coach who could be assigned to
    both slots, create a ``chained`` BoolVar that is true when the coach is
    assigned to both. The bonus weight is ``CHAINING_TIER_WEIGHTS[tier]`` where
    the tier is the highest-tier team the coach coaches across the two sessions.

    Returns a list of ``(chained_var, weight)`` terms. The caller MUST fold
    these into its single ``model.Maximize(...)`` — this function must not call
    Maximize itself, or CP-SAT's single-objective model would drop them.
    """

    assignment_list = _normalise_assignments(assignments)
    if len(assignment_list) < 2:
        return []

    teams_by_id = _teams_by_id(teams)

    slot_lookup: dict[tuple[str, str, int], list[dict[str, Any]]] = {}

    for assignment in assignment_list:
        venue_id = _get_venue_id(assignment)
        slot_id = _get_slot_id(assignment)
        if venue_id is None or slot_id is None:
            continue

        slot_id_str = str(slot_id)
        parts = slot_id_str.split(":", 1)
        if len(parts) != 2:
            continue

        day = parts[0]
        start_minutes = _parse_time_minutes(parts[1])
        if start_minutes is None:
            continue

        start_val = _get(assignment, "start", "start_minute", "starts_at", default=None)
        end_val = _get(assignment, "end", "end_minute", "ends_at", default=None)

        start_min = int(start_val) if start_val is not None else start_minutes
        end_min = int(end_val) if end_val is not None else None

        key = (str(venue_id), day, start_min)
        slot_lookup.setdefault(key, []).append({
            "assignment": assignment,
            "start": start_min,
            "end": end_min,
        })

    chaining_pairs: list[tuple[BoolVarLike, int]] = []
    seen_pairs: set[tuple[str, str]] = set()

    for key, entries in slot_lookup.items():
        venue_id, day, _start_min = key
        for entry in entries:
            end_min = entry["end"]
            if end_min is None:
                continue

            next_key = (venue_id, day, end_min)
            next_entries = slot_lookup.get(next_key)
            if next_entries is None:
                continue

            for next_entry in next_entries:
                pair_id_a = str(_assignment_key(entry["assignment"], _var(entry["assignment"])))
                pair_id_b = str(_assignment_key(next_entry["assignment"], _var(next_entry["assignment"])))
                pair_key = (pair_id_a, pair_id_b)
                if pair_key in seen_pairs:
                    continue
                seen_pairs.add(pair_key)

                coaches_a = _coach_ids_for(entry["assignment"])
                coaches_b = _coach_ids_for(next_entry["assignment"])
                common_coaches = coaches_a & coaches_b

                for coach_id in common_coaches:
                    tier_a = _priority_tier_name(entry["assignment"], teams_by_id)
                    tier_b = _priority_tier_name(next_entry["assignment"], teams_by_id)
                    highest_tier = _higher_tier(tier_a, tier_b)
                    weight = CHAINING_TIER_WEIGHTS.get(highest_tier, 0)
                    if weight == 0:
                        continue

                    var_a = _var(entry["assignment"])
                    var_b = _var(next_entry["assignment"])
                    # Cheap encoding: `chained` only ever appears in the objective
                    # with a positive weight, so two linear upper bounds suffice —
                    # the maximiser pushes it to min(var_a, var_b) = "both placed".
                    # Avoids the reified AddBoolAnd/AddBoolOr + OnlyEnforceIf, which
                    # blow up the model on real datasets (BCCL solve > 30 s).
                    chained = model.NewBoolVar(f"chained_{coach_id}_{pair_id_a}_{pair_id_b}")
                    model.Add(chained <= var_a)
                    model.Add(chained <= var_b)

                    chaining_pairs.append((chained, int(weight)))

    return chaining_pairs


def _normalise_assignments(
    assignments: Iterable[AssignmentLike] | Mapping[Any, BoolVarLike]
) -> list[AssignmentLike]:
    if isinstance(assignments, Mapping):
        return [_assignment_from_mapping_item(key, value) for key, value in assignments.items()]
    return list(assignments)


def _assignment_from_mapping_item(key: Any, variable: BoolVarLike) -> Mapping[str, Any]:
    if isinstance(key, tuple):
        values = list(key)
        return {
            "var": variable,
            "team_id": str(values[0]) if len(values) > 0 and values[0] is not None else None,
            "slot_id": str(values[1]) if len(values) > 1 and values[1] is not None else None,
            "venue_id": str(values[2]) if len(values) > 2 and values[2] is not None else None,
            "coach_id": str(values[3]) if len(values) > 3 and values[3] is not None else None,
            "session_id": str(values[4]) if len(values) > 4 and values[4] is not None else None,
            "id": ":".join(str(value) for value in values),
        }
    return {"var": variable, "id": str(key)}


def _teams_by_id(teams: Iterable[Any]) -> dict[Any, Any]:
    indexed: dict[Any, Any] = {}
    for team in teams:
        team_id = _scalar_id(_get(team, "id", "team_id", "teamId", default=None))
        if team_id is not None:
            indexed[team_id] = team
    return indexed


def _priority_tier_name(assignment: AssignmentLike, teams_by_id: Mapping[Any, Any]) -> str:
    rank_value = _get(assignment, *_PRIORITY_RANK_FIELDS, default=_MISSING)
    if rank_value is not _MISSING:
        return _normalise_priority_rank(rank_value)

    tier_value = _get(assignment, *_PRIORITY_TIER_FIELDS, default=_MISSING)
    if tier_value is not _MISSING:
        return _normalise_priority_tier(tier_value)

    team_id = _team_id(assignment)
    team = teams_by_id.get(team_id)
    if team is not None:
        rank_value = _get(team, *_PRIORITY_RANK_FIELDS, default=_MISSING)
        if rank_value is not _MISSING:
            return _normalise_priority_rank(rank_value)

        tier_value = _get(team, *_PRIORITY_TIER_FIELDS, default=_MISSING)
        if tier_value is not _MISSING:
            return _normalise_priority_tier(tier_value)

    raise ValueError("assignment is missing a priority tier (S/A/B/C/D or priority_tier_id 1..5)")


def _normalise_priority_tier(value: Any) -> str:
    scalar = _scalar_id(value)
    if isinstance(scalar, int):
        if scalar in _ONE_BASED_TIER_IDS:
            return _ONE_BASED_TIER_IDS[scalar]
        raise ValueError(f"unknown priority_tier_id {scalar!r}; expected 1..5")

    text = str(scalar).strip().upper().replace("-", "_").replace(" ", "_")
    if text.isdigit():
        return _normalise_priority_tier(int(text))
    if text in _TIER_ALIASES:
        return _TIER_ALIASES[text]
    raise ValueError(f"unknown priority tier {value!r}; expected S/A/B/C/D or 1..5")


def _normalise_priority_rank(value: Any) -> str:
    scalar = _scalar_id(value)
    if isinstance(scalar, int):
        if scalar in _ZERO_BASED_TIER_RANKS:
            return _ZERO_BASED_TIER_RANKS[scalar]
        raise ValueError(f"unknown priority rank {scalar!r}; expected 0..4")

    text = str(scalar).strip()
    if text.isdigit():
        return _normalise_priority_rank(int(text))
    return _normalise_priority_tier(text)


def _active_bonus_weight_names(assignment: AssignmentLike) -> tuple[str, ...]:
    active: set[str] = set()

    for weight_name, aliases in _BONUS_FIELD_ALIASES.items():
        if any(bool(_get(assignment, alias, default=False)) for alias in aliases):
            active.add(weight_name)

    explicit = _get(assignment, *_EXPLICIT_BONUS_FIELDS, default=())
    if isinstance(explicit, Mapping):
        for weight_name, enabled in explicit.items():
            if enabled:
                active.add(_normalise_bonus_weight_name(weight_name))
    elif explicit is not None and not isinstance(explicit, (str, bytes)):
        for weight_name in explicit:
            active.add(_normalise_bonus_weight_name(weight_name))
    elif explicit:
        active.add(_normalise_bonus_weight_name(explicit))

    return tuple(weight_name for weight_name in BONUS_WEIGHT_NAMES if weight_name in active)


def _soft_term_variable_and_weight(term: Any) -> tuple[BoolVarLike, str]:
    if isinstance(term, Sequence) and not isinstance(term, (str, bytes, Mapping)):
        if len(term) != 2:
            raise ValueError("soft objective term tuples must contain (variable, weight_name)")
        return term[0], _normalise_bonus_weight_name(term[1])

    variable = _var(term)
    weight_name = _get(
        term,
        "weight_name",
        "weightName",
        "objective_weight",
        "objectiveWeight",
        "weight",
        "type",
        "kind",
        default=_MISSING,
    )
    if weight_name is _MISSING:
        raise ValueError("soft objective term is missing a weight_name/objective_weight field")
    return variable, _normalise_bonus_weight_name(weight_name)


def _normalise_bonus_weight_name(value: Any) -> str:
    text = str(_scalar_id(value)).strip()
    normalized = text
    if normalized not in BONUS_WEIGHT_NAMES:
        raise ValueError(f"unknown level-2 bonus weight {value!r}")
    return normalized


def _get(source: Any, *names: str, default: Any = None) -> Any:
    return get_field(source, *names, default=default, skip_none=True)


def _scalar_id(value: Any) -> Any:
    return scalar_id(value)


def _var(assignment: AssignmentLike) -> BoolVarLike:
    return assignment_var(assignment, skip_none=True)


def _team_id(assignment: AssignmentLike) -> Any:
    return assignment_team_id(assignment, skip_none=True)


def _assignment_key(assignment: AssignmentLike, variable: BoolVarLike) -> Any:
    explicit = _scalar_id(_get(assignment, "id", "assignment_id", "assignmentId", "key", default=None))
    if explicit is not None:
        return explicit
    return variable.Index() if hasattr(variable, "Index") else id(variable)


def _get_venue_id(assignment: AssignmentLike) -> str | None:
    result = _scalar_id(_get(assignment, "venue_id", "room_id", "location_id", "venue", "room", "location", default=None))
    return str(result) if result is not None else None


def _get_slot_id(assignment: AssignmentLike) -> str | None:
    result = _scalar_id(_get(assignment, "slot_id", "time_slot_id", "timeslot_id", "slot", "time_slot", default=None))
    return str(result) if result is not None else None


def _parse_time_minutes(time_str: str) -> int | None:
    try:
        parts = time_str.split(":")
        if len(parts) < 2:
            return None
        return int(parts[0]) * 60 + int(parts[1])
    except (ValueError, TypeError):
        return None


def _coach_ids_for(assignment: AssignmentLike) -> set[str]:
    coach_id = _scalar_id(_get(assignment, "coach_id", "trainer_id", "coach", "trainer", default=None))
    result: set[str] = set()
    if coach_id is not None:
        result.add(str(coach_id))
    return result


def _higher_tier(tier_a: str, tier_b: str) -> str:
    tier_order = {"S": 0, "A": 1, "B": 2, "C": 3, "D": 4}
    rank_a = tier_order.get(tier_a, 99)
    rank_b = tier_order.get(tier_b, 99)
    return tier_a if rank_a <= rank_b else tier_b


__all__ = [
    "BONUS_WEIGHT_NAMES",
    "CHAINING_TIER_WEIGHTS",
    "LEVEL_2_OBJECTIVE_WEIGHTS",
    "Level2ObjectiveStats",
    "SCORE_FORMULA_VERSION",
    "TIER_WEIGHT_NAMES",
    "UNPLACED_PENALTY",
    "add_chaining_bonus",
    "add_level_2_objective",
    "add_preferred_day_bonus",
    "is_team_satisfied_by_hard_locks",
]
