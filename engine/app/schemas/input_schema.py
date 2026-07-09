from __future__ import annotations

from datetime import time

from pydantic import BaseModel, ConfigDict, Field, model_validator

# A10 (DoS "generation bomb" guard): bound every input list so an oversized payload is
# rejected with 422 at the boundary, before CP-SAT builds the model. Values are generous
# (~10x a large FFBB club) — they only trip on a genuine bomb. The backend enforces the
# same caps plus an n_teams x n_venues product pre-check BEFORE dispatch; this schema is
# the defense-in-depth last line. Availability slots are bounded BOTH per-venue and in
# total (a model_validator sums across venues, so 50 venues x 1000 can't smuggle 50k slots).
MAX_VENUES = 50
MAX_TEAMS = 200
MAX_COACHES = 200
MAX_CONSTRAINTS = 500
MAX_SLOT_TEMPLATES = 2000
MAX_PRIORITY_TIERS = 20
MAX_SLOTS_PER_VENUE = 1000
MAX_SLOTS_TOTAL = 3000
MAX_TAGS_PER_TEAM = 50


class SerializableModel(BaseModel):
    model_config = ConfigDict(extra="forbid", populate_by_name=True)


class VenueTrainingSlotSchema(SerializableModel):
    day_of_week: int = Field(alias="dayOfWeek")
    start_time: str = Field(alias="startTime")  # "19:00"
    duration_minutes: int = Field(alias="durationMinutes")
    capacity: int = Field(default=1, ge=1)


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
    training_slots: list[VenueTrainingSlotSchema] = Field(
        default_factory=list, alias="trainingSlots", max_length=MAX_SLOTS_PER_VENUE
    )


class PriorityTierSchema(SerializableModel):
    id: int
    label: str
    or_tools_weight: int = Field(alias="orToolsWeight")
    default_min_sessions: int = Field(alias="defaultMinSessions")


class TeamSchema(SerializableModel):
    id: str
    sport_category_id: str = Field(alias="sportCategoryId")
    """Age range from SportCategory. Used for age-ascending constraint. None = constraint skipped."""
    ageMin: int | None = None
    ageMax: int | None = None
    priority_tier_id: int = Field(alias="priorityTierId")
    name: str
    gender: str | None = None
    level: str | None = None
    sessions_per_week: int = Field(alias="sessionsPerWeek")
    min_sessions_override: int | None = Field(default=None, alias="minSessionsOverride")
    match_day: int | None = Field(default=None, alias="matchDay")
    allow_multiple_sessions_per_day: bool = Field(
        default=False,
        alias="allowMultipleSessionsPerDay",
    )
    forced_venue_id: str | None = Field(default=None, alias="forcedVenueId")
    is_active: bool = Field(default=False, alias="isActive")
    parent_team_id: str | None = Field(default=None, alias="parentTeamId")
    ffbb_team_id: str | None = Field(default=None, alias="ffbbTeamId")
    tags: list[str] = Field(default_factory=list, max_length=MAX_TAGS_PER_TEAM)


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
    is_employee: bool = Field(default=False, alias="isEmployee")


class ConstraintV2Schema(BaseModel):
    """Unified v2 constraint schema that accepts both v2 and legacy v1 formats.

    V2 constraints use scope/family/ruleType/config.
    Legacy v1 constraints (TEAM_COACH, COACH_PLAYER_UNAVAILABILITY, PRIORITY_TIER)
    use teamId/type/severity/value/metadata.
    Both formats are accepted so the engine can handle mixed constraint arrays.
    """

    model_config = ConfigDict(extra="ignore", populate_by_name=True)

    id: str
    # V2 unified fields
    scope: str | None = None
    scope_target_id: str | None = Field(default=None, alias="scopeTargetId")
    family: str | None = None
    rule_type: str | None = Field(default=None, alias="ruleType")
    name: str | None = None
    config: dict[str, object] = Field(default_factory=dict)
    sort_order: int = Field(default=0, alias="sortOrder")
    is_active: bool = Field(default=True, alias="isActive")
    # Legacy v1 fields (still sent by backend for TEAM_COACH, etc.)
    team_id: str | None = Field(default=None, alias="teamId")
    type: str | None = None
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
    version: str = "2.0"
    club_id: str = Field(alias="clubId")
    season_id: str = Field(alias="seasonId")
    schedule_name: str | None = Field(default=None, alias="scheduleName")
    solver_seed: int = Field(default=42, alias="solverSeed")
    solver_timeout_seconds: int = Field(default=650, alias="solverTimeoutSeconds")
    venues: list[VenueSchema] = Field(default_factory=list, max_length=MAX_VENUES)
    teams: list[TeamSchema] = Field(default_factory=list, max_length=MAX_TEAMS)
    coaches: list[CoachSchema] = Field(default_factory=list, max_length=MAX_COACHES)
    constraints: list[ConstraintV2Schema] = Field(default_factory=list, max_length=MAX_CONSTRAINTS)
    slot_templates: list[ScheduleSlotTemplateSchema] = Field(
        default_factory=list, alias="slotTemplates", max_length=MAX_SLOT_TEMPLATES
    )
    priority_tiers: list[PriorityTierSchema] = Field(
        default_factory=list, alias="priorityTiers", max_length=MAX_PRIORITY_TIERS
    )

    @model_validator(mode="after")
    def _bound_total_slots(self) -> ScheduleInputSchema:
        # Per-venue max_length alone would let 50 venues x 1000 smuggle 50k slots to CP-SAT.
        total_slots = sum(len(v.training_slots) for v in self.venues)
        if total_slots > MAX_SLOTS_TOTAL:
            raise ValueError(f"too many availability slots: {total_slots} (max {MAX_SLOTS_TOTAL})")
        return self
