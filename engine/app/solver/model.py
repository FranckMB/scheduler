from __future__ import annotations

from collections.abc import Iterable, Mapping
from datetime import datetime, time
from typing import Any, cast

from ortools.sat.python import cp_model

SLOT_MINUTES = 15
HARD_LOCK_LEVEL = "HARD"

SlotKey = tuple[str, str, int, str]
VenueSlotKey = tuple[str, int, str]
VariableMap = dict[SlotKey, cp_model.IntVar]


class ScheduleCpModel(cp_model.CpModel):
    def __init__(self) -> None:
        super().__init__()
        self.x: VariableMap = {}
        self.available_slots: tuple[VenueSlotKey, ...] = ()
        self.locked_slots: tuple[dict[str, Any], ...] = ()
        self.hard_slot_keys: frozenset[SlotKey] = frozenset()
        self.blocked_venue_slots: frozenset[VenueSlotKey] = frozenset()

    def NumVariables(self) -> int:
        return len(self.Proto().variables)

    def num_variables(self) -> int:
        return self.NumVariables()


def build_model(data: Mapping[str, Any] | Any) -> ScheduleCpModel:
    model = ScheduleCpModel()
    teams = _team_ids(data)
    available_slots = _derive_available_slots(data)
    locked_slots, hard_slot_keys, blocked_venue_slots = _extract_hard_locks(data)

    model.available_slots = available_slots
    model.locked_slots = locked_slots
    model.hard_slot_keys = hard_slot_keys
    model.blocked_venue_slots = blocked_venue_slots

    for team_id in teams:
        for venue_id, day_of_week, slot_start in available_slots:
            slot_key = (team_id, venue_id, day_of_week, slot_start)
            venue_slot_key = (venue_id, day_of_week, slot_start)
            if slot_key in hard_slot_keys or venue_slot_key in blocked_venue_slots:
                continue

            model.x[slot_key] = cast(Any, model).NewBoolVar(_variable_name(slot_key))

    return model


def _team_ids(data: Mapping[str, Any] | Any) -> tuple[str, ...]:
    return tuple(str(_required(team, "id")) for team in _collection(data, "teams"))


DEFAULT_START_MINUTES = 8 * 60  # 08:00
DEFAULT_END_MINUTES = 22 * 60  # 22:00
DEFAULT_DAYS_OF_WEEK = (1, 2, 3, 4, 5)  # Mon-Fri


def _derive_available_slots(data: Mapping[str, Any] | Any) -> tuple[VenueSlotKey, ...]:
    slots: set[VenueSlotKey] = set()

    for venue in _collection(data, "venues"):
        venue_id = str(_required(venue, "id"))
        availability_windows = _collection(venue, "availability", "availabilities")

        if availability_windows:
            for window in availability_windows:
                day_of_week = int(_required(window, "day_of_week", "dayOfWeek"))
                start_minutes = _time_to_minutes(_required(window, "start_time", "startTime"))
                end_minutes = _time_to_minutes(_required(window, "end_time", "endTime"))

                if end_minutes <= start_minutes:
                    raise ValueError(f"availability for venue {venue_id} ends before it starts")

                for slot_minutes in range(start_minutes, end_minutes, SLOT_MINUTES):
                    slots.add((venue_id, day_of_week, _format_time(slot_minutes)))
        else:
            # Default: all standard weekdays, 08:00-22:00
            for day_of_week in DEFAULT_DAYS_OF_WEEK:
                for slot_minutes in range(DEFAULT_START_MINUTES, DEFAULT_END_MINUTES, SLOT_MINUTES):
                    slots.add((venue_id, day_of_week, _format_time(slot_minutes)))

    return tuple(sorted(slots, key=_sort_venue_slot))


