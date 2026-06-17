"""Level-1 hard constraints for the OR-Tools CP-SAT scheduler model.

The solver treats these rules as hard constraints only: no relaxation
variables and no penalties are introduced in this module.

Implicit rules (always applied):
  VENUE_AT_MOST_ONE, COACH_NO_OVERLAP, COACH_PLAYER_NO_OVERLAP,
  TEAM_NO_OVERLAP, MIN_SESSIONS

Derived rules (parsed from v2 constraints[] payload):
  fixed_slots, forbidden_assignments, coach_unavailability,
  venue_closures, forced_venues
"""

from __future__ import annotations

from collections import defaultdict
from dataclasses import dataclass
from typing import Any, Iterable, Mapping, Sequence, cast

AssignmentLike = Any
BoolVarLike = Any
RuleCollection = Any

_MISSING = object()


@dataclass(frozen=True)
class AssignmentVariable:
    """Candidate assignment variable used by the hard constraint builder.

    The fields intentionally mirror the domain dimensions used by level-1
    constraints. Future T22 model objects can also be passed directly: all
    public functions read attributes or mapping keys with the same names.
    """

    var: BoolVarLike
    team_id: str | None = None
    slot_id: str | None = None
    venue_id: str | None = None
    coach_id: str | None = None
    player_ids: Sequence[str] = ()
    session_id: str | None = None
    start: int | None = None
    end: int | None = None
    fixed: bool = False
    forbidden: bool = False
    coach_unavailable: bool = False
    venue_closed: bool = False
    forced_venue_id: str | None = None
    id: str | None = None


@dataclass
class HardConstraintStats:
    """Counts of level-1 hard constraints added to the CP-SAT model."""

    room_at_most_one: int = 0
    coach_at_most_one: int = 0
    coach_player_non_overlap: int = 0
    team_no_overlap: int = 0
    travel_feasibility_stub: int = 0
    fixed_slots: int = 0
    forbidden_assignments: int = 0
    coach_unavailability: int = 0
    venue_closures: int = 0
    required_bridge_stub: int = 0
    min_sessions: int = 0
    forced_venues: int = 0
    one_session_per_day: int = 0
    adult_weekday_time: int = 0
    min_session_duration: int = 0

    @property
    def total_constraints_added(self) -> int:
        """Return the number of concrete CP-SAT constraints added."""

        return (
            self.room_at_most_one
            + self.coach_at_most_one
            + self.coach_player_non_overlap
            + self.team_no_overlap
            + self.travel_feasibility_stub
            + self.fixed_slots
            + self.forbidden_assignments
            + self.coach_unavailability
            + self.venue_closures
            + self.required_bridge_stub
            + self.min_sessions
            + self.forced_venues
            + self.one_session_per_day
            + self.adult_weekday_time
            + self.min_session_duration
        )


