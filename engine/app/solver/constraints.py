"""Level-1 hard constraints for the OR-Tools CP-SAT scheduler model.

The solver treats these rules as hard constraints only: no relaxation
variables and no penalties are introduced in this module.

Implicit rules (always applied):
  VENUE_AT_MOST_ONE, COACH_NO_OVERLAP, COACH_PLAYER_NO_OVERLAP,
  TEAM_NO_OVERLAP
MIN_SESSIONS is CAPABLE of a hard floor (add_min_sessions_constraints) but is
currently wired SOFT-ONLY: main._solve passes a floor of 0 for every team and
relies on the objective bonus (session_count) + a WARNING/ERROR diagnostic. It
is a target, not a guarantee (ENG-18).

Derived rules (parsed from v2 constraints[] payload → ParsedConstraints):
  forbidden_assignments, coach_unavailability, forced_venues,
  preferred_venues, venue_capacity_caps, time_windows (TIME/DAY/LOCK).
"""

from __future__ import annotations

import logging
from collections import defaultdict
from collections.abc import Iterable, Mapping, Sequence
from dataclasses import dataclass
from datetime import UTC, datetime
from typing import Any, TypedDict, cast

from .helpers import MISSING, assignment_team_id, assignment_var, get_field, scalar_id
from .model import DEFAULT_SESSION_MINUTES, _time_to_minutes

logger = logging.getLogger("engine.constraints")


class ParsedConstraints(TypedDict):
    """Typed result of parse_v2_constraints — the backend→engine boundary where
    the ENG-01/02 format bugs lived (an untyped dict let a set-of-days be
    compared to a slot string with no type error). Now mypy checks every
    producer and consumer of these collections."""

    fixed_slots: list[str]
    forbidden_assignments: list[dict[str, str | None]]
    # coach id → set of blocked (weekday, from_minute, to_minute) intervals. A
    # whole-day block is (day, 0, 1440). Union semantics — a slot is blocked if it
    # falls in ANY interval (Lot C: coach unavailability with time windows).
    coach_unavailability: dict[str, set[tuple[int, int, int]]]
    forced_venues: dict[str, str]
    preferred_venues: dict[str, str]
    avoided_venues: list[dict[str, str]]
    venue_minimums: list[dict[str, Any]]
    venue_capacity_caps: dict[str, int]
    time_windows: list[dict[str, Any]]
    priority_tiers: dict[int, int]
    team_coach_map: dict[str, list[str]]
    team_player_map: dict[str, list[str]]
    parse_warnings: list[dict[str, Any]]

# Recognised constraint discriminators (a v2 unified `family` or a v1 `type`).
# Used to warn ONLY on genuine contract drift, not on recognised families whose
# specific config variant is intentionally a no-op.
_KNOWN_FAMILIES = frozenset({"TIME", "DAY", "FACILITY", "FACILITY_CAPACITY", "COACH_AVAILABILITY"})
_KNOWN_TYPES = frozenset({"TEAM_COACH", "COACH_PLAYER_UNAVAILABILITY", "PRIORITY_TIER"})

# Intentional aliases (ENG-05 Scope A leaves these as-is, out of scope):
#   BoolVarLike     — a CP-SAT BoolVar/literal; ortools exposes no stable public
#                     type to annotate against, so `Any` is deliberate.
#   RuleCollection  — a loosely-shaped rule container (mapping/sequence) whose
#                     concrete shape varies per caller; kept `Any` on purpose.
BoolVarLike = Any
RuleCollection = Any

_MISSING = MISSING


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
    forced_venue_id: str | None = None
    id: str | None = None


