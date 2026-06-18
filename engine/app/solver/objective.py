"""Level-2 linear objective for the OR-Tools CP-SAT scheduler model.

The T24 score formula is intentionally fixed. Any change to one of these
weights must be accompanied by a new SCORE_FORMULA_VERSION.
"""

from __future__ import annotations

from collections.abc import Iterable, Mapping, Sequence
from dataclasses import dataclass
from types import MappingProxyType
from typing import Any

AssignmentLike = Any
BoolVarLike = Any

SCORE_FORMULA_VERSION = "T24_LEVEL_2_FIXED_WEIGHTS_V2"

LEVEL_2_OBJECTIVE_WEIGHTS = MappingProxyType(
    {
        "S": 10000,
        "A": 1000,
        "SOFT": 800,
        "B": 100,
        "session_count": 20,
        "pref_link": 80,
        "preferred": 60,
        "grouping": 50,
        "C": 10,
        "max_days": 8,
        "opt_link": 5,
        "D": 1,
        "rest": 3,
    }
)

TIER_WEIGHT_NAMES = ("S", "A", "B", "C", "D")
BONUS_WEIGHT_NAMES = (
    "SOFT",
    "pref_link",
    "preferred",
    "grouping",
    "max_days",
    "opt_link",
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
    "SOFT": (
        "soft",
        "soft_satisfied",
        "softSatisfied",
        "soft_constraints_satisfied",
        "softConstraintsSatisfied",
        "respects_soft_constraints",
        "respectsSoftConstraints",
    ),
    "pref_link": (
        "pref_link",
        "prefLink",
        "preferred_link",
        "preferredLink",
        "preferred_link_satisfied",
        "preferredLinkSatisfied",
    ),
    "preferred": (
        "preferred",
        "is_preferred",
        "isPreferred",
        "preferred_slot",
        "preferredSlot",
        "preferred_venue",
        "preferredVenue",
    ),
    "grouping": ("grouping", "grouped", "grouping_satisfied", "groupingSatisfied"),
    "max_days": (
        "max_days",
        "maxDays",
        "max_days_satisfied",
        "maxDaysSatisfied",
        "within_max_days",
        "withinMaxDays",
    ),
    "opt_link": (
        "opt_link",
        "optLink",
        "optional_link",
        "optionalLink",
        "optional_link_satisfied",
        "optionalLinkSatisfied",
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

_MISSING = object()


@dataclass(frozen=True)
class Level2ObjectiveStats:
    """Summary of the T24 objective installed on the CP-SAT model."""

    score_formula_version: str
    placement_terms: int
    soft_bonus_terms: int
    total_terms: int
    coefficient_by_assignment: Mapping[Any, int]


def add_level_2_objective(
    model: Any,
    assignments: Iterable[AssignmentLike] | Mapping[Any, BoolVarLike],
    *,
    teams: Iterable[Any] = (),
    soft_terms: Iterable[Any] = (),
    score_formula_version: str = SCORE_FORMULA_VERSION,
) -> Level2ObjectiveStats:
    """Maximize the fixed T24 weighted score for candidate placements.

    Each selected placement receives the weight of its priority tier (S/A/B/C/D).
    If the placement also marks soft criteria as satisfied, their fixed bonus
    weights are added to the same linear term. Extra soft literals can be passed
    through soft_terms as (literal, weight_name) pairs or mapping/object
    values with var/weight_name fields.
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

    for soft_term in soft_terms:
        variable, weight_name = _soft_term_variable_and_weight(soft_term)
        variables.append(variable)
        coefficients.append(LEVEL_2_OBJECTIVE_WEIGHTS[weight_name])
        soft_bonus_terms += 1

    if variables:
        model.Maximize(sum(coefficient * variable for variable, coefficient in zip(variables, coefficients)))
    else:
        model.Maximize(0)

    return Level2ObjectiveStats(
        score_formula_version=SCORE_FORMULA_VERSION,
        placement_terms=placement_terms,
        soft_bonus_terms=soft_bonus_terms,
        total_terms=len(variables),
        coefficient_by_assignment=coefficient_by_assignment,
    )


def apply_level_2_objective(*args: Any, **kwargs: Any) -> Level2ObjectiveStats:
    """Compatibility alias for callers that use an apply_* naming style."""

    return add_level_2_objective(*args, **kwargs)


def add_objective(*args: Any, **kwargs: Any) -> Level2ObjectiveStats:
    """Compatibility alias for the solver entry point."""

    return add_level_2_objective(*args, **kwargs)


def set_level_2_objective(*args: Any, **kwargs: Any) -> Level2ObjectiveStats:
    """Compatibility alias for T24 naming."""

    return add_level_2_objective(*args, **kwargs)


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
    aliases = {
        "soft": "SOFT",
        "SOFT": "SOFT",
        "preferred_link": "pref_link",
        "preferredLink": "pref_link",
        "optional_link": "opt_link",
        "optionalLink": "opt_link",
        "maxDays": "max_days",
    }
    normalized = aliases.get(text, text)
    if normalized not in BONUS_WEIGHT_NAMES:
        raise ValueError(f"unknown level-2 bonus weight {value!r}")
    return normalized


def _get(source: Any, *names: str, default: Any = None) -> Any:
    if isinstance(source, Mapping):
        for name in names:
            if name in source and source[name] is not None:
                return source[name]

    for name in names:
        if hasattr(source, name):
            value = getattr(source, name)
            if value is not None:
                return value

    if isinstance(source, Sequence) and not isinstance(source, (str, bytes)):
        tuple_indexes = {
            "var": 0,
            "variable": 0,
            "bool_var": 0,
            "x": 0,
            "team_id": 1,
            "team": 1,
            "slot_id": 2,
            "time_slot_id": 2,
            "venue_id": 3,
            "room_id": 3,
            "coach_id": 4,
            "session_id": 5,
            "priority_tier": 6,
            "priority_tier_id": 6,
            "tier": 6,
        }
        for name in names:
            index = tuple_indexes.get(name)
            if index is not None and len(source) > index:
                value = source[index]
                if value is not None:
                    return value

    return default


def _scalar_id(value: Any) -> Any:
    if value is None:
        return None
    if isinstance(value, (str, int, float, bool, tuple)):
        return value
    if isinstance(value, Mapping):
        return value.get("id") or value.get("uuid") or value.get("name")
    for attr in ("id", "uuid", "name"):
        if hasattr(value, attr):
            return getattr(value, attr)
    return str(value)


def _var(assignment: AssignmentLike) -> BoolVarLike:
    variable = _get(assignment, "var", "variable", "bool_var", "literal", "x", default=_MISSING)
    if variable is _MISSING:
        raise ValueError("Assignment is missing a CP-SAT BoolVar field named var/variable/bool_var/literal/x")
    return variable


def _team_id(assignment: AssignmentLike) -> Any:
    return _scalar_id(_get(assignment, "team_id", "teamId", "team", default=None))


def _assignment_key(assignment: AssignmentLike, variable: BoolVarLike) -> Any:
    explicit = _scalar_id(_get(assignment, "id", "assignment_id", "assignmentId", "key", default=None))
    if explicit is not None:
        return explicit
    return variable.Index() if hasattr(variable, "Index") else id(variable)


__all__ = [
    "BONUS_WEIGHT_NAMES",
    "LEVEL_2_OBJECTIVE_WEIGHTS",
    "Level2ObjectiveStats",
    "SCORE_FORMULA_VERSION",
    "TIER_WEIGHT_NAMES",
    "add_level_2_objective",
    "add_objective",
    "apply_level_2_objective",
    "set_level_2_objective",
]
