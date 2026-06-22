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
from datetime import datetime, timezone
from typing import Any, Iterable, Mapping, Sequence, cast

from .model import _time_to_minutes

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
    age_ascending: int = 0
    coach_rest_day: int = 0
    salarie_distribution: int = 0
    max_consecutive_sessions: int = 0

    @property
    def total_constraints_added(self) -> int:
        """Return the number of concrete CP-SAT constraints added to the model."""

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
            + self.age_ascending
            + self.coach_rest_day
            + self.salarie_distribution
            + self.max_consecutive_sessions
        )


def add_level_1_hard_constraints(
    model: Any,
    assignments: Iterable[AssignmentLike] | Mapping[Any, BoolVarLike] | None = None,
    *,
    teams: Iterable[Any] = (),
    coaches: Iterable[Any] = (),
    min_sessions_by_team: Mapping[Any, int] | None = None,  # unused — kept for API compatibility
    fixed_assignments: Iterable[Any] = (),
    forbidden_assignments: Iterable[Any] = (),
    coach_unavailability: RuleCollection = (),
    venue_closures: RuleCollection = (),
    forced_venues: Mapping[Any, Any] | None = None,
    priority_tiers: Mapping[int, int] | None = None,
    skip_rest_day_and_distribution: bool = False,
    team_coach_map: dict[str, list[str]] | None = None,
    team_player_map: dict[str, list[str]] | None = None,
) -> HardConstraintStats:
    """Add the 5 implicit + 5 derived + 1 new level-1 hard constraints to a CP-SAT model.

    Implicit (always applied):
      1. VENUE_AT_MOST_ONE  — one venue hosts at most capacity teams per time slot
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

    New implicit rule:
     11. one_session_per_day  — at most one session per day per team
     12. age_ascending        — younger teams train earlier than older teams (same venue+day)

    When ``skip_rest_day_and_distribution`` is True, constraints 3b (coach_rest_day)
    and 3c (salarie_distribution) are skipped. This is used in the two-pass fallback:
    Pass 1 runs all constraints; if INFEASIBLE, Pass 2 drops these two to find a
    feasible solution, and a WARNING diagnostic is emitted instead.
    """

    if assignments is None:
        assignments = getattr(model, "x", ())

    assignment_list = _normalise_assignments(assignments)
    stats = HardConstraintStats()

    # 1. One venue hosts at most one team at a time.
    stats.room_at_most_one = add_room_at_most_one(model, assignment_list)

    # 2. One coach works with at most one team at a time.
    stats.coach_at_most_one = add_coach_at_most_one(
        model, assignment_list, team_coach_map=team_coach_map
    )

    # 3. A person cannot coach and play at the same time.
    stats.coach_player_non_overlap = add_coach_player_non_overlap(
        model, assignment_list, team_coach_map=team_coach_map, team_player_map=team_player_map
    )

    # 3b. Every coach must have at least one rest day from Monday to Friday.
    if not skip_rest_day_and_distribution:
        stats.coach_rest_day = add_coach_rest_day_constraints(
            model, assignment_list, coaches=coaches,
            team_coach_map=team_coach_map, team_player_map=team_player_map,
        )

    # 3c. At least one salarié coach must be present each Mon-Fri day.
    if not skip_rest_day_and_distribution:
        stats.salarie_distribution = add_salarie_distribution_constraints(
            model, assignment_list, coaches=coaches,
            team_coach_map=team_coach_map, team_player_map=team_player_map,
        )

    # 3d. A coach may not be in all 3 slots of a consecutive triple.
    stats.max_consecutive_sessions = add_max_consecutive_sessions_constraints(
        model, assignment_list, coaches=coaches,
        team_coach_map=team_coach_map, team_player_map=team_player_map,
    )

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
        model,
        assignment_list,
        teams=teams,
        min_sessions_by_team=min_sessions_by_team,
        priority_tiers=priority_tiers,
    )

    # 10. If a venue is forced, every other venue option is forced to 0.
    stats.forced_venues = add_forced_venue_constraints(
        model, assignment_list, forced_venues=forced_venues
    )

    # 11. At most one session per day per team (unless explicitly allowed).
    stats.one_session_per_day = add_one_session_per_day_constraints(
        model, assignment_list, teams=teams
    )

    # 12. Younger teams train earlier than older teams in the same venue+day.
    stats.age_ascending = add_age_ascending_constraints(
        model, assignment_list, teams=teams
    )

    return stats