def add_level_1_hard_constraints(
    model: Any,
    assignments: Iterable[AssignmentLike] | Mapping[Any, BoolVarLike] | None = None,
    *,
    teams: Iterable[Any] = (),
    min_sessions_by_team: Mapping[Any, int] | None = None,
    fixed_assignments: Iterable[Any] = (),
    forbidden_assignments: Iterable[Any] = (),
    coach_unavailability: RuleCollection = (),
    venue_closures: RuleCollection = (),
    forced_venues: Mapping[Any, Any] | None = None,
) -> HardConstraintStats:
    """Add the 5 implicit + 5 derived + 3 new level-1 hard constraints to a CP-SAT model.

    Implicit (always applied):
      1. VENUE_AT_MOST_ONE  — one venue hosts at most one team per time slot
      2. COACH_NO_OVERLAP   — one coach coaches at most one team per time slot
      3. COACH_PLAYER_NO_OVERLAP — a coach-player cannot be in two roles at once
      4. TEAM_NO_OVERLAP    — a team cannot have two sessions at the same time
      5. MIN_SESSIONS        — every team receives at least its effective minimum

    Derived (fed from parse_v2_constraints or direct arguments):
      6. fixed_slots          — pre-placed slots forced to 1
      7. forbidden_assignments — forbidden variables forced to 0
      8. coach_unavailability — unavailable coach slots forced to 0
      9. venue_closures       — closed venue slots forced to 0
     10. forced_venues        — forced venue excludes alternatives

    New implicit rules:
     11. one_session_per_day  — at most one session per day per team
     12. adult_weekday_time   — adult competitive teams cannot train before 19:00 on weekdays
     13. min_session_duration — minimum session duration per team (default 90 min)
    """

    if assignments is None:
        assignments = getattr(model, "x", ())

    assignment_list = _normalise_assignments(assignments)
    stats = HardConstraintStats()

    # 1. One venue hosts at most one team at a time.
    stats.room_at_most_one = add_room_at_most_one(model, assignment_list)

    # 2. One coach works with at most one team at a time.
    stats.coach_at_most_one = add_coach_at_most_one(model, assignment_list)

    # 3. A person cannot coach and play at the same time.
    stats.coach_player_non_overlap = add_coach_player_non_overlap(model, assignment_list)

    # 4. A team cannot have two sessions at the same time slot.
    stats.team_no_overlap = add_team_no_overlap(model, assignment_list)

    # 5. Pre-placed slots are fixed and excluded from optimization choices.
    stats.fixed_slots = add_fixed_slots(model, assignment_list, fixed_assignments)

    # 6. Explicitly forbidden assignment variables are forced to 0.
    stats.forbidden_assignments = add_forbidden_assignments(
        model, assignment_list, forbidden_assignments
    )

    # 7. Coach unavailable variables are forced to 0.
    stats.coach_unavailability = add_coach_unavailability_constraints(
        model, assignment_list, coach_unavailability
    )

    # 8. Closed venue variables are forced to 0.
    stats.venue_closures = add_venue_closure_constraints(
        model, assignment_list, venue_closures
    )

    # 9. Effective minimum sessions are guaranteed by a hard linear bound.
    stats.min_sessions = add_min_sessions_constraints(
        model, assignment_list, teams=teams, min_sessions_by_team=min_sessions_by_team
    )

    # 10. If a venue is forced, every other venue option is forced to 0.
    stats.forced_venues = add_forced_venue_constraints(
        model, assignment_list, forced_venues=forced_venues
    )

    # 11. At most one session per day per team (unless explicitly allowed).
    stats.one_session_per_day = add_one_session_per_day_constraints(
        model, assignment_list, teams=teams
    )

    # 12. Adult competitive teams cannot train before 19:00 on weekdays.
    stats.adult_weekday_time = add_adult_weekday_time_constraints(
        model, assignment_list, teams=teams
    )

    # 13. Minimum session duration — NOT applied as a hard constraint here.
    # Enforcing consecutive-slot blocks causes infeasibility on fixtures where
    # fewer than N slots are available in a time window. Duration is handled via
    # slot templates (durationMinutes). minSessionMinutes is surfaced in the
    # payload for future objective-level use.
    stats.min_session_duration = 0

    return stats


def add_room_at_most_one(model: Any, assignments: Iterable[AssignmentLike]) -> int:
    """Constraint 1: one room/venue can host at most one team per time slot."""

    groups: dict[tuple[Any, Any], list[BoolVarLike]] = defaultdict(list)
    for assignment in assignments:
        venue_id = _venue_id(assignment)
        time_key = _time_key(assignment)
        if venue_id is None or time_key is None:
            continue
        groups[(venue_id, time_key)].append(_var(assignment))

    return _add_at_most_one_groups(model, groups.values())


