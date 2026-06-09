from __future__ import annotations

from datetime import datetime, time
from typing import Literal

from pydantic import BaseModel, ConfigDict, Field


class SerializableModel(BaseModel):
    model_config = ConfigDict(extra="forbid", populate_by_name=True)


class ScheduleSlotSchema(SerializableModel):
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


class DiagnosticSchema(SerializableModel):
    id: str
    type: str
    severity: str
    team_id: str | None = Field(default=None, alias="teamId")
    coach_id: str | None = Field(default=None, alias="coachId")
    venue_id: str | None = Field(default=None, alias="venueId")
    message: str
    suggestions: list[str] = Field(default_factory=list)
    created_at: datetime | None = Field(default=None, alias="createdAt")


class ScheduleOutputSchema(SerializableModel):
    status: Literal["queued", "generating", "completed", "failed"]
    score: int | None = None
    slots: list[ScheduleSlotSchema] = Field(default_factory=list)
    diagnostics: list[DiagnosticSchema] = Field(default_factory=list)