def add_room_at_most_one(model: Any, assignments: Iterable[AssignmentLike]) -> int:
    """Constraint 1: one room/venue can host at most capacity teams per time slot."""

    slot_capacities: dict[Any, int] = getattr(model, "slot_capacities", {})
    groups: dict[tuple[Any, Any], list[BoolVarLike]] = defaultdict(list)
    for assignment in assignments:
        venue_id = _venue_id(assignment)
        time_key = _time_key(assignment)
        if venue_id is None or time_key is None:
            continue
        groups[(venue_id, time_key)].append(_var(assignment))

    added = 0
    for (venue_id, time_key), variables in groups.items():
        deduped = _dedupe_variables(variables)
        if len(deduped) < 2:
            continue
        parts = str(time_key).split(":", 1)
        if len(parts) == 2 and parts[0].isdigit():
            cap = slot_capacities.get((venue_id, int(parts[0]), parts[1]), 1)
        else:
            cap = 1
        model.Add(sum(deduped) <= cap)
        added += 1
    return added


def add_coach_at_most_one(model: Any, assignments: Iterable[AssignmentLike], *, team_coach_map: dict[str, list[str]] | None = None) -> int:
    """Constraint 2: one coach can coach at most one team per time slot.

    When ``team_coach_map`` is provided and the assignment's team is in the map,
    all coaches for that team are looked up from the map. Otherwise, falls back
    to the assignment's ``coach_id`` attribute for backward compatibility.
    """

    groups: dict[tuple[Any, Any], list[BoolVarLike]] = defaultdict(list)
    for assignment in assignments:
        time_key = _time_key(assignment)
        if time_key is None:
            continue

        team_id = _team_id(assignment)
        team_id_str = str(team_id) if team_id is not None else None

        # Look up coaches from team_coach_map
        if team_coach_map is not None and team_id_str is not None and team_id_str in team_coach_map:
            for coach_id in team_coach_map[team_id_str]:
                groups[(coach_id, time_key)].append(_var(assignment))
        else:
            # Fall back to assignment's coach_id attribute
            coach_id = _coach_id(assignment)
            if coach_id is not None:
                groups[(coach_id, time_key)].append(_var(assignment))

    return _add_at_most_one_groups(model, groups.values())


def add_coach_player_non_overlap(model: Any, assignments: Iterable[AssignmentLike], *, team_coach_map: dict[str, list[str]] | None = None, team_player_map: dict[str, list[str]] | None = None) -> int:
    """Constraint 3: a coach-player cannot be in two roles at the same time.

    When ``team_coach_map`` / ``team_player_map`` are provided and the
    assignment's team is found, coaches and players are looked up from the
    maps. Otherwise, falls back to the assignment's own attributes.
    """

    coach_groups: dict[tuple[Any, Any], list[BoolVarLike]] = defaultdict(list)
    player_groups: dict[tuple[Any, Any], list[BoolVarLike]] = defaultdict(list)

    for assignment in assignments:
        time_key = _time_key(assignment)
        if time_key is None:
            continue

        team_id = _team_id(assignment)
        team_id_str = str(team_id) if team_id is not None else None

        if team_coach_map is not None and team_id_str is not None and team_id_str in team_coach_map:
            for coach_id in team_coach_map[team_id_str]:
                coach_groups[(coach_id, time_key)].append(_var(assignment))
        else:
            coach_id = _coach_id(assignment)
            if coach_id is not None:
                coach_groups[(coach_id, time_key)].append(_var(assignment))

        if team_player_map is not None and team_id_str is not None and team_id_str in team_player_map:
            for player_id in team_player_map[team_id_str]:
                player_groups[(player_id, time_key)].append(_var(assignment))
        else:
            for player_id in _player_ids(assignment):
                player_groups[(player_id, time_key)].append(_var(assignment))

    overlap_groups = (
        coach_groups[key] + player_groups[key]
        for key in coach_groups.keys() & player_groups.keys()
    )
    return _add_at_most_one_groups(model, overlap_groups)