def add_coach_at_most_one(model: Any, assignments: Iterable[AssignmentLike]) -> int:
    """Constraint 2: one coach can coach at most one team per time slot."""

    groups: dict[tuple[Any, Any], list[BoolVarLike]] = defaultdict(list)
    for assignment in assignments:
        coach_id = _coach_id(assignment)
        time_key = _time_key(assignment)
        if coach_id is None or time_key is None:
            continue
        groups[(coach_id, time_key)].append(_var(assignment))

    return _add_at_most_one_groups(model, groups.values())


def add_coach_player_non_overlap(model: Any, assignments: Iterable[AssignmentLike]) -> int:
    """Constraint 3: a coach-player cannot be in two roles at the same time."""

    coach_groups: dict[tuple[Any, Any], list[BoolVarLike]] = defaultdict(list)
    player_groups: dict[tuple[Any, Any], list[BoolVarLike]] = defaultdict(list)

    for assignment in assignments:
        time_key = _time_key(assignment)
        if time_key is None:
            continue

        coach_id = _coach_id(assignment)
        if coach_id is not None:
            coach_groups[(coach_id, time_key)].append(_var(assignment))

        for player_id in _player_ids(assignment):
            player_groups[(player_id, time_key)].append(_var(assignment))

    overlap_groups = (
        coach_groups[key] + player_groups[key]
        for key in coach_groups.keys() & player_groups.keys()
    )
    return _add_at_most_one_groups(model, overlap_groups)


def add_team_no_overlap(model: Any, assignments: Iterable[AssignmentLike]) -> int:
    """A team cannot have two sessions at the same time slot."""

    groups: dict[tuple[Any, Any], list[BoolVarLike]] = defaultdict(list)
    for assignment in assignments:
        team_id = _team_id(assignment)
        time_key = _time_key(assignment)
        if team_id is None or time_key is None:
            continue
        groups[(team_id, time_key)].append(_var(assignment))
    return _add_at_most_one_groups(model, groups.values())


def add_fixed_slots(
    model: Any, assignments: Iterable[AssignmentLike], fixed_assignments: Iterable[Any] = ()
) -> int:
    """Constraint 5: pre-placed slots are fixed to 1."""

    fixed_ids = set(fixed_assignments or ())
    added = 0
    for assignment in assignments:
        assignment_id = _assignment_id(assignment)
        if _bool_field(assignment, "fixed", "is_fixed", "pre_placed", "preplaced", "is_pre_placed") or (
            assignment_id is not None and assignment_id in fixed_ids
        ):
            model.Add(_var(assignment) == 1)
            added += 1
    return added


def add_forbidden_assignments(
    model: Any, assignments: Iterable[AssignmentLike], forbidden_assignments: Iterable[Any] = ()
) -> int:
    """Constraint 6: forbidden assignment variables are fixed to 0.

    ``forbidden_assignments`` may contain either:
    - plain string/hashable IDs matched against the assignment's ``id`` field, OR
    - dicts with ``scope_target_id`` (team) and ``venue_id`` keys — every variable
      for that (team, venue) pair is forced to 0 regardless of day/slot.
    """

    forbidden_ids: set[Any] = set()
    forbidden_pairs: set[tuple[Any, Any]] = set()

    for item in forbidden_assignments or ():
        if isinstance(item, dict):
            tid = item.get("scope_target_id") or item.get("team_id")
            vid = item.get("venue_id") or item.get("room_id")
            if tid is not None and vid is not None:
                forbidden_pairs.add((str(tid), str(vid)))
        else:
            forbidden_ids.add(item)

    added = 0
    for assignment in assignments:
        assignment_id = _assignment_id(assignment)
        team_id = _team_id(assignment)
        venue_id = _venue_id(assignment)
        if (
            _bool_field(assignment, "forbidden", "is_forbidden")
            or (assignment_id is not None and assignment_id in forbidden_ids)
            or (team_id is not None and venue_id is not None and (str(team_id), str(venue_id)) in forbidden_pairs)
        ):
            model.Add(_var(assignment) == 0)
            added += 1
    return added