# Loose input accepted by the public constraint entry points: already-typed
# AssignmentVariable objects, or plain mappings (production sends list[dict]).
# _normalise_assignments converts every element to AssignmentVariable so the
# internal builder operates on a single, real type (ENG-05: kills the
# AssignmentLike=Any duck-typing that let a mistyped field silently return None).
AssignmentInput = AssignmentVariable | Mapping[str, Any]


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
    assignments: Iterable[AssignmentInput] | Mapping[Any, BoolVarLike] | None = None,
    *,
    teams: Iterable[Any] = (),
    coaches: Iterable[Any] = (),
    min_sessions_by_team: Mapping[Any, int] | None = None,
    fixed_assignments: Iterable[Any] = (),
    forbidden_assignments: Iterable[Any] = (),
    coach_unavailability: RuleCollection = (),
    forced_venues: Mapping[Any, Any] | None = None,
    priority_tiers: Mapping[int, int] | None = None,
    skip_rest_day_and_distribution: bool = False,
    team_coach_map: dict[str, list[str]] | None = None,
    team_player_map: dict[str, list[str]] | None = None,
) -> HardConstraintStats:
    """Add the implicit + derived + new-implicit level-1 hard constraints to a CP-SAT model.

    Implicit (always applied):
      1. VENUE_AT_MOST_ONE  — one venue hosts at most capacity teams per time slot
      2. COACH_NO_OVERLAP   — one coach coaches at most one team per time slot
      3. COACH_PLAYER_NO_OVERLAP — a coach-player cannot be in two roles at once
      4. TEAM_NO_OVERLAP    — a team cannot have two sessions at the same time
      5. MIN_SESSIONS        — soft TARGET only (ENG-18): the objective rewards reaching a
                               team's effective minimum; it is NOT a hard guarantee

    Derived (fed from parse_v2_constraints or direct arguments):
      6. fixed_slots          — pre-placed slots forced to 1
      7. forbidden_assignments — forbidden variables forced to 0
      8. coach_unavailability — unavailable coach slots forced to 0
      9. forced_venues        — forced venue excludes alternatives

    New implicit rule:
     10. one_session_per_day  — at most one session per day per team
     11. age_ascending        — younger teams train earlier than older teams (same venue+day)

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
        model, assignment_list, coach_unavailability, team_coach_map=team_coach_map
    )

    # 8. Effective minimum sessions are guaranteed by a hard linear bound.
    # (Venue closures are honored upstream: the backend expands them to FACILITY
    # forbiddenVenueId → forbidden_assignments, ENG-02. No dead engine path.)
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


def add_room_at_most_one(model: Any, assignments: Sequence[AssignmentVariable]) -> int:
    """Constraint 1: one room/venue can host at most capacity teams per time slot."""

    slot_capacities: dict[Any, int] = getattr(model, "slot_capacities", {})
    groups: dict[tuple[Any, Any], list[BoolVarLike]] = defaultdict(list)
    for assignment in assignments:
        venue_id = assignment.venue_id
        time_key = _assignment_time_key(assignment)
        if venue_id is None or time_key is None:
            continue
        groups[(venue_id, time_key)].append(assignment.var)

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


def add_coach_at_most_one(model: Any, assignments: Sequence[AssignmentVariable], *, team_coach_map: dict[str, list[str]] | None = None) -> int:
    """Constraint 2: one coach can coach at most one team per time slot.

    When ``team_coach_map`` is provided and the assignment's team is in the map,
    all coaches for that team are looked up from the map. Otherwise, falls back
    to the assignment's ``coach_id`` attribute for backward compatibility.

    Overlap detection uses both ``_assignment_time_key`` grouping (same slot start) and
    ``_intervals_overlap`` (interval intersection) so that coaching assignments
    with different start times but overlapping intervals are also prevented.
    """

    groups: dict[tuple[Any, Any], list[BoolVarLike]] = defaultdict(list)
    person_entries: dict[str, list[tuple[int, int, BoolVarLike, str]]] = defaultdict(list)

    for assignment in assignments:
        time_key = _assignment_time_key(assignment)
        if time_key is None:
            continue

        team_id = assignment.team_id
        team_id_str = str(team_id) if team_id is not None else None

        # Look up coaches from team_coach_map
        coach_ids: list[Any] = []
        if team_coach_map is not None and team_id_str is not None and team_id_str in team_coach_map:
            coach_ids = list(team_coach_map[team_id_str])
        else:
            # Fall back to assignment's coach_id attribute
            coach_id = assignment.coach_id
            if coach_id is not None:
                coach_ids = [coach_id]

        var = assignment.var
        for coach_id in coach_ids:
            groups[(coach_id, time_key)].append(var)

        start, end, day = _extract_interval(assignment)
        if start is not None and end is not None and day is not None:
            for coach_id in coach_ids:
                person_entries[str(coach_id)].append((start, end, var, day))

    time_key_added = _add_at_most_one_groups(model, groups.values())
    interval_added = _add_interval_at_most_one(model, person_entries)
    return time_key_added + interval_added


def add_coach_player_non_overlap(model: Any, assignments: Sequence[AssignmentVariable], *, team_coach_map: dict[str, list[str]] | None = None, team_player_map: dict[str, list[str]] | None = None) -> int:
    """Constraint 3: a coach-player cannot be in two roles at the same time.

    When ``team_coach_map`` / ``team_player_map`` are provided and the
    assignment's team is found, coaches and players are looked up from the
    maps. Otherwise, falls back to the assignment's own attributes.

    Overlap detection uses both ``_assignment_time_key`` grouping (same slot start) and
    ``_intervals_overlap`` (interval intersection) so that assignments with
    different start times but overlapping intervals are also prevented. The
    interval check covers ALL role combinations for the same person
    (coach-coach, coach-player, player-player).
    """

    coach_groups: dict[tuple[Any, Any], list[BoolVarLike]] = defaultdict(list)
    player_groups: dict[tuple[Any, Any], list[BoolVarLike]] = defaultdict(list)
    person_entries: dict[str, list[tuple[int, int, BoolVarLike, str]]] = defaultdict(list)

    for assignment in assignments:
        time_key = _assignment_time_key(assignment)
        if time_key is None:
            continue

        team_id = assignment.team_id
        team_id_str = str(team_id) if team_id is not None else None

        all_person_ids: set[str] = set()

        if team_coach_map is not None and team_id_str is not None and team_id_str in team_coach_map:
            for coach_id in team_coach_map[team_id_str]:
                coach_groups[(coach_id, time_key)].append(assignment.var)
                all_person_ids.add(str(coach_id))
        else:
            single_coach = assignment.coach_id
            if single_coach is not None:
                coach_groups[(single_coach, time_key)].append(assignment.var)
                all_person_ids.add(str(single_coach))

        if team_player_map is not None and team_id_str is not None and team_id_str in team_player_map:
            for player_id in team_player_map[team_id_str]:
                player_groups[(player_id, time_key)].append(assignment.var)
                all_person_ids.add(str(player_id))
        else:
            for player_id in assignment.player_ids:
                player_groups[(player_id, time_key)].append(assignment.var)
                all_person_ids.add(str(player_id))

        var = assignment.var
        start, end, day = _extract_interval(assignment)
        if start is not None and end is not None and day is not None:
            for person_id in all_person_ids:
                person_entries[person_id].append((start, end, var, day))

    overlap_groups = (
        coach_groups[key] + player_groups[key]
        for key in coach_groups.keys() & player_groups.keys()
    )
    time_key_added = _add_at_most_one_groups(model, overlap_groups)
    interval_added = _add_interval_at_most_one(model, person_entries)
    return time_key_added + interval_added


def add_coach_rest_day_constraints(
    model: Any,
    assignments: Sequence[AssignmentVariable],
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
        slot_id = assignment.slot_id
        if slot_id is None:
            continue
        day_str = str(slot_id).split(":")[0]
        try:
            day = int(day_str)
        except (TypeError, ValueError):
            continue
        if day < 1 or day > 5:
            continue

        team_id = assignment.team_id
        team_id_str = str(team_id) if team_id is not None else None

        # Coaching assignments — look up from team_coach_map
        if team_coach_map is not None and team_id_str is not None and team_id_str in team_coach_map:
            for coach_id in team_coach_map[team_id_str]:
                if coach_id in coach_max_days:
                    person_day_vars[(coach_id, day)].append(assignment.var)
        else:
            coach_id = assignment.coach_id
            if coach_id is not None:
                coach_id_str = str(coach_id)
                if coach_id_str in coach_max_days:
                    person_day_vars[(coach_id_str, day)].append(assignment.var)

        # Playing assignments (coach as player) — look up from team_player_map
        if team_player_map is not None and team_id_str is not None and team_id_str in team_player_map:
            for player_id in team_player_map[team_id_str]:
                if player_id in coach_max_days:
                    person_day_vars[(player_id, day)].append(assignment.var)
        else:
            for player_id in assignment.player_ids:
                player_id_str = str(player_id)
                if player_id_str in coach_max_days:
                    person_day_vars[(player_id_str, day)].append(assignment.var)

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
    assignments: Sequence[AssignmentVariable],
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
        slot_id = assignment.slot_id
        if slot_id is None:
            continue
        day_str = str(slot_id).split(":")[0]
        try:
            day = int(day_str)
        except (TypeError, ValueError):
            continue
        if day < 1 or day > 5:
            continue

        team_id = assignment.team_id
        team_id_str = str(team_id) if team_id is not None else None

        if team_coach_map is not None and team_id_str is not None and team_id_str in team_coach_map:
            for coach_id in team_coach_map[team_id_str]:
                if coach_id in salarie_ids:
                    day_vars[day].append(assignment.var)
        else:
            coach_id = assignment.coach_id
            if coach_id is not None and str(coach_id) in salarie_ids:
                day_vars[day].append(assignment.var)

        if team_player_map is not None and team_id_str is not None and team_id_str in team_player_map:
            for player_id in team_player_map[team_id_str]:
                if player_id in salarie_ids:
                    day_vars[day].append(assignment.var)
        else:
            for player_id in assignment.player_ids:
                if str(player_id) in salarie_ids:
                    day_vars[day].append(assignment.var)

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
    assignments: Sequence[AssignmentVariable],
    *,
    coaches: Iterable[Any] = (),
    team_coach_map: dict[str, list[str]] | None = None,
    team_player_map: dict[str, list[str]] | None = None,
) -> int:
    """Constraint 3d: a person may not be in all 3 slots of a consecutive triple.

    Uses a single **cross-venue** grouping strategy: for each
    ``(person_id, day)``, collects all assignments across all venues where
    the person appears (coach via ``team_coach_map`` or player via
    ``team_player_map``).  Detects back-to-back chains A->B->C (where
    A.end == B.start and B.end == C.start) and adds
    ``sum(varA + varB + varC) <= 2`` for each triple.

    Cross-venue grouping is sufficient on its own: a same-venue triple is
    just a cross-venue triple where all three slots happen to share a
    venue, so it is already detected by the ``(person_id, day)`` grouping.
    The previous same-venue ``(venue_id, day)`` loop was redundant and is
    removed for performance — on the BCCL payload (~2793 assignments,
    ~196 entries per venue-day) the O(n^3) triple search per venue-day
    made constraint building exceed the 30s test timeout.

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

    # Deduplicate by variable so a person who is both coach and player on the
    # same team does not get duplicate entries that could mask real triples.
    person_day_entries: dict[tuple[str, str], dict[int, tuple[int, int, AssignmentVariable]]] = defaultdict(dict)

    for assignment in assignments:
        slot_id = assignment.slot_id
        if slot_id is None:
            continue

        slot_id_str = str(slot_id)
        parts = slot_id_str.split(":", 1)
        if len(parts) < 2:
            continue
        day = parts[0]

        start = assignment.start
        end = assignment.end
        if start is None or end is None:
            continue

        start_minutes = int(start) if not isinstance(start, int) else start
        end_minutes = int(end) if not isinstance(end, int) else end

        team_id = assignment.team_id
        team_id_str = str(team_id) if team_id is not None else None

        person_ids: set[str] = set()
        if team_coach_map is not None and team_id_str is not None and team_id_str in team_coach_map:
            for cid in team_coach_map[team_id_str]:
                if cid in coach_ids:
                    person_ids.add(cid)
        else:
            single_cid = assignment.coach_id
            if single_cid is not None and str(single_cid) in coach_ids:
                person_ids.add(str(single_cid))

        if team_player_map is not None and team_id_str is not None and team_id_str in team_player_map:
            for pid in team_player_map[team_id_str]:
                if pid in coach_ids:
                    person_ids.add(pid)
        else:
            for pid in assignment.player_ids:
                if str(pid) in coach_ids:
                    person_ids.add(str(pid))

        var = assignment.var
        var_key = var.Index() if hasattr(var, "Index") else id(var)
        for person_id in person_ids:
            person_day_entries[(person_id, day)][var_key] = (start_minutes, end_minutes, assignment)

    added = 0

    # --- Cross-venue grouping by (person_id, day) — BUG-3 fix ---
    for entries_dict in person_day_entries.values():
        slot_entries = list(entries_dict.values())
        for a, b, c in _find_consecutive_triples(slot_entries):
            deduped = _dedupe_variables([a[2].var, b[2].var, c[2].var])
            if len(deduped) >= 3:
                cast(Any, model).Add(sum(deduped) <= 2)
                added += 1

    return added


