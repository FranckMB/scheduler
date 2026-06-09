from __future__ import annotations

from datetime import time

from pydantic import BaseModel, ConfigDict, Field


class SerializableModel(BaseModel):
    model_config = ConfigDict(extra="forbid", populate_by_name=True)


class VenueSchema(SerializableModel):
    id: str
    name: str
    is_external: bool = Field(default=False, alias="isExternal")
    color: str | None = None
    latitude: str | None = None
    longitude: str | None = None
    source: str = ""
    external_ref: str | None = Field(default=None, alias="externalRef")
    is_active: bool = Field(default=False, alias="isActive")
    parent_venue_id: str | None = Field(default=None, alias="parentVenueId")


class TeamSchema(SerializableModel):
    id: str
    sport_category_id: str = Field(alias="sportCategoryId")
    priority_tier_id: int = Field(alias="priorityTierId")
    name: str
    gender: str | None = None
    sessions_per_week: int = Field(alias="sessionsPerWeek")
    min_sessions_override: int | None = Field(default=None, alias="minSessionsOverride")
    match_day: int | None = Field(default=None, alias="matchDay")
    forced_venue_id: str | None = Field(default=None, alias="forcedVenueId")
    is_active: bool = Field(default=False, alias="isActive")
    parent_team_id: str | None = Field(default=None, alias="parentTeamId")
    ffbb_team_id: str | None = Field(default=None, alias="ffbbTeamId")


class CoachSchema(SerializableModel):
    id: str
    first_name: str = Field(alias="firstName")
    last_name: str = Field(alias="lastName")
    email: str | None = None
    phone: str | None = None
    max_days_override: int | None = Field(default=None, alias="maxDaysOverride")
    max_days_override_confirmed: bool = Field(default=False, alias="maxDaysOverrideConfirmed")
    acceptable_late_minutes: int | None = Field(default=None, alias="acceptableLateMinutes")
    is_active: bool = Field(default=False, alias="isActive")
    parent_coach_id: str | None = Field(default=None, alias="parentCoachId")


class ConstraintSchema(SerializableModel):
    id: str
    team_id: str = Field(alias="teamId")
    type: str
    severity: str | None = None
    value: str | int | float | bool | None = None
    metadata: dict[str, object] = Field(default_factory=dict)


class ScheduleSlotTemplateSchema(SerializableModel):
    id: str
    team_id: str = Field(alias="teamId")
    venue_id: str = Field(alias="venueId")
    coach_id: str | None = Field(default=None, alias="coachId")
    day_of_week: int = Field(alias="dayOfWeek")
    start_time: time = Field(alias="startTime")
    duration_minutes: int = Field(alias="durationMinutes")
    lock_level: str = Field(default="NONE", alias="lockLevel")
    temporary_lock: bool = Field(default=False, alias="temporaryLock")
    temporary_lock_for: str | None = Field(default=None, alias="temporaryLockFor")
    temporary_min_sessions_override: int | None = Field(
        default=None,
        alias="temporaryMinSessionsOverride",
    )
    pending_constraint_suggestion: dict[str, object] | None = Field(
        default=None,
        alias="pendingConstraintSuggestion",
    )


class ScheduleInputSchema(SerializableModel):
    version: str = "1.0"
    club_id: str = Field(alias="clubId")
    season_id: str = Field(alias="seasonId")
    schedule_name: str | None = Field(default=None, alias="scheduleName")
    solver_seed: int = Field(default=42, alias="solverSeed")
    venues: list[VenueSchema] = Field(default_factory=list)
    teams: list[TeamSchema] = Field(default_factory=list)
    coaches: list[CoachSchema] = Field(default_factory=list)
    constraints: list[ConstraintSchema] = Field(default_factory=list)
    slot_templates: list[ScheduleSlotTemplateSchema] = Field(default_factory=list, alias="slotTemplates")