def add_coach_rest_day_constraints(
    model: Any,
    assignments: Iterable[AssignmentLike],
    *,
    coaches: Iterable[Any] = (),
    team_coach_map: dict[str, list[str]] | None = None,
    team_player_map: dict[str, list[str]] | None = None,
) -> int:
    """Constraint 3b: every coach must have at least one rest day from Monday to Friday.

    For each coach, creates ``is_working[coach, day]`` BoolVars for days 1-5
    using reification, then enforces ``sum(is_working) <= 4`` (at most 4 working
    days among Mon-Fri, guaranteeing at least 1 rest day).

    Both coaching assignments (via ``team_coach_map``) and coach-player playing
    assignments (via ``team_player_map``) count as working days. Falls back to
    assignment attributes when maps are not provided or team is not found.
    Coaches whose ``max_days_override`` is 4 or less are skipped because their
    rest day is already guaranteed by that cap.
    """

    # Build coach_id -> max_days_override map
    coach_max_days: dict[str, int | None] = {}
    for coach in coaches:
        coach_id = _scalar_id(_get(coach, "id", "coach_id", default=None))
        if coach_id is None:
            continue
        coach_id_str = str(coach_id)
        max_days = _get(coach, "max_days_override", "maxDaysOverride", default=None)
        coach_max_days[coach_id_str] = int(max_days) if max_days is not None else None

    if not coach_max_days:
        return 0

    # Group assignment variables by (person_id, day) for days 1-5.
    # A person is "working" on a day if they coach or play on that day.
    person_day_vars: dict[tuple[str, int], list[BoolVarLike]] = defaultdict(list)

    for assignment in assignments:
        slot_id = _slot_id(assignment)
        if slot_id is None:
            continue
        day_str = str(slot_id).split(":")[0]
        try:
            day = int(day_str)
        except (TypeError, ValueError):
            continue
        if day < 1 or day > 5:
            continue

        team_id = _team_id(assignment)
        team_id_str = str(team_id) if team_id is not None else None

        # Coaching assignments — look up from team_coach_map
        if team_coach_map is not None and team_id_str is not None and team_id_str in team_coach_map:
            for coach_id in team_coach_map[team_id_str]:
                if coach_id in coach_max_days:
                    person_day_vars[(coach_id, day)].append(_var(assignment))
        else:
            coach_id = _coach_id(assignment)
            if coach_id is not None:
                coach_id_str = str(coach_id)
                if coach_id_str in coach_max_days:
                    person_day_vars[(coach_id_str, day)].append(_var(assignment))

        # Playing assignments (coach as player) — look up from team_player_map
        if team_player_map is not None and team_id_str is not None and team_id_str in team_player_map:
            for player_id in team_player_map[team_id_str]:
                if player_id in coach_max_days:
                    person_day_vars[(player_id, day)].append(_var(assignment))
        else:
            for player_id in _player_ids(assignment):
                player_id_str = str(player_id)
                if player_id_str in coach_max_days:
                    person_day_vars[(player_id_str, day)].append(_var(assignment))

    added = 0
    for coach_id_str, max_days in coach_max_days.items():
        # Skip coaches whose max_days_override <= 4 (rest day already guaranteed)
        if max_days is not None and max_days <= 4:
            continue

        # Create is_working BoolVars for each day 1-5 using reification
        is_working_vars: list[BoolVarLike] = []
        for day in range(1, 6):
            day_vars = _dedupe_variables(person_day_vars.get((coach_id_str, day), []))
            is_working = cast(Any, model).NewBoolVar(f"coach_rest_day_is_working_{coach_id_str}_day{day}")
            is_working_vars.append(is_working)

            if not day_vars:
                # No assignments on this day => coach is definitely not working
                cast(Any, model).Add(is_working == 0)
            else:
                day_sum = sum(cast(Any, v) for v in day_vars)
                cast(Any, model).Add(day_sum >= 1).OnlyEnforceIf(is_working)
                cast(Any, model).Add(day_sum == 0).OnlyEnforceIf(is_working.Not())

        # Enforce: at most 4 working days among Mon-Fri (at least 1 rest day)
        cast(Any, model).Add(sum(is_working_vars) <= 4)
        added += 1

    return added