def add_team_no_overlap(model: Any, assignments: Sequence[AssignmentVariable]) -> int:
    """A team cannot have two sessions at the same time slot."""

    groups: dict[tuple[Any, Any], list[BoolVarLike]] = defaultdict(list)
    for assignment in assignments:
        team_id = assignment.team_id
        time_key = _assignment_time_key(assignment)
        if team_id is None or time_key is None:
            continue
        groups[(team_id, time_key)].append(assignment.var)
    return _add_at_most_one_groups(model, groups.values())


def add_fixed_slots(
    model: Any, assignments: Sequence[AssignmentVariable], fixed_assignments: Iterable[Any] = ()
) -> int:
    """Constraint 5: pre-placed slots are fixed to 1."""

    fixed_ids = set(fixed_assignments or ())
    added = 0
    for assignment in assignments:
        assignment_id = assignment.id
        if assignment.fixed or (
            assignment_id is not None and assignment_id in fixed_ids
        ):
            model.Add(assignment.var == 1)
            added += 1
    return added


def add_forbidden_assignments(
    model: Any, assignments: Sequence[AssignmentVariable], forbidden_assignments: Iterable[Any] = ()
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
        assignment_id = assignment.id
        team_id = assignment.team_id
        venue_id = assignment.venue_id
        if (
            assignment.forbidden
            or (assignment_id is not None and assignment_id in forbidden_ids)
            or (team_id is not None and venue_id is not None and (str(team_id), str(venue_id)) in forbidden_pairs)
        ):
            model.Add(assignment.var == 0)
            added += 1
    return added


def _assignment_day(assignment: AssignmentVariable) -> int | None:
    """Weekday number of an assignment, from its slot_id ("3:18:00" → 3)."""
    slot_id = _assignment_time_key(assignment)
    if isinstance(slot_id, str) and ":" in slot_id:
        head = slot_id.split(":", 1)[0]
        try:
            return int(head)
        except ValueError:
            return None
    return None


def _assignment_start_minutes(assignment: AssignmentVariable) -> int | None:
    """Start-of-day in minutes, from the slot_id ("3:18:00" → 1080). Lot C."""
    slot_id = _assignment_time_key(assignment)
    if isinstance(slot_id, str) and ":" in slot_id:
        _, _, rest = slot_id.partition(":")
        try:
            return _time_to_minutes(rest)
        except (ValueError, TypeError):
            return None
    return None


def add_coach_unavailability_constraints(
    model: Any,
    assignments: Sequence[AssignmentVariable],
    coach_unavailability: RuleCollection = (),
    *,
    team_coach_map: dict[str, list[str]] | None = None,
) -> int:
    """Constraint 7: coach-unavailable assignment variables are fixed to 0.

    ``coach_unavailability`` maps a coach id to a set of blocked ``(weekday,
    from_minute, to_minute)`` intervals. A slot is blocked when its day matches
    and its start time falls in ``[from, to)`` (start-based, like the team time
    windows). A whole-day block is ``(day, 0, 1440)`` — the legacy day-level
    behaviour (Lot C added the time dimension; ENG-01 fixed the old no-match bug).

    A team can have several required (non-ASSISTANT) coaches; the assignment only
    carries the first. If ``team_coach_map`` is given, EVERY coach of the team is
    checked — a co-head-coach's unavailability must block the slot too (audit
    review), otherwise ENG-01 survives for co-coached teams.
    """
    rules: Mapping[Any, Any] = coach_unavailability if isinstance(coach_unavailability, Mapping) else {}
    coach_map = team_coach_map or {}
    added = 0
    for assignment in assignments:
        blocked = assignment.coach_unavailable
        if not blocked and rules:
            coach_ids = coach_map.get(str(assignment.team_id))
            if not coach_ids:
                single = assignment.coach_id
                coach_ids = [str(single)] if single is not None else []
            day = _assignment_day(assignment)
            start = _assignment_start_minutes(assignment)
            if day is not None and start is not None:
                blocked = any(
                    iv_day == day and iv_from <= start < iv_to
                    for cid in coach_ids
                    for iv_day, iv_from, iv_to in (rules.get(str(cid)) or ())
                )
        if blocked:
            model.Add(assignment.var == 0)
            added += 1
    return added


def add_time_window_constraints(
    model: Any,
    x: Mapping[Any, BoolVarLike],
    time_windows: Iterable[dict[str, Any]] = (),
) -> tuple[int, list[dict[str, Any]]]:
    added = 0
    conflicts: list[dict[str, Any]] = []

    day_rules_by_team: dict[str, dict[str, set[int]]] = defaultdict(
        lambda: {"forced": set(), "forbidden": set(), "allowed": set()}
    )

    for constraint in time_windows or ():
        if not constraint.get("isActive", True):
            continue

        rule_type = constraint.get("ruleType") or constraint.get("rule_type")
        family = constraint.get("family")
        if rule_type == "PREFERRED" and family == "TIME":
            # PREFERRED TIME is a soft bonus handled in the objective (E-feat),
            # not a hard window here.
            continue
        # LOCK on a time/day rule is enforced as HARD (a locked window is fixed).
        if rule_type not in ("HARD", "LOCK") or family not in ("TIME", "DAY"):
            continue

        team_id = constraint.get("scope_target_id") or constraint.get("scopeTargetId")
        if team_id is None:
            continue
        team_id_text = str(team_id)
        config = constraint.get("config") or {}

        if family == "DAY":
            day_rules_by_team[team_id_text]["forced"].update(_day_int_set(config.get("forcedDays")))
            day_rules_by_team[team_id_text]["forbidden"].update(_day_int_set(config.get("forbiddenDays")))
            # An empty allowedDays is treated as "unconfigured" (no restriction),
            # matching the coach-availability whitelist semantics — never "no day
            # allowed" (which would force the team to zero sessions).
            day_rules_by_team[team_id_text]["allowed"].update(_day_int_set(config.get("allowedDays")))
            continue

        min_start_time = config.get("minStartTime")
        max_start_time = config.get("maxStartTime")
        max_end_time = config.get("maxEndTime")
        min_start_minutes = _time_to_minutes(min_start_time) if min_start_time is not None else None
        max_start_minutes = _time_to_minutes(max_start_time) if max_start_time is not None else None
        max_end_minutes = _time_to_minutes(max_end_time) if max_end_time is not None else None

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
                continue
            # maxEndTime: the session must END by that time (start + its duration).
            # The duration is the slot's own (venue/day/start), default 90 min.
            if max_end_minutes is not None:
                duration = model.slot_durations.get((slot_key[1], slot_key[2], slot_key[3]), DEFAULT_SESSION_MINUTES)
                if slot_start_minutes + duration > max_end_minutes:
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
        original_forbidden = set(day_rules["forbidden"])
        allowed_day_set = day_rules["allowed"]
        forbidden_day_set = set(original_forbidden)
        # allowedDays = whitelist: forbid every day the team could train on that
        # is not allowed (the complement, restricted to days that actually exist).
        if allowed_day_set:
            forbidden_day_set |= {
                day for day in team_day_vars.get(team_id_text, {}) if day not in allowed_day_set
            }
        # Contradiction → the team can be placed on NO day. Two shapes: a forced day
        # is also forbidden, OR a whitelist ('uniquement'/allowedDays) has ALL its
        # days explicitly forbidden ('évite'). Both are checked against the ORIGINAL
        # forbidden set (not the whitelist complement) so the diagnostic is explicit
        # rather than a downstream "insufficient gym slots" (audit ENG-16 review).
        forced_vs_forbidden = forced_day_set & original_forbidden
        allowed_all_forbidden = bool(allowed_day_set) and not (allowed_day_set - original_forbidden)
        if forced_vs_forbidden or allowed_all_forbidden:
            conflicts.append({
                "id": f"day_constraint_conflict-{team_id_text}",
                "type": "day_constraint_conflict",
                "severity": "ERROR",
                "teamId": team_id_text,
                "message": (
                    f"Team {team_id_text} has contradictory day rules "
                    "(the allowed/forced days are all forbidden); the team is forced to 0 slots."
                ),
                "suggestions": [
                    "Remove the overlapping days between the 'only these days' / 'forced' rule and the 'avoid' rule.",
                ],
                "createdAt": datetime.now(UTC).isoformat(),
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
    assignments: Sequence[AssignmentVariable],
    *,
    teams: Iterable[Any] = (),
    min_sessions_by_team: Mapping[Any, int] | None = None,
    priority_tiers: Mapping[int, int] | None = None,
) -> int:
    """MIN_SESSIONS as a soft TARGET (ENG-18): rewards reaching each team's effective
    minimum via the objective; NOT a hard "every team gets at least its minimum" guarantee
    (production passes 0 as the hard floor, so no hard MIN_SESSIONS constraint is posted)."""

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
        team_id = assignment.team_id
        if team_id is None:
            continue
        assignments_by_team[team_id].append(assignment.var)

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
    assignments: Sequence[AssignmentVariable],
    *,
    forced_venues: Mapping[Any, Any] | None = None,
) -> int:
    """Constraint 11: when a venue is forced, all other venues are fixed to 0."""

    added = 0
    for assignment in assignments:
        venue_id = assignment.venue_id
        target_venue_id = _forced_venue_id(assignment, forced_venues)
        if target_venue_id is None or venue_id is None or venue_id == target_venue_id:
            continue
        model.Add(assignment.var == 0)
        added += 1
    return added


def add_venue_minimum_constraints(
    model: Any,
    x: Mapping[Any, BoolVarLike],
    venue_minimums: Iterable[Mapping[str, Any]] = (),
) -> tuple[int, list[dict[str, Any]]]:
    """ALIGN-05: 'at least N of the team's sessions at venue V'.

    A COUNT (sum(team vars at V) >= N), NOT a forced venue. If the team has fewer
    available slots at V than N, it is provably unsatisfiable → emit an explicit
    diagnostic (never a silent INFEASIBLE)."""
    added = 0
    conflicts: list[dict[str, Any]] = []

    for rule in venue_minimums or []:
        team_id = str(rule.get("scope_target_id"))
        venue_id = str(rule.get("venue_id"))
        minimum = int(rule.get("min") or 1)

        team_venue_vars = [
            var
            for slot_key, var in x.items()
            if isinstance(slot_key, tuple) and len(slot_key) >= 2 and str(slot_key[0]) == team_id and str(slot_key[1]) == venue_id
        ]

        # Reachability is bounded by the number of DISTINCT DAYS available at the
        # venue, NOT the raw slot count: a team plays ≤1 session/day (per-day cap),
        # so two same-day slots still contribute at most ONE session. Counting raw
        # vars would let a provably-infeasible minimum slip past → silent INFEASIBLE.
        team_venue_days = {
            slot_key[2]
            for slot_key in x
            if isinstance(slot_key, tuple) and len(slot_key) >= 3 and str(slot_key[0]) == team_id and str(slot_key[1]) == venue_id
        }

        if len(team_venue_days) < minimum:
            conflicts.append({
                "id": f"venue_minimum_unreachable-{team_id}-{venue_id}",
                "type": "venue_minimum_unreachable",
                "severity": "ERROR",
                "teamId": team_id,
                "message": (
                    f"Team {team_id} cannot reach {minimum} session(s) at venue {venue_id}: "
                    f"only {len(team_venue_days)} distinct day(s) available there (≤1 session/day)."
                ),
                "suggestions": ["Lower the minimum, or add availability slots on OTHER days at this venue."],
                "createdAt": datetime.now(UTC).isoformat(),
            })
            continue

        model.Add(sum(team_venue_vars) >= minimum)
        added += 1

    return added, conflicts


def _normalise_assignments(
    assignments: Iterable[AssignmentInput] | Mapping[Any, BoolVarLike] | None
) -> list[AssignmentVariable]:
    """Convert any accepted input into a homogeneous ``list[AssignmentVariable]``.

    Three input shapes are supported:
      * a Mapping of ``key -> BoolVar`` (the T22 ``model.x``) — built via
        ``_assignment_from_mapping_item``;
      * an iterable of mappings/dicts (production sends ``list[dict]``);
      * an iterable of objects already exposing the assignment attributes
        (including ``AssignmentVariable`` itself, returned unchanged).

    After this call every downstream constraint builder reads real, typed
    attributes instead of duck-typing over ``Any`` (ENG-05).
    """
    if assignments is None:
        return []
    if isinstance(assignments, Mapping):
        return [_assignment_from_mapping_item(key, value) for key, value in assignments.items()]
    return [_as_assignment_variable(item) for item in assignments]


def _coerce_id(value: Any) -> Any:
    """Reproduce the removed accessors' id-normalisation EXACTLY: ``scalar_id``
    (unwrap a nested id/uuid/name), and nothing more.

    Deliberately NOT wrapped in ``str()``: the old ``_team_id``/``_venue_id``/…
    accessors returned the bare ``scalar_id`` result, so this keeps the object
    path byte-identical to before on every input — including hypothetical
    non-string ids, where an added ``str()`` would have desynced the group keys
    here from the un-stringified ``min_sessions``/``forced_venues`` lookup keys.
    All real inputs are strings, so this is a no-op on them.
    """
    return scalar_id(value)


def _as_assignment_variable(obj: AssignmentInput) -> AssignmentVariable:
    """Convert a single mapping/object element to an ``AssignmentVariable``.

    Already-typed ``AssignmentVariable`` instances are returned unchanged. For
    mappings and generic objects the canonical fields are read through the same
    alias lists the removed ``_``-accessors used, so behaviour is identical.
    """
    if isinstance(obj, AssignmentVariable):
        return obj
    return AssignmentVariable(
        var=assignment_var(obj, skip_none=False),
        team_id=_coerce_id(assignment_team_id(obj, skip_none=False)),
        slot_id=_coerce_id(get_field(obj, "slot_id", "time_slot_id", "timeslot_id", "slot", "time_slot", default=None)),
        venue_id=_coerce_id(get_field(obj, "venue_id", "room_id", "location_id", "venue", "room", "location", default=None)),
        coach_id=_coerce_id(get_field(obj, "coach_id", "trainer_id", "coach", "trainer", default=None)),
        session_id=_coerce_id(get_field(obj, "session_id", "lesson_id", "event_id", "session", "lesson", "event", default=None)),
        player_ids=_raw_player_ids(obj),
        start=get_field(obj, "start", "start_minute", "start_time", "starts_at", default=None),
        end=get_field(obj, "end", "end_minute", "end_time", "ends_at", default=None),
        fixed=_bool_field(obj, "fixed", "is_fixed", "pre_placed", "preplaced", "is_pre_placed"),
        forbidden=_bool_field(obj, "forbidden", "is_forbidden"),
        coach_unavailable=_bool_field(obj, "coach_unavailable", "is_coach_unavailable"),
        forced_venue_id=_coerce_id(get_field(obj, "forced_venue_id", "forced_room_id", "forced_venue", "forced_room", default=None)),
        id=_coerce_id(get_field(obj, "id", "assignment_id", "key", default=None)),
    )


def _raw_player_ids(obj: AssignmentInput) -> Sequence[str]:
    """Read the player-id sequence off a raw mapping/object during conversion,
    honouring the historical alias list. Element coercion happens later in
    ``_player_ids`` (kept identical to the old read-time behaviour)."""
    players = get_field(
        obj,
        "player_ids",
        "participant_ids",
        "athlete_ids",
        "players",
        "participants",
        "athletes",
        default=(),
    )
    if players is None:
        return ()
    if isinstance(players, (str, bytes)):
        return [scalar_id(players)]
    return [scalar_id(player) for player in players]


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


def _get(source: Any, *names: str, default: Any = None) -> Any:
    return get_field(source, *names, default=default, skip_none=False)


def _scalar_id(value: Any) -> Any:
    return scalar_id(value)


def _assignment_time_key(assignment: AssignmentVariable) -> Any:
    """Grouping key for "same time" collision detection.

    ``slot_id`` when present (a ``"day:HH:MM"`` string), else the
    ``(start, end)`` minute pair when both are present, else ``None``. This
    reproduces the old ``_time_key`` accessor exactly; the legacy
    ``time_key``/``time`` alias branch is dropped because the dataclass has no
    such field and no input ever supplied one.
    """
    if assignment.slot_id is not None:
        return assignment.slot_id
    if assignment.start is not None and assignment.end is not None:
        return (assignment.start, assignment.end)
    return None


def _intervals_overlap(a_start: Any, a_end: Any, b_start: Any, b_end: Any) -> bool:
    return bool(a_start < b_end and b_start < a_end)


def _interval_key(person_id: Any, day: Any, pair_index: Any) -> str:
    return f"{person_id}:{day}:{pair_index}"


def _extract_interval(assignment: AssignmentVariable) -> tuple[int | None, int | None, str | None]:
    """Extract (start_minutes, end_minutes, day) from an assignment.

    Returns (None, None, None) when start/end or slot_id are missing so callers
    can fall back to ``_assignment_time_key`` grouping.
    """
    start = assignment.start
    end = assignment.end
    if start is None or end is None:
        return None, None, None

    start_minutes = int(start) if not isinstance(start, int) else start
    end_minutes = int(end) if not isinstance(end, int) else end

    slot_id = assignment.slot_id
    if slot_id is None:
        return start_minutes, end_minutes, None
    day = str(slot_id).split(":")[0]
    return start_minutes, end_minutes, day


def _add_interval_at_most_one(
    model: Any,
    person_entries: dict[str, list[tuple[int, int, BoolVarLike, str]]],
) -> int:
    """Add pairwise ``varA + varB <= 1`` for overlapping intervals per person per day.

    Args:
        model: CP-SAT model.
        person_entries: ``dict[person_id, list[(start, end, var, day)]]``.

    Returns: number of pairwise constraints added.
    """
    added = 0
    for entries in person_entries.values():
        by_day: dict[str, list[tuple[int, int, BoolVarLike]]] = defaultdict(list)
        for start, end, var, day in entries:
            by_day[day].append((start, end, var))

        for day_entries in by_day.values():
            for i in range(len(day_entries)):
                a_start, a_end, var_a = day_entries[i]
                for j in range(i + 1, len(day_entries)):
                    b_start, b_end, var_b = day_entries[j]
                    if var_a is var_b:
                        continue
                    if _intervals_overlap(a_start, a_end, b_start, b_end):
                        model.Add(var_a + var_b <= 1)
                        added += 1
    return added


def _find_consecutive_triples(
    entries: list[tuple[int, int, AssignmentVariable]],
) -> list[tuple[tuple[int, int, AssignmentVariable], tuple[int, int, AssignmentVariable], tuple[int, int, AssignmentVariable]]]:
    """Find consecutive triples A->B->C where A.end == B.start and B.end == C.start.

    Uses a start-time index so that multiple entries sharing the same start
    (e.g. the same slot at different venues) are all considered as candidates.
    """
    by_start: dict[int, list[tuple[int, int, AssignmentVariable]]] = defaultdict(list)
    for entry in entries:
        by_start[entry[0]].append(entry)

    triples: list[
        tuple[
            tuple[int, int, AssignmentVariable],
            tuple[int, int, AssignmentVariable],
            tuple[int, int, AssignmentVariable],
        ]
    ] = []
    for a in entries:
        for b in by_start.get(a[1], []):
            if b is a:
                continue
            for c in by_start.get(b[1], []):
                if c is a or c is b:
                    continue
                triples.append((a, b, c))
    return triples


def _bool_field(obj: AssignmentInput, *names: str) -> bool:
    """Read a boolean flag off a raw mapping/object at conversion time, honouring
    the historical alias list (e.g. ``fixed`` / ``is_fixed``)."""
    return any(bool(_get(obj, name, default=False)) for name in names)


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
    assignment: AssignmentVariable, forced_venues: Mapping[Any, Any] | None
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

    team_id = assignment.team_id
    session_id = assignment.session_id
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
    assignments: Sequence[AssignmentVariable],
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
        team_id = assignment.team_id
        slot_id = assignment.slot_id
        if team_id is None or slot_id is None:
            continue
        day = str(slot_id).split(":")[0]
        groups[(str(team_id), day)].append(assignment.var)

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
    assignments: Sequence[AssignmentVariable],
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
        team_id = assignment.team_id
        venue_id = assignment.venue_id
        slot_id = assignment.slot_id
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
        groups[(str(venue_id), day)].append((team_id_str, start_minutes, assignment.var))

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


def _to_day_int(value: Any) -> int | None:
    """Best-effort weekday int. Legacy string day names (e.g. 'monday') and other
    non-numeric values are skipped, never crash the whole solve (audit review)."""
    try:
        return int(value)
    except (TypeError, ValueError):
        return None


def _day_int_set(values: Any) -> set[int]:
    return {d for d in (_to_day_int(v) for v in (values or [])) if d is not None}


def _set_venue_rule(
    rules: dict[str, str],
    team_id: str,
    venue_id: str,
    constraint: dict[str, Any],
    warnings: list[dict[str, Any]],
) -> None:
    """Single-venue-per-team rule maps are last-wins by structure — surface a
    conflicting overwrite instead of silently dropping the earlier rule (the
    same silent-overwrite class as ENG-13)."""
    existing = rules.get(team_id)
    if existing is not None and existing != venue_id:
        warnings.append(_not_honored_warning(
            constraint, "INFO",
            "Plusieurs règles de gymnase pour la même équipe — la dernière remplace la précédente.",
        ))
    rules[team_id] = venue_id


def _not_honored_warning(constraint: dict[str, Any], severity: str, message: str) -> dict[str, Any]:
    """A diagnostics entry for a constraint the solver cannot (fully) honor —
    same shape as the hard-conflict diagnostics merged by main.py."""
    constraint_id = constraint.get("id")

    # Shape must match DiagnosticSchema (no extra keys) — the source constraint
    # id rides in the diagnostic id; the manager-facing message uses the
    # constraint's human name when available.
    label = constraint.get("name") or constraint_id
    return {
        "id": f"constraint_not_honored-{constraint_id}",
        "type": "constraint_not_honored",
        "severity": severity,
        "teamId": None,
        "message": f"{message} (contrainte « {label} »)",
        "suggestions": [],
        "createdAt": datetime.now(UTC).isoformat(),
    }


def parse_v2_constraints(constraints: list[dict[str, Any]]) -> ParsedConstraints:
    """Parse v2 constraints[] array into typed, solver-ready rule collections."""

    result: ParsedConstraints = {
        "fixed_slots": [],
        "forbidden_assignments": [],
        "coach_unavailability": {},
        "forced_venues": {},
        "preferred_venues": {},
        "avoided_venues": [],
        "venue_minimums": [],
        "venue_capacity_caps": {},
        "time_windows": [],
        "priority_tiers": {},
        "team_coach_map": {},
        "team_player_map": {},
        "parse_warnings": [],
    }

    # Per-coach availability accumulators (merged after the loop — see the
    # COACH_AVAILABILITY branch — accumulate blocked (day, from, to) intervals with
    # UNION semantics. By De Morgan this expresses both the blacklist UNION and the
    # whitelist INTERSECTION (complement of an available window = blocked parts), so
    # no separate merge step is needed (ENG-13 algebra preserved, now with time).
    coach_blocked_intervals: dict[str, set[tuple[int, int, int]]] = {}

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

        # BONUS never had a distinct semantic anywhere (no weight, no branch) —
        # the UI no longer offers it; legacy rows are honored as PREFERRED
        # (soft), which is more honest than silently dropping them (ENG-12).
        if rule_type == "BONUS":
            rule_type = "PREFERRED"
            c = {**c, "ruleType": "PREFERRED"}

        if rule_type == "LOCK" and family in ("TIME", "DAY"):
            # A LOCK on a time/day rule means "keep this window fixed" — same
            # effect as HARD for the solver. Route it through time_windows;
            # add_time_window_constraints treats LOCK as HARD.
            result["time_windows"].append(c)

        elif c_type == "TEAM_COACH":
            team_id = c.get("teamId") or c.get("team_id") or scope_target_id
            coach_id = (
                metadata.get("coachId")
                or metadata.get("coach_id")
                or c.get("value")
                or config.get("coachId")
                or config.get("coach_id")
            )
            # Only the MAIN coach is a HARD no-overlap resource: a team never
            # trains without its head coach, so the head coach is implicitly
            # present at every session. An ASSISTANT is optional and must NOT
            # block placement (e.g. a team can be scheduled while the assistant
            # is busy elsewhere). Missing role → treated as MAIN (legacy-safe).
            role = str(metadata.get("role") or "MAIN").strip().upper()
            if team_id and coach_id and role != "ASSISTANT":
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
            # Days are weekday numbers (ints, as the wizard sends them). Store a
            # set of unavailable days; a non-empty availableDays whitelist is the
            # complement. An empty/absent availableDays adds no restriction (an
            # empty whitelist is treated as "unconfigured", never "blocked every
            # day" — which would force the team to zero sessions).
            # Combine multiple constraints on one coach with the right algebra
            # (ENG-13 — assignment was last-wins): blacklists UNION ("unavailable
            # Monday" + "unavailable Wednesday" = both blocked); whitelists
            # INTERSECT ("available Monday" + "available Tuesday" must not union
            # complements into "unavailable every day"). Merged after the loop.
            coach_key = str(scope_target_id)
            # Optional time window (Lot C): absent → whole day (0..1440), i.e. the
            # legacy day-level behaviour, so old configs stay byte-identical.
            from_min = _time_to_minutes(config["fromTime"]) if config.get("fromTime") else 0
            to_min = _time_to_minutes(config["untilTime"]) if config.get("untilTime") else 1440
            intervals = coach_blocked_intervals.setdefault(coach_key, set())
            for day in _day_int_set(config.get("unavailableDays")):
                intervals.add((day, from_min, to_min))
            available_set = _day_int_set(config.get("availableDays"))
            if available_set:
                # Available ONLY on these days within [from, to] → block the
                # complement: every other day whole, plus the out-of-window parts
                # of the available days.
                for day in range(0, 8):
                    if day not in available_set:
                        intervals.add((day, 0, 1440))
                        continue
                    if from_min > 0:
                        intervals.add((day, 0, from_min))
                    if to_min < 1440:
                        intervals.add((day, to_min, 1440))
            # Coach availability is always enforced HARD (a person cannot be in
            # two places); the UI now forces HARD — surface legacy soft rows.
            if rule_type not in (None, "HARD", "LOCK"):
                result["parse_warnings"].append(_not_honored_warning(
                    c, "INFO",
                    "Une disponibilité de coach est toujours appliquée comme obligatoire "
                    f"(ruleType {rule_type} reçu).",
                ))

        elif (
            family == "FACILITY"
            and config.get("preferredVenueId")
            # LOCK on a venue rule = "keep this venue fixed" — dur, like
            # LOCK TIME/DAY (was dead end-to-end, ENG-12).
            and rule_type in ("HARD", "LOCK")
            and scope == "TEAM"
            and scope_target_id
        ):
            _set_venue_rule(result["forced_venues"], scope_target_id, config["preferredVenueId"], c, result["parse_warnings"])

        elif (
            family == "FACILITY"
            and config.get("forcedVenueId")
            and rule_type in ("HARD", "LOCK")
            and scope == "TEAM"
            and scope_target_id
        ):
            _set_venue_rule(result["forced_venues"], scope_target_id, config["forcedVenueId"], c, result["parse_warnings"])

        elif (
            family == "FACILITY"
            and config.get("minAtVenueId")
            and rule_type in ("HARD", "LOCK")
            and scope == "TEAM"
            and scope_target_id
        ):
            # "au moins N séances dans ce gymnase" — un compte, PAS un forçage de
            # toutes les séances (≠ forcedVenueId). Défaut N=1 (cas courant).
            raw_count = config.get("minAtVenueCount")
            min_count = int(raw_count) if raw_count is not None else 1
            result["venue_minimums"].append({
                "scope_target_id": str(scope_target_id),
                "venue_id": str(config["minAtVenueId"]),
                "min": max(1, min_count),
            })

        elif (
            family == "FACILITY"
            and config.get("preferredVenueId")
            and rule_type == "PREFERRED"
            and scope == "TEAM"
            and scope_target_id
        ):
            _set_venue_rule(result["preferred_venues"], scope_target_id, config["preferredVenueId"], c, result["parse_warnings"])

        elif family == "FACILITY" and config.get("forbiddenVenueId"):
            # rule_type decides HOW hard "avoid this venue" is (ENG-11 — this
            # branch used to escalate every ruleType into a hard interdiction,
            # making INFEASIBLE possible on a mere preference).
            if rule_type in ("HARD", "LOCK", None):
                result["forbidden_assignments"].append(
                    {"scope_target_id": scope_target_id, "venue_id": config["forbiddenVenueId"]}
                )
            elif scope_target_id:
                # PREFERRED (incl. normalized BONUS): soft "avoid" — an
                # objective malus, never a feasibility constraint.
                result["avoided_venues"].append(
                    {"scope_target_id": str(scope_target_id), "venue_id": str(config["forbiddenVenueId"])}
                )
            else:
                # Soft avoid without a target cannot be applied — say so (the
                # sibling hard/target-less variants warn too, never a silent drop).
                result["parse_warnings"].append(_not_honored_warning(
                    c, "WARNING",
                    "Contrainte de gymnase sans équipe cible — non appliquée.",
                ))

        elif family == "FACILITY_CAPACITY" and config.get("venueId"):
            # Max teams allowed simultaneously per slot of this venue. Applied in
            # _solve as min(trainingSlot.capacity, maxTeams) — can only tighten,
            # never widen a venue the backend already capped to 1 (canSplit=false).
            # Keyed strictly by config.venueId: scope_target_id under a TEAM scope
            # is a team id and would never match a venue slot (audit review).
            max_teams = config.get("maxTeams")
            if max_teams is not None:
                result["venue_capacity_caps"][str(config["venueId"])] = int(max_teams)

        elif c.get("type") == "PRIORITY_TIER":
            metadata = c.get("metadata") or {}
            tier_id = metadata.get("id")
            default_min = metadata.get("defaultMinSessions")
            if tier_id is not None and default_min is not None:
                result["priority_tiers"][int(tier_id)] = int(default_min)

        elif family in ("TIME", "DAY"):
            if scope_target_id is None:
                # The backend expands club-wide constraints into per-team ones;
                # a target-less window reaching the engine would be silently
                # skipped downstream (add_time_window_constraints requires a
                # team) — surface it instead of a silent no-op.
                result["parse_warnings"].append(_not_honored_warning(
                    c, "WARNING",
                    "Contrainte sans équipe cible — non appliquée (le backend doit "
                    "l'étendre par équipe).",
                ))
            else:
                result["time_windows"].append(c)

        elif family == "FACILITY" and (
            config.get("preferredVenueId") or config.get("forcedVenueId")
        ):
            # A wizard-emitted venue rule that matched no branch (target-less
            # scope) — an explicit warning, never a silent drop. Other FACILITY
            # variants (e.g. the cockpit venue_closed marker, enforced via the
            # backend expandClosedVenues expansion) are deliberate no-ops here
            # and must NOT raise a false "not applied" alarm.
            result["parse_warnings"].append(_not_honored_warning(
                c, "WARNING",
                "Contrainte de gymnase sans équipe cible — non appliquée.",
            ))

        elif family not in _KNOWN_FAMILIES and c_type not in _KNOWN_TYPES and rule_type != "LOCK":
            # Only warn when neither the family NOR the type is recognised — a
            # genuine contract drift. A recognised family whose specific
            # config/scope variant isn't handled (e.g. a CLUB-scope FACILITY) is
            # a deliberate no-op, not drift, and must not spam warnings (review).
            logger.warning(
                "unrecognised constraint dropped: id=%s type=%s family=%s ruleType=%s",
                c.get("id"), c_type, family, rule_type,
            )

    # The blocked-interval accumulation IS the coach-availability algebra (union of
    # every constraint's blocked intervals — see coach_blocked_intervals above).
    result["coach_unavailability"] = {k: v for k, v in coach_blocked_intervals.items() if v}

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
    "add_team_no_overlap",
    "add_time_window_constraints",
    "parse_v2_constraints",
]