def _extract_hard_locks(
    data: Mapping[str, Any] | Any,
) -> tuple[tuple[dict[str, Any], ...], frozenset[SlotKey], frozenset[VenueSlotKey]]:
    locked_slots: list[dict[str, Any]] = []
    hard_slot_keys: set[SlotKey] = set()
    blocked_venue_slots: set[VenueSlotKey] = set()

    for slot in _slot_templates(data):
        lock_level = str(_value(slot, "lock_level", "lockLevel", default="")).upper()
        if lock_level != HARD_LOCK_LEVEL:
            continue

        team_id = str(_required(slot, "team_id", "teamId"))
        venue_id = str(_required(slot, "venue_id", "venueId"))
        day_of_week = int(_required(slot, "day_of_week", "dayOfWeek"))
        start_minutes = _time_to_minutes(_required(slot, "start_time", "startTime"))
        duration_minutes = int(_value(slot, "duration_minutes", "durationMinutes", default=SLOT_MINUTES))

        if duration_minutes <= 0:
            raise ValueError(f"HARD slot for team {team_id} has a non-positive duration")

        normalized_slot = dict(slot) if isinstance(slot, Mapping) else {}
        normalized_slot.update(
            {
                "team_id": team_id,
                "venue_id": venue_id,
                "day_of_week": day_of_week,
                "start_time": _format_time(start_minutes),
                "duration_minutes": duration_minutes,
                "lock_level": HARD_LOCK_LEVEL,
            }
        )
        locked_slots.append(normalized_slot)

        for slot_start in _duration_slot_starts(start_minutes, duration_minutes):
            normalized_start = _format_time(slot_start)
            hard_slot_keys.add((team_id, venue_id, day_of_week, normalized_start))
            blocked_venue_slots.add((venue_id, day_of_week, normalized_start))

    return tuple(locked_slots), frozenset(hard_slot_keys), frozenset(blocked_venue_slots)


def _slot_templates(data: Mapping[str, Any] | Any) -> Iterable[Mapping[str, Any] | Any]:
    names = (
        "schedule_slot_templates",
        "scheduleSlotTemplates",
        "slot_templates",
        "slotTemplates",
        "locked_slots",
        "lockedSlots",
        "slots",
    )
    for name in names:
        yield from _collection(data, name)


def _collection(source: Mapping[str, Any] | Any, *names: str) -> Iterable[Any]:
    for name in names:
        values = _value(source, name, default=None)
        if values is None:
            continue
        if isinstance(values, Iterable) and not isinstance(values, (str, bytes, Mapping)):
            return values
        raise TypeError(f"{name} must be a list-like collection")
    return ()


def _required(source: Mapping[str, Any] | Any, *names: str) -> Any:
    value = _value(source, *names, default=None)
    if value is None:
        joined_names = "/".join(names)
        raise ValueError(f"missing required field: {joined_names}")
    return value


def _value(source: Mapping[str, Any] | Any, *names: str, default: Any = None) -> Any:
    for name in names:
        if isinstance(source, Mapping):
            if name in source and source[name] is not None:
                return source[name]
            continue

        value = getattr(source, name, None)
        if value is not None:
            return value

    return default


def _time_to_minutes(value: Any) -> int:
    if isinstance(value, datetime):
        return value.hour * 60 + value.minute
    if isinstance(value, time):
        return value.hour * 60 + value.minute
    if isinstance(value, int):
        return value

    text = str(value).strip()
    if "T" in text:
        text = text.split("T", 1)[1]
    elif " " in text:
        text = text.split(" ", 1)[1]

    text = text.removesuffix("Z").split("+", 1)[0]
    if "-" in text[1:]:
        text = text.rsplit("-", 1)[0]

    parts = text.split(":")
    if len(parts) < 2:
        raise ValueError(f"invalid time value: {value!r}")

    return int(parts[0]) * 60 + int(parts[1])


def _duration_slot_starts(start_minutes: int, duration_minutes: int) -> Iterable[int]:
    slot_count = (duration_minutes + SLOT_MINUTES - 1) // SLOT_MINUTES
    for offset in range(slot_count):
        yield start_minutes + offset * SLOT_MINUTES


def _format_time(minutes: int) -> str:
    hours, remainder = divmod(minutes, 60)
    return f"{hours:02d}:{remainder:02d}"


def _sort_venue_slot(slot: VenueSlotKey) -> tuple[str, int, int]:
    venue_id, day_of_week, slot_start = slot
    return venue_id, day_of_week, _time_to_minutes(slot_start)


def _variable_name(slot_key: SlotKey) -> str:
    team_id, venue_id, day_of_week, slot_start = slot_key
    return f"x[{team_id},{venue_id},{day_of_week},{slot_start}]"