def add_salarie_distribution_constraints(
    model: Any,
    assignments: Iterable[AssignmentLike],
    *,
    coaches: Iterable[Any] = (),
    team_coach_map: dict[str, list[str]] | None = None,
    team_player_map: dict[str, list[str]] | None = None,
) -> int:
    """Constraint 3c: at least one salarié coach must be present each Mon-Fri day.

    A salarié is a coach with ``isEmployee=True``. For each day 1-5 (Mon-Fri),
    creates a ``day_has_salarie[d]`` BoolVar with reification and enforces
    ``day_has_salarie[d] == 1``. Both coaching assignments (via ``team_coach_map``)
    and coach-player playing assignments (via ``team_player_map``) count as being
    present. Falls back to assignment attributes when maps are not provided.

    Skipped if there are fewer than 2 salarié coaches.
    """

    salarie_ids: set[str] = set()
    for coach in coaches:
        coach_id = _scalar_id(_get(coach, "id", "coach_id", default=None))
        if coach_id is None:
            continue
        is_employee = _get(coach, "isEmployee", "is_employee", default=False)
        if is_employee:
            salarie_ids.add(str(coach_id))

    if len(salarie_ids) < 2:
        return 0

    day_vars: dict[int, list[BoolVarLike]] = defaultdict(list)

    for assignment in assignments:
        slot_id = _slot_id(assignment)
        if slot_id is None:
            continue
        day_str = str(slot_id).split(":")[0]
        try:
            day = int(day_str)
        except (TypeError, ValueError):
            continue
        if day < 1 or day > 5:
            continue

        team_id = _team_id(assignment)
        team_id_str = str(team_id) if team_id is not None else None

        if team_coach_map is not None and team_id_str is not None and team_id_str in team_coach_map:
            for coach_id in team_coach_map[team_id_str]:
                if coach_id in salarie_ids:
                    day_vars[day].append(_var(assignment))
        else:
            coach_id = _coach_id(assignment)
            if coach_id is not None and str(coach_id) in salarie_ids:
                day_vars[day].append(_var(assignment))

        if team_player_map is not None and team_id_str is not None and team_id_str in team_player_map:
            for player_id in team_player_map[team_id_str]:
                if player_id in salarie_ids:
                    day_vars[day].append(_var(assignment))
        else:
            for player_id in _player_ids(assignment):
                if str(player_id) in salarie_ids:
                    day_vars[day].append(_var(assignment))

    added = 0
    for day in range(1, 6):
        day_assignments = _dedupe_variables(day_vars.get(day, []))
        day_has_salarie = cast(Any, model).NewBoolVar(f"day_has_salarie_day{day}")

        if not day_assignments:
            cast(Any, model).Add(day_has_salarie == 0)
        else:
            day_sum = sum(cast(Any, v) for v in day_assignments)
            cast(Any, model).Add(day_sum >= 1).OnlyEnforceIf(day_has_salarie)
            cast(Any, model).Add(day_sum == 0).OnlyEnforceIf(day_has_salarie.Not())

        cast(Any, model).Add(day_has_salarie == 1)
        added += 1

    return added