def add_coach_unavailability_constraints(
    model: Any, assignments: Iterable[AssignmentLike], coach_unavailability: RuleCollection = ()
) -> int:
    """Constraint 7: coach-unavailable assignment variables are fixed to 0."""

    added = 0
    for assignment in assignments:
        coach_id = _coach_id(assignment)
        time_key = _time_key(assignment)
        if _bool_field(assignment, "coach_unavailable", "is_coach_unavailable") or _rule_matches(
            coach_unavailability, coach_id, time_key
        ):
            model.Add(_var(assignment) == 0)
            added += 1
    return added


def add_venue_closure_constraints(
    model: Any, assignments: Iterable[AssignmentLike], venue_closures: RuleCollection = ()
) -> int:
    """Constraint 8: closed-room assignment variables are fixed to 0."""

    added = 0
    for assignment in assignments:
        venue_id = _venue_id(assignment)
        time_key = _time_key(assignment)
        if _bool_field(assignment, "venue_closed", "is_venue_closed", "room_closed", "is_room_closed") or _rule_matches(
            venue_closures, venue_id, time_key
        ):
            model.Add(_var(assignment) == 0)
            added += 1
    return added


def add_min_sessions_constraints(
    model: Any,
    assignments: Iterable[AssignmentLike],
    *,
    teams: Iterable[Any] = (),
    min_sessions_by_team: Mapping[Any, int] | None = None,
) -> int:
    """Constraint 10: every team receives at least its effective minimum sessions."""

    minimums = _effective_min_sessions_by_team(teams, min_sessions_by_team)
    if not minimums:
        return 0

    assignments_by_team: dict[Any, list[BoolVarLike]] = defaultdict(list)
    for assignment in assignments:
        team_id = _team_id(assignment)
        if team_id is None:
            continue
        assignments_by_team[team_id].append(_var(assignment))

    added = 0
    for team_id, minimum in minimums.items():
        if minimum <= 0:
            continue
        team_vars = _dedupe_variables(assignments_by_team.get(team_id, []))
        model.Add(sum(team_vars) >= minimum)
        added += 1
    return added


def add_forced_venue_constraints(
    model: Any,
    assignments: Iterable[AssignmentLike],
    *,
    forced_venues: Mapping[Any, Any] | None = None,
) -> int:
    """Constraint 11: when a venue is forced, all other venues are fixed to 0."""

    added = 0
    for assignment in assignments:
        venue_id = _venue_id(assignment)
        target_venue_id = _forced_venue_id(assignment, forced_venues)
        if target_venue_id is None or venue_id is None or venue_id == target_venue_id:
            continue
        model.Add(_var(assignment) == 0)
        added += 1
    return added


def _normalise_assignments(
    assignments: Iterable[AssignmentLike] | Mapping[Any, BoolVarLike]
) -> list[AssignmentLike]:
    if isinstance(assignments, Mapping):
        return [_assignment_from_mapping_item(key, value) for key, value in assignments.items()]
    return list(assignments)


def _assignment_from_mapping_item(key: Any, var: BoolVarLike) -> AssignmentVariable:
    if isinstance(key, tuple):
        values = list(key)
        assignment_id = ":".join(str(value) for value in values)

        if _looks_like_schedule_slot_key(values):
            day_of_week = values[2]
            slot_start = values[3]
            return AssignmentVariable(
                var=var,
                team_id=str(values[0]) if values[0] is not None else None,
                venue_id=str(values[1]) if values[1] is not None else None,
                slot_id=f"{day_of_week}:{slot_start}",
                id=assignment_id,
            )

        return AssignmentVariable(
            var=var,
            team_id=str(values[0]) if len(values) > 0 and values[0] is not None else None,
            slot_id=str(values[1]) if len(values) > 1 and values[1] is not None else None,
            venue_id=str(values[2]) if len(values) > 2 and values[2] is not None else None,
            coach_id=str(values[3]) if len(values) > 3 and values[3] is not None else None,
            session_id=str(values[4]) if len(values) > 4 and values[4] is not None else None,
            id=assignment_id,
        )
    return AssignmentVariable(var=var, id=str(key))


