"""Shared assignment-field helpers for the CP-SAT solver.

Extracted from constraints.py and objective.py to remove duplicated logic.
The only intentional behavioural difference between the two call sites is
whether an explicitly-``None`` field counts as "absent" (skip to the next
candidate name): controlled by ``skip_none``. constraints.py passes
``skip_none=False`` (a present ``None`` is returned as-is); objective.py passes
``skip_none=True`` (a ``None`` is skipped). Every field-name list here is the
union of what both modules accepted, and the tuple index map is their union too
(so behaviour is a strict superset for each caller — no existing lookup is lost).

Deliberately NOT shared (different contracts, kept per-module): the
``_normalise_assignments`` / ``_assignment_from_mapping_item`` pair —
constraints.py returns ``AssignmentVariable`` objects with schedule-slot-key
detection, objective.py returns plain dicts.
"""

from __future__ import annotations

from collections.abc import Mapping, Sequence
from typing import Any

BoolVarLike = Any
AssignmentLike = Any

# Sentinel distinct from any real value (including None) — "field truly absent".
MISSING = object()

_TUPLE_INDEXES = {
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


def get_field(source: AssignmentLike, *names: str, default: Any = None, skip_none: bool = False) -> Any:
    """Return the first present field among ``names`` from a mapping, object, or tuple.

    With ``skip_none=True`` an explicitly-``None`` value counts as absent and the
    search continues to the next name; with ``skip_none=False`` it is returned as-is.
    """
    if isinstance(source, Mapping):
        for name in names:
            if name in source:
                value = source[name]
                if not (skip_none and value is None):
                    return value

    for name in names:
        if hasattr(source, name):
            value = getattr(source, name)
            if not (skip_none and value is None):
                return value

    if isinstance(source, Sequence) and not isinstance(source, (str, bytes)):
        for name in names:
            index = _TUPLE_INDEXES.get(name)
            if index is not None and len(source) > index:
                value = source[index]
                if not (skip_none and value is None):
                    return value

    return default


def scalar_id(value: Any) -> Any:
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


def assignment_var(assignment: AssignmentLike, *, skip_none: bool = False) -> BoolVarLike:
    variable = get_field(assignment, "var", "variable", "bool_var", "literal", "x", default=MISSING, skip_none=skip_none)
    if variable is MISSING:
        raise ValueError("Assignment is missing a CP-SAT BoolVar field named var/variable/bool_var/literal/x")
    return variable


def assignment_team_id(assignment: AssignmentLike, *, skip_none: bool = False) -> Any:
    return scalar_id(get_field(assignment, "team_id", "teamId", "team", default=None, skip_none=skip_none))