def add_max_consecutive_sessions_constraints(
    model: Any,
    assignments: Iterable[AssignmentLike],
    *,
    coaches: Iterable[Any] = (),
    team_coach_map: dict[str, list[str]] | None = None,
    team_player_map: dict[str, list[str]] | None = None,
) -> int:
    """Constraint 3d: a coach may not be in all 3 slots of a consecutive triple.

    For each venue, identifies consecutive slot triples (A, B, C) where
    A.end == B.start and B.end == C.start. For each coach, the sum of
    their assignments (coaching + playing) across the triple must be <= 2.

    Coaches and players are looked up from ``team_coach_map`` and
    ``team_player_map`` when available, falling back to assignment attributes.
    """

    coach_ids: set[str] = set()
    for coach in coaches:
        coach_id = _scalar_id(_get(coach, "id", "coach_id", default=None))
        if coach_id is not None:
            coach_ids.add(str(coach_id))

    if not coach_ids:
        return 0

    venue_day_slots: dict[tuple[str, str], list[tuple[int, int, AssignmentLike]]] = defaultdict(list)

    for assignment in assignments:
        venue_id = _venue_id(assignment)
        slot_id = _slot_id(assignment)
        if venue_id is None or slot_id is None:
            continue

        slot_id_str = str(slot_id)
        parts = slot_id_str.split(":", 1)
        if len(parts) < 2:
            continue
        day = parts[0]

        start = _get(assignment, "start", "start_minute", "start_time", "starts_at", default=None)
        end = _get(assignment, "end", "end_minute", "end_time", "ends_at", default=None)
        if start is None or end is None:
            continue

        start_minutes = int(start) if not isinstance(start, int) else start
        end_minutes = int(end) if not isinstance(end, int) else end

        venue_day_slots[(str(venue_id), day)].append((start_minutes, end_minutes, assignment))

    added = 0
    for (venue_key, day_key), slot_entries in venue_day_slots.items():
        slot_entries.sort(key=lambda x: x[0])

        for i in range(len(slot_entries) - 2):
            start_a, end_a, _ = slot_entries[i]
            start_b, end_b, _ = slot_entries[i + 1]
            start_c, end_c, _ = slot_entries[i + 2]

            if end_a != start_b or end_b != start_c:
                continue

            coach_vars: dict[str, list[BoolVarLike]] = defaultdict(list)

            for _, _, assignment in slot_entries[i : i + 3]:
                team_id = _team_id(assignment)
                team_id_str = str(team_id) if team_id is not None else None

                if team_coach_map is not None and team_id_str is not None and team_id_str in team_coach_map:
                    for cid in team_coach_map[team_id_str]:
                        if cid in coach_ids:
                            coach_vars[cid].append(_var(assignment))
                else:
                    cid = _coach_id(assignment)
                    if cid is not None and str(cid) in coach_ids:
                        coach_vars[str(cid)].append(_var(assignment))

                if team_player_map is not None and team_id_str is not None and team_id_str in team_player_map:
                    for pid in team_player_map[team_id_str]:
                        if pid in coach_ids:
                            coach_vars[pid].append(_var(assignment))
                else:
                    for pid in _player_ids(assignment):
                        if str(pid) in coach_ids:
                            coach_vars[str(pid)].append(_var(assignment))

            for cid, vars_list in coach_vars.items():
                deduped = _dedupe_variables(vars_list)
                if len(deduped) >= 3:
                    cast(Any, model).Add(sum(deduped) <= 2)
                    added += 1

    return added


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