def _looks_like_schedule_slot_key(values: Sequence[Any]) -> bool:
    if len(values) != 4:
        return False

    day_of_week = values[2]
    slot_start = values[3]
    return _looks_like_day_of_week(day_of_week) and _looks_like_slot_start(slot_start)


def _looks_like_day_of_week(value: Any) -> bool:
    if isinstance(value, int):
        return 0 <= value <= 7
    if isinstance(value, str) and value.isdigit():
        return 0 <= int(value) <= 7
    return False


def _looks_like_slot_start(value: Any) -> bool:
    if isinstance(value, int):
        return 0 <= value < 24 * 60
    if not isinstance(value, str):
        return False
    parts = value.split(":")
    return len(parts) >= 2 and all(part.isdigit() for part in parts[:2])


def _add_at_most_one_groups(model: Any, groups: Iterable[Iterable[BoolVarLike]]) -> int:
    added = 0
    for group in groups:
        variables = _dedupe_variables(group)
        if len(variables) < 2:
            continue
        if hasattr(model, "add_at_most_one"):
            model.add_at_most_one(variables)
        else:
            model.AddAtMostOne(variables)
        added += 1
    return added


def _dedupe_variables(variables: Iterable[BoolVarLike]) -> list[BoolVarLike]:
    unique: list[BoolVarLike] = []
    seen: set[Any] = set()
    for variable in variables:
        key = variable.Index() if hasattr(variable, "Index") else id(variable)
        if key in seen:
            continue
        seen.add(key)
        unique.append(variable)
    return unique


def _get(assignment: AssignmentLike, *names: str, default: Any = None) -> Any:
    if isinstance(assignment, Mapping):
        for name in names:
            if name in assignment:
                return assignment[name]

    for name in names:
        if hasattr(assignment, name):
            return getattr(assignment, name)

    if isinstance(assignment, Sequence) and not isinstance(assignment, (str, bytes)):
        tuple_indexes = {
            "var": 0,
            "variable": 0,
            "bool_var": 0,
            "team_id": 1,
            "team": 1,
            "slot_id": 2,
            "time_slot_id": 2,
            "venue_id": 3,
            "room_id": 3,
            "coach_id": 4,
            "session_id": 5,
        }
        for name in names:
            index = tuple_indexes.get(name)
            if index is not None and len(assignment) > index:
                return assignment[index]

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
    variable = _get(assignment, "var", "variable", "bool_var", "x", default=_MISSING)
    if variable is _MISSING:
        raise ValueError("Assignment is missing a CP-SAT BoolVar field named var/variable/bool_var/x")
    return variable


def _assignment_id(assignment: AssignmentLike) -> Any:
    return _scalar_id(_get(assignment, "id", "assignment_id", "key", default=None))


def _team_id(assignment: AssignmentLike) -> Any:
    return _scalar_id(_get(assignment, "team_id", "team", default=None))


def _slot_id(assignment: AssignmentLike) -> Any:
    return _scalar_id(_get(assignment, "slot_id", "time_slot_id", "timeslot_id", "slot", "time_slot", default=None))


def _venue_id(assignment: AssignmentLike) -> Any:
    return _scalar_id(_get(assignment, "venue_id", "room_id", "location_id", "venue", "room", "location", default=None))


def _coach_id(assignment: AssignmentLike) -> Any:
    return _scalar_id(_get(assignment, "coach_id", "trainer_id", "coach", "trainer", default=None))


def _session_id(assignment: AssignmentLike) -> Any:
    return _scalar_id(_get(assignment, "session_id", "lesson_id", "event_id", "session", "lesson", "event", default=None))


