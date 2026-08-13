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
    # D-41 — c'etait un COMMENTAIRE, donc rien : il annoncait 7 types alors que le solveur en
    # emettait deja 8 (`constraint_not_honored`, P2-9). Un commentaire ne valide pas, et un
    # code renomme ici tombe cote backend dans le `default` de `DiagnosticMessageBuilder`
    # (« Diagnostic inconnu. » affiche au gestionnaire) tout en eteignant le surlignage du
    # front — sans erreur nulle part. La liste est donc une CONTRAINTE : Pydantic refuse
    # desormais un type hors enumeration, a la construction, cote emetteur.
    type: Literal[
        "coach_no_rest_day",
        "coach_overload",
        "conflict",
        "constraint_not_honored",
        "day_constraint_conflict",
        "session_below_effective_min",
        "soft_lock_moved",
        "unplaced",
        "unplaced_match",
        "unused_slot",
        "venue_minimum_unreachable",
    ]
    severity: str
    team_id: str | None = Field(default=None, alias="teamId")
    coach_id: str | None = Field(default=None, alias="coachId")
    venue_id: str | None = Field(default=None, alias="venueId")
    day_of_week: int | None = Field(default=None, alias="dayOfWeek")
    start_time: str | None = Field(default=None, alias="startTime")
    duration_minutes: int | None = Field(default=None, alias="durationMinutes")
    message: str
    suggestions: list[str] = Field(default_factory=list)
    created_at: datetime | None = Field(default=None, alias="createdAt")


class SolverMetricsSchema(SerializableModel):
    solver_version: str
    nb_variables: int
    nb_constraints: int
    wall_time_ms: int
    # Determinism identifiers (v3 §4.5): with solver_seed they pin down a run.
    # score_formula_version = which fixed T24 scoring formula produced the score;
    # constraint_version = the backend↔engine contract version the model was built
    # against. Optional so older payloads still validate.
    score_formula_version: str | None = Field(default=None, alias="scoreFormulaVersion")
    constraint_version: str | None = Field(default=None, alias="constraintVersion")
    # P5-10 — capacity metrics measured AROUND the solve (main.py), merged into the
    # metrics dict before schema validation. ALL optional so the SHARED schema stays
    # valid for /place-matches and /validate-assignments, which don't measure them.
    # total_wall_time_ms is the WHOLE solve (both phases): wall_time_ms above is only
    # the LAST phase's WallTime() — when phase-2 chaining (cap 10 s) wins, a 600 s
    # phase-1 solve otherwise reports ~10 s. nb_conflicts finally leaves the engine
    # (the backend column + the ContractSchemaTest mock already announced it, but
    # extra=forbid meant it was never actually sent).
    total_wall_time_ms: int | None = Field(default=None, alias="totalWallTimeMs")
    cpu_time_ms: int | None = Field(default=None, alias="cpuTimeMs")
    workers: int | None = None
    budget_seconds: int | None = Field(default=None, alias="budgetSeconds")
    solver_status_detail: str | None = Field(default=None, alias="solverStatusDetail")
    nb_conflicts: int | None = Field(default=None, alias="nbConflicts")
    peak_rss_mb: float | None = Field(default=None, alias="peakRssMb")
    rss_before_mb: float | None = Field(default=None, alias="rssBeforeMb")
    engine_wait_ms: int | None = Field(default=None, alias="engineWaitMs")


class ScheduleOutputSchema(SerializableModel):
    status: Literal["queued", "generating", "completed", "failed"]
    score: int | None = None
    metrics: SolverMetricsSchema
    unplaced: list[str] = Field(default_factory=list)
    slots: list[ScheduleSlotSchema] = Field(default_factory=list)
    diagnostics: list[DiagnosticSchema] = Field(default_factory=list)