def add_time_window_constraints(
    model: Any,
    x: Mapping[Any, BoolVarLike],
    time_windows: Iterable[dict[str, Any]] = (),
) -> tuple[int, list[dict[str, Any]]]:
    added = 0
    conflicts: list[dict[str, Any]] = []

    day_rules_by_team: dict[str, dict[str, set[int]]] = defaultdict(lambda: {"forced": set(), "forbidden": set()})

    for constraint in time_windows or ():
        if not constraint.get("isActive", True):
            continue

        rule_type = constraint.get("ruleType") or constraint.get("rule_type")
        family = constraint.get("family")
        if rule_type == "PREFERRED" and family == "TIME":
            # TODO: PREFERRED TIME not implemented
            continue
        if rule_type != "HARD" or family not in ("TIME", "DAY"):
            continue

        team_id = constraint.get("scope_target_id") or constraint.get("scopeTargetId")
        if team_id is None:
            continue
        team_id_text = str(team_id)
        config = constraint.get("config") or {}

        if family == "DAY":
            forced_days = config.get("forcedDays") or []
            forbidden_days = config.get("forbiddenDays") or []
            day_rules_by_team[team_id_text]["forced"].update(int(day) for day in forced_days if day is not None)
            day_rules_by_team[team_id_text]["forbidden"].update(int(day) for day in forbidden_days if day is not None)
            continue

        min_start_time = config.get("minStartTime")
        max_start_time = config.get("maxStartTime")
        min_start_minutes = _time_to_minutes(min_start_time) if min_start_time is not None else None
        max_start_minutes = _time_to_minutes(max_start_time) if max_start_time is not None else None

        for slot_key, var in x.items():
            if not isinstance(slot_key, tuple) or len(slot_key) < 4:
                continue

            slot_team_id = slot_key[0]
            if str(slot_team_id) != team_id_text:
                continue

            slot_start = slot_key[3]
            slot_start_minutes = _time_to_minutes(slot_start)
            if min_start_minutes is not None and slot_start_minutes < min_start_minutes:
                model.Add(var == 0)
                added += 1
                continue
            if max_start_minutes is not None and slot_start_minutes > max_start_minutes:
                model.Add(var == 0)
                added += 1

    team_day_vars: dict[str, dict[int, list[BoolVarLike]]] = defaultdict(lambda: defaultdict(list))
    team_all_vars: dict[str, list[BoolVarLike]] = defaultdict(list)

    for slot_key, var in x.items():
        if not isinstance(slot_key, tuple) or len(slot_key) < 4:
            continue

        slot_team_id = slot_key[0]
        team_id_text = str(slot_team_id)
        team_all_vars[team_id_text].append(var)

        day = slot_key[2]
        try:
            day_value = int(day)
        except (TypeError, ValueError):
            continue
        team_day_vars[team_id_text][day_value].append(var)

    for team_id_text, day_rules in day_rules_by_team.items():
        forced_day_set = day_rules["forced"]
        forbidden_day_set = day_rules["forbidden"]
        if forced_day_set & forbidden_day_set:
            conflicts.append({
                "id": f"day_constraint_conflict-{team_id_text}",
                "type": "day_constraint_conflict",
                "severity": "ERROR",
                "teamId": team_id_text,
                "message": (
                    f"Team {team_id_text} has contradictory forcedDays and forbiddenDays; "
                    "the team is forced to 0 slots."
                ),
                "suggestions": [
                    "Remove the overlapping days from forcedDays or forbiddenDays.",
                ],
                "createdAt": datetime.now(timezone.utc).isoformat(),
            })
            for var in team_all_vars.get(team_id_text, []):
                model.Add(var == 0)
                added += 1
            continue

        for day_value in forbidden_day_set:
            for var in team_day_vars.get(team_id_text, {}).get(day_value, []):
                model.Add(var == 0)
                added += 1

        if forced_day_set:
            forced_day_vars: list[BoolVarLike] = []
            for day_value in forced_day_set:
                forced_day_vars.extend(team_day_vars.get(team_id_text, {}).get(day_value, []))

            model.Add(sum(forced_day_vars) >= 1)
            added += 1

    return added, conflicts


def add_min_sessions_constraints(
    model: Any,
    assignments: Iterable[AssignmentLike],
    *,
    teams: Iterable[Any] = (),
    min_sessions_by_team: Mapping[Any, int] | None = None,
    priority_tiers: Mapping[int, int] | None = None,
) -> int:
    """Constraint 10: every team receives at least its effective minimum sessions."""

    if priority_tiers:
        minimums = _compute_effective_min_sessions(teams, priority_tiers)
        if min_sessions_by_team:
            for tid, minimum in min_sessions_by_team.items():
                minimums[_scalar_id(tid)] = int(minimum)
    else:
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
    assignments: Iterable[AssignmentLike] | Mapping[Any, BoolVarLike] | None
) -> list[AssignmentLike]:
    if assignments is None:
        return []
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