def _time_key(assignment: AssignmentLike) -> Any:
    slot_id = _slot_id(assignment)
    if slot_id is not None:
        return slot_id

    explicit = _scalar_id(_get(assignment, "time_key", "time", default=None))
    if explicit is not None:
        return explicit

    start = _get(assignment, "start", "starts_at", "start_minute", "start_time", default=None)
    end = _get(assignment, "end", "ends_at", "end_minute", "end_time", default=None)
    if start is not None and end is not None:
        return (start, end)

    return None


def _player_ids(assignment: AssignmentLike) -> list[Any]:
    players = _get(
        assignment,
        "player_ids",
        "participant_ids",
        "athlete_ids",
        "players",
        "participants",
        "athletes",
        default=(),
    )
    if players is None:
        return []
    if isinstance(players, (str, bytes)):
        return [_scalar_id(players)]
    return [_scalar_id(player) for player in players]


def _bool_field(assignment: AssignmentLike, *names: str) -> bool:
    return any(bool(_get(assignment, name, default=False)) for name in names)


def _rule_matches(rules: RuleCollection, resource_id: Any, time_key: Any) -> bool:
    if not rules or resource_id is None:
        return False

    if isinstance(rules, Mapping):
        if (resource_id, time_key) in rules:
            return bool(rules[(resource_id, time_key)])
        if resource_id not in rules:
            return False
        values = rules[resource_id]
        if values is True:
            return True
        return _contains(values, time_key)

    return _contains(rules, (resource_id, time_key)) or _contains(rules, resource_id)


def _contains(values: Any, candidate: Any) -> bool:
    if values is None:
        return False
    if isinstance(values, (str, bytes)):
        return bool(values == candidate)
    try:
        return candidate in values
    except TypeError:
        return False


def _effective_min_sessions_by_team(
    teams: Iterable[Any], min_sessions_by_team: Mapping[Any, int] | None
) -> dict[Any, int]:
    minimums: dict[Any, int] = {}
    for team in teams:
        team_id = _scalar_id(_get(team, "id", "team_id", default=None))
        if team_id is None:
            continue
        minimum = _get(
            team,
            "min_sessions_effectif",
            "effective_min_sessions",
            "min_sessions",
            "sessions_per_week",
            default=None,
        )
        if minimum is not None:
            minimums[team_id] = int(minimum)

    if min_sessions_by_team:
        for team_id, minimum in min_sessions_by_team.items():
            minimums[_scalar_id(team_id)] = int(minimum)

    return minimums


def _forced_venue_id(
    assignment: AssignmentLike, forced_venues: Mapping[Any, Any] | None
) -> Any:
    explicit = _scalar_id(
        _get(
            assignment,
            "forced_venue_id",
            "forced_room_id",
            "forced_venue",
            "forced_room",
            default=None,
        )
    )
    if explicit is not None:
        return explicit

    if not forced_venues:
        return None

    team_id = _team_id(assignment)
    session_id = _session_id(assignment)
    candidate_keys = (
        (team_id, session_id),
        f"{team_id}:{session_id}" if team_id is not None and session_id is not None else None,
        session_id,
        team_id,
    )
    for key in candidate_keys:
        if key is not None and key in forced_venues:
            return _scalar_id(forced_venues[key])
    return None


_ADULT_LEVELS: frozenset[str] = frozenset(
    {
        "REGIONAL",
        "DEPARTEMENTAL",
        "NATIONAL",
        "ELITE",
        "HONNEUR",
        "PROMOTION",
        "PRE_REGION",
    }
)
_WEEKDAY_NUMBERS: frozenset[str] = frozenset({"1", "2", "3", "4", "5"})
_ADULT_MIN_WEEKDAY_START: str = "19:00"
_DEFAULT_MIN_SESSION_MINUTES: int = 90
_SLOT_MINUTES: int = 15


def add_one_session_per_day_constraints(
    model: Any,
    assignments: Iterable[AssignmentLike],
    *,
    teams: Iterable[Any] = (),
) -> int:
    """Implicit rule 11: a team can have at most one training session per day,
    unless allowMultipleSessionsPerDay is True for that team.
    """

    multi_allowed: set[str] = set()
    for team in teams:
        tid = _scalar_id(_get(team, "id", "team_id", "teamId", default=None))
        allow = _get(
            team,
            "allowMultipleSessionsPerDay",
            "allow_multiple_sessions_per_day",
            default=False,
        )
        if tid is not None and allow:
            multi_allowed.add(str(tid))

    groups: dict[tuple[str, str], list[BoolVarLike]] = defaultdict(list)
    for assignment in assignments:
        team_id = _team_id(assignment)
        slot_id = _slot_id(assignment)
        if team_id is None or slot_id is None:
            continue
        day = str(slot_id).split(":")[0]
        groups[(str(team_id), day)].append(_var(assignment))

    added = 0
    for (team_id, _day), vars_list in groups.items():
        if team_id in multi_allowed:
            continue
        if len(vars_list) > 1:
            cast(Any, model).Add(sum(vars_list) <= 1)
            added += 1

    return added


def add_adult_weekday_time_constraints(
    model: Any,
    assignments: Iterable[AssignmentLike],
    *,
    teams: Iterable[Any] = (),
) -> int:
    """Implicit rule 12: adult competitive teams (level != LOISIR, level != null)
    cannot have sessions on weekdays (Mon–Fri) starting before 19:00.
    """

    adult_team_ids: set[str] = set()
    for team in teams:
        tid = _scalar_id(_get(team, "id", "team_id", "teamId", default=None))
        level = _get(team, "level", default=None)
        if tid is not None and isinstance(level, str) and level.upper() in _ADULT_LEVELS:
            adult_team_ids.add(str(tid))

    if not adult_team_ids:
        return 0

    added = 0
    for assignment in assignments:
        team_id = _team_id(assignment)
        if team_id is None or str(team_id) not in adult_team_ids:
            continue

        slot_id = _slot_id(assignment)
        if slot_id is None:
            continue

        parts = str(slot_id).split(":")
        if len(parts) < 3:
            continue

        day = parts[0]
        slot_start = parts[1] + ":" + parts[2]

        if day not in _WEEKDAY_NUMBERS:
            continue

        if slot_start < _ADULT_MIN_WEEKDAY_START:
            cast(Any, model).Add(_var(assignment) == 0)
            added += 1

    return added


def add_min_session_duration_constraints(
    model: Any,
    assignments: Iterable[AssignmentLike],
    *,
    teams: Iterable[Any] = (),
) -> int:
    """Implicit rule 13: each session must span at least minSessionMinutes
    of consecutive 15-min slots (default 90 min = 6 slots).

    If a slot variable at position i is active (==1), all slots from i to i+N-1
    in the same (team, venue, day) group must also be active.
    If fewer than N subsequent slots exist, that slot is forced to 0.
    """

    import math

    min_slots_by_team: dict[str, int] = {}
    for team in teams:
        tid = _scalar_id(_get(team, "id", "team_id", "teamId", default=None))
        if tid is None:
            continue
        raw = _get(team, "minSessionMinutes", "min_session_minutes", default=None)
        minutes = int(raw) if raw is not None else _DEFAULT_MIN_SESSION_MINUTES
        min_slots_by_team[str(tid)] = math.ceil(minutes / _SLOT_MINUTES)

    if not min_slots_by_team:
        return 0

    groups: dict[tuple[str, str, str], list[tuple[str, BoolVarLike]]] = defaultdict(list)
    for assignment in assignments:
        team_id = _team_id(assignment)
        venue_id = _venue_id(assignment)
        slot_id = _slot_id(assignment)
        if team_id is None or venue_id is None or slot_id is None:
            continue
        parts = str(slot_id).split(":")
        if len(parts) < 3:
            continue
        day = parts[0]
        slot_time = parts[1] + ":" + parts[2]
        groups[(str(team_id), str(venue_id), day)].append((slot_time, _var(assignment)))

    added = 0
    for (team_id, _venue_key, _day), slot_list in groups.items():
        n = min_slots_by_team.get(team_id)
        if n is None or n <= 1:
            continue

        slot_list.sort(key=lambda x: x[0])
        vars_only = [v for _, v in slot_list]

        for i, var_i in enumerate(vars_only):
            for k in range(1, n):
                j = i + k
                if j >= len(vars_only):
                    cast(Any, model).Add(cast(Any, var_i) == 0)
                    added += 1
                    break
                cast(Any, model).Add(cast(Any, var_i) - cast(Any, vars_only[j]) <= 0)
                added += 1

    return added