def _compute_effective_min_sessions(
    teams: Iterable[Any], priority_tiers: Mapping[int, int]
) -> dict[Any, int]:
    """Compute effective minimum sessions per team via tier defaultMinSessions.

    effective_min = min(sessionsPerWeek, tier.defaultMinSessions)

    If the team has no priorityTierId or the tier is not in priority_tiers,
    falls back to sessionsPerWeek as the effective minimum.
    """
    minimums: dict[Any, int] = {}
    for team in teams:
        team_id = _scalar_id(_get(team, "id", "team_id", default=None))
        if team_id is None:
            continue
        sessions_per_week_raw = _get(team, "sessions_per_week", "sessionsPerWeek", default=None)
        if sessions_per_week_raw is None:
            continue
        sessions_per_week = int(sessions_per_week_raw)
        tier_id_raw = _get(team, "priority_tier_id", "priorityTierId", default=None)
        if tier_id_raw is not None:
            try:
                tier_key = int(tier_id_raw)
            except (TypeError, ValueError):
                tier_key = None
            if tier_key is not None and tier_key in priority_tiers:
                minimums[team_id] = min(sessions_per_week, priority_tiers[tier_key])
                continue
        minimums[team_id] = sessions_per_week
    return minimums


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

    sessions_per_week: dict[str, int] = {}
    for team in teams:
        tid = _scalar_id(_get(team, "id", "team_id", "teamId", default=None))
        if tid is None:
            continue
        spw = _get(team, "sessionsPerWeek", "sessions_per_week", default=1)
        sessions_per_week[str(tid)] = max(1, int(spw))

    days_by_team: dict[str, list[tuple[str, list[BoolVarLike]]]] = defaultdict(list)
    for (team_id, day), vars_list in groups.items():
        days_by_team[team_id].append((day, vars_list))

    added = 0
    for team_id, day_entries in days_by_team.items():
        if len(day_entries) <= 1:
            continue
        spw = sessions_per_week.get(team_id, 1)
        day_active_vars: list[BoolVarLike] = []
        for _day, vars_list in day_entries:
            day_active = cast(Any, model).NewBoolVar(f"day_active_{team_id}_{_day}")
            day_active_vars.append(day_active)
            slot_sum = sum(cast(Any, v) for v in vars_list)
            cast(Any, model).Add(slot_sum >= 1).OnlyEnforceIf(day_active)
            cast(Any, model).Add(slot_sum == 0).OnlyEnforceIf(day_active.Not())
        cast(Any, model).Add(sum(day_active_vars) <= spw)
        added += 1

    for (team_id, _day), vars_list in groups.items():
        if team_id in multi_allowed:
            continue
        deduped = _dedupe_variables(vars_list)
        if len(deduped) > 1:
            cast(Any, model).Add(sum(deduped) <= 1)
            added += 1

    return added


def add_age_ascending_constraints(
    model: Any,
    assignments: Iterable[AssignmentLike],
    *,
    teams: Iterable[Any] = (),
) -> int:
    """Implicit rule 12: younger teams train earlier than older teams
    in the same venue on the same day.

    For each pair (A, B) where A.ageMin < B.ageMin (both not None, neither
    HARD-locked), and for each venue+day, if slot_A starts later than slot_B,
    prevent both from being selected simultaneously:
    ``x[A, venue, day, slot_A] + x[B, venue, day, slot_B] <= 1``.

    Teams with ``ageMin=None`` (Loisir, Baby) and HARD-locked teams are exempt.
    No constraint is added between teams sharing the same ``ageMin``.
    """

    team_age_min: dict[str, int] = {}
    for team in teams:
        tid = _scalar_id(_get(team, "id", "team_id", "teamId", default=None))
        if tid is None:
            continue
        age_min = _get(team, "ageMin", "age_min", default=None)
        if age_min is None:
            continue
        team_age_min[str(tid)] = int(age_min)

    if len(team_age_min) < 2:
        return 0

    hard_locked_teams: set[str] = set()
    hard_slot_keys: frozenset[tuple[str, str, int, str]] = getattr(model, "hard_slot_keys", frozenset())
    for slot_key in hard_slot_keys:
        hard_locked_teams.add(str(slot_key[0]))

    locked_slots = getattr(model, "locked_slots", ())
    for locked in locked_slots:
        tid = _scalar_id(_get(locked, "team_id", "teamId", default=None))
        if tid is not None:
            hard_locked_teams.add(str(tid))

    groups: dict[tuple[str, str], list[tuple[str, int, BoolVarLike]]] = defaultdict(list)
    for assignment in assignments:
        team_id = _team_id(assignment)
        venue_id = _venue_id(assignment)
        slot_id = _slot_id(assignment)
        if team_id is None or venue_id is None or slot_id is None:
            continue
        team_id_str = str(team_id)
        if team_id_str not in team_age_min or team_id_str in hard_locked_teams:
            continue
        slot_id_str = str(slot_id)
        parts = slot_id_str.split(":", 1)
        if len(parts) != 2:
            continue
        day = parts[0]
        start_minutes = _time_to_minutes(parts[1])
        groups[(str(venue_id), day)].append((team_id_str, start_minutes, _var(assignment)))

    added = 0
    for _entries in groups.values():
        by_team: dict[str, list[tuple[int, BoolVarLike]]] = defaultdict(list)
        for team_id_str, start_minutes, var in _entries:
            by_team[team_id_str].append((start_minutes, var))

        team_ids_here = [t for t in by_team if t in team_age_min]
        team_ids_here.sort(key=lambda t: team_age_min[t])

        for i in range(len(team_ids_here)):
            for j in range(i + 1, len(team_ids_here)):
                team_a = team_ids_here[i]
                team_b = team_ids_here[j]
                if team_age_min[team_a] == team_age_min[team_b]:
                    continue
                for start_a, var_a in by_team[team_a]:
                    for start_b, var_b in by_team[team_b]:
                        if start_a > start_b:
                            model.Add(var_a + var_b <= 1)
                            added += 1

    return added