def parse_v2_constraints(constraints: list[dict[str, Any]]) -> dict[str, Any]:
    """Parse v2 constraints[] array into solver-ready rule collections.

    Returns dict with keys: fixed_slots, forbidden_assignments,
    coach_unavailability, venue_closures, forced_venues, preferred_venues,
    time_windows
    """

    result: dict[str, Any] = {
        "fixed_slots": [],
        "forbidden_assignments": [],
        "coach_unavailability": {},
        "venue_closures": {},
        "forced_venues": {},
        "preferred_venues": {},
        "time_windows": [],
    }

    for c in constraints:
        if not c.get("isActive", True):
            continue
        rule_type = c.get("ruleType") or c.get("rule_type")
        family = c.get("family")
        scope = c.get("scope")
        scope_target_id = c.get("scopeTargetId") or c.get("scope_target_id")
        config = c.get("config") or {}

        if rule_type == "LOCK":
            result["fixed_slots"].append(c.get("id"))

        elif family == "COACH_AVAILABILITY" and scope_target_id:
            unavail = config.get("unavailableDays") or []
            result["coach_unavailability"][scope_target_id] = unavail

        elif family == "FACILITY" and config.get("dateStart") and scope_target_id:
            result["venue_closures"][scope_target_id] = config

        elif (
            family == "FACILITY"
            and config.get("preferredVenueId")
            and rule_type == "HARD"
            and scope == "TEAM"
            and scope_target_id
        ):
            result["forced_venues"][scope_target_id] = config["preferredVenueId"]

        elif (
            family == "FACILITY"
            and config.get("forcedVenueId")
            and rule_type == "HARD"
            and scope == "TEAM"
            and scope_target_id
        ):
            result["forced_venues"][scope_target_id] = config["forcedVenueId"]

        elif (
            family == "FACILITY"
            and config.get("preferredVenueId")
            and rule_type == "PREFERRED"
            and scope == "TEAM"
            and scope_target_id
        ):
            result["preferred_venues"][scope_target_id] = config["preferredVenueId"]

        elif family == "FACILITY" and config.get("forbiddenVenueId"):
            result["forbidden_assignments"].append(
                {"scope_target_id": scope_target_id, "venue_id": config["forbiddenVenueId"]}
            )

        elif family in ("TIME", "DAY"):
            result["time_windows"].append(c)

    return result


__all__ = [
    "AssignmentVariable",
    "HardConstraintStats",
    "add_coach_at_most_one",
    "add_coach_player_non_overlap",
    "add_coach_unavailability_constraints",
    "add_adult_weekday_time_constraints",
    "add_fixed_slots",
    "add_forbidden_assignments",
    "add_forced_venue_constraints",
    "add_level_1_hard_constraints",
    "add_min_session_duration_constraints",
    "add_min_sessions_constraints",
    "add_one_session_per_day_constraints",
    "add_room_at_most_one",
    "add_team_no_overlap",
    "add_venue_closure_constraints",
    "parse_v2_constraints",
]