def parse_v2_constraints(constraints: list[dict[str, Any]]) -> dict[str, Any]:
    """Parse v2 constraints[] array into solver-ready rule collections.

    Returns dict with keys: fixed_slots, forbidden_assignments,
    coach_unavailability, venue_closures, forced_venues, preferred_venues,
    time_windows, priority_tiers, team_coach_map, team_player_map
    """

    result: dict[str, Any] = {
        "fixed_slots": [],
        "forbidden_assignments": [],
        "coach_unavailability": {},
        "venue_closures": {},
        "forced_venues": {},
        "preferred_venues": {},
        "time_windows": [],
        "priority_tiers": {},
        "team_coach_map": {},
        "team_player_map": {},
    }

    for c in constraints:
        if not c.get("isActive", True):
            continue
        rule_type = c.get("ruleType") or c.get("rule_type")
        c_type = c.get("type")
        family = c.get("family")
        scope = c.get("scope")
        scope_target_id = c.get("scopeTargetId") or c.get("scope_target_id")
        config = c.get("config") or {}
        metadata = c.get("metadata") or {}

        if rule_type == "LOCK":
            result["fixed_slots"].append(c.get("id"))

        elif c_type == "TEAM_COACH":
            team_id = c.get("teamId") or c.get("team_id") or scope_target_id
            coach_id = (
                metadata.get("coachId")
                or metadata.get("coach_id")
                or c.get("value")
                or config.get("coachId")
                or config.get("coach_id")
            )
            if team_id and coach_id:
                team_id_str = str(team_id)
                coach_id_str = str(coach_id)
                result["team_coach_map"].setdefault(team_id_str, []).append(coach_id_str)

        elif c_type == "COACH_PLAYER_UNAVAILABILITY":
            team_id = (
                metadata.get("teamId")
                or metadata.get("team_id")
                or c.get("teamId")
                or c.get("team_id")
                or scope_target_id
            )
            coach_id = (
                metadata.get("coachId")
                or metadata.get("coach_id")
                or c.get("value")
            )
            if team_id and coach_id:
                team_id_str = str(team_id)
                coach_id_str = str(coach_id)
                result["team_player_map"].setdefault(team_id_str, []).append(coach_id_str)

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

        elif c.get("type") == "PRIORITY_TIER":
            metadata = c.get("metadata") or {}
            tier_id = metadata.get("id")
            default_min = metadata.get("defaultMinSessions")
            if tier_id is not None and default_min is not None:
                result["priority_tiers"][int(tier_id)] = int(default_min)

        elif family in ("TIME", "DAY"):
            result["time_windows"].append(c)

    return result


__all__ = [
    "AssignmentVariable",
    "HardConstraintStats",
    "add_age_ascending_constraints",
    "add_coach_at_most_one",
    "add_coach_player_non_overlap",
    "add_coach_rest_day_constraints",
    "add_coach_unavailability_constraints",
    "add_fixed_slots",
    "add_forbidden_assignments",
    "add_forced_venue_constraints",
    "add_level_1_hard_constraints",
    "add_max_consecutive_sessions_constraints",
    "add_min_sessions_constraints",
    "add_one_session_per_day_constraints",
    "add_room_at_most_one",
    "add_salarie_distribution_constraints",
    "add_time_window_constraints",
    "add_team_no_overlap",
    "add_venue_closure_constraints",
    "parse_v2_constraints",
]
