from __future__ import annotations

from datetime import date, time

from pydantic import BaseModel, ConfigDict, Field, model_validator

# P1-4 PR D — match-placement solve (ADR-0003). SEPARATE schemas from the
# weekly training solve: matches carry REAL dates where /generate reasons in
# dayOfWeek templates. One CONTRACT_VERSION covers both endpoints.
#
# Input bombs are bounded at the boundary (same A10 philosophy as
# input_schema.py): the problem is tiny by design, the caps are generous.
MAX_MATCHES = 2000
MAX_MATCH_VENUES = 50
MAX_MATCH_TEAMS = 200
MAX_TEAM_LINKS = 400
MAX_TRAINING_OCCUPANCIES = 20000
MAX_WINDOWS_PER_VENUE = 50
MAX_UNAVAILABILITIES_PER_VENUE = 100
MAX_TIMEOUT_SECONDS = 60


class SerializableModel(BaseModel):
    model_config = ConfigDict(extra="forbid", populate_by_name=True)


class MatchAccessWindowSchema(SerializableModel):
    """A venue's MATCH access window (city-hall grant): ISO day + same-day range."""

    day_of_week: int = Field(alias="dayOfWeek", ge=1, le=7)
    start: time
    end: time


class MatchVenueUnavailabilitySchema(SerializableModel):
    """All-circumstances closure — inclusive date bounds."""

    start_date: date = Field(alias="startDate")
    end_date: date = Field(alias="endDate")


class MatchVenueSchema(SerializableModel):
    id: str
    name: str = ""
    match_windows: list[MatchAccessWindowSchema] = Field(
        default_factory=list, alias="matchWindows", max_length=MAX_WINDOWS_PER_VENUE
    )
    unavailabilities: list[MatchVenueUnavailabilitySchema] = Field(
        default_factory=list, max_length=MAX_UNAVAILABILITIES_PER_VENUE
    )


class LeagueKickoffWindowSchema(SerializableModel):
    """League-imposed kickoff window for a team (resolved by the backend): the
    KICKOFF must fall inside; the day is part of the rule."""

    day_of_week: int = Field(alias="dayOfWeek", ge=1, le=7)
    kickoff_min: time = Field(alias="kickoffMin")
    kickoff_max: time = Field(alias="kickoffMax")


class TeamHabitSchema(SerializableModel):
    """The team's habitual window — a kickoff INSTANT, venue optional."""

    day_of_week: int = Field(alias="dayOfWeek", ge=1, le=7)
    kickoff: time
    venue_id: str | None = Field(default=None, alias="venueId")


class TeamCoachRefSchema(SerializableModel):
    coach_id: str = Field(alias="coachId")
    role: str = "MAIN"  # MAIN | ASSISTANT


class MatchTeamSchema(SerializableModel):
    id: str
    name: str = ""
    # [] = the team does not map to a league envelope → NO league HARD (the
    # backend already emitted an INFO diagnostic saying so).
    league_windows: list[LeagueKickoffWindowSchema] = Field(default_factory=list, alias="leagueWindows")
    habits: list[TeamHabitSchema] = Field(default_factory=list, max_length=7)
    coaches: list[TeamCoachRefSchema] = Field(default_factory=list, max_length=20)


class MatchSchema(SerializableModel):
    """One dated match.

    kind:
    - TO_PLACE — HOME, UNPLACED (or SOLVER-placed being re-solved): the solver
      picks (venue, kickoff). `currentVenueId`/`currentKickoff` carry the
      previous SOLVER placement for the stability bonus + hint.
    - FIXED — HOME already anchored (manual placement / submitted / validated):
      consumes its venue slot, NEVER moves.
    - AWAY — informative only: occupies the team's people (coach terms), not a
      venue. `kickoff` may be the real hour or the habit estimation
      (kickoffEstimated) — null = no footprint at all.
    """

    id: str
    team_id: str = Field(alias="teamId")
    match_date: date = Field(alias="date")
    kind: str = "TO_PLACE"  # TO_PLACE | FIXED | AWAY
    venue_id: str | None = Field(default=None, alias="venueId")
    kickoff: time | None = None
    kickoff_estimated: bool = Field(default=False, alias="kickoffEstimated")
    current_venue_id: str | None = Field(default=None, alias="currentVenueId")
    current_kickoff: time | None = Field(default=None, alias="currentKickoff")

    @model_validator(mode="after")
    def _fixed_is_anchored(self) -> MatchSchema:
        if self.kind == "FIXED" and (self.venue_id is None or self.kickoff is None):
            msg = "a FIXED match must carry venueId and kickoff"
            raise ValueError(msg)
        if self.kind not in {"TO_PLACE", "FIXED", "AWAY"}:
            msg = f"unknown match kind {self.kind!r}"
            raise ValueError(msg)
        return self


class TeamLinkSchema(SerializableModel):
    team_a_id: str = Field(alias="teamAId")
    team_b_id: str = Field(alias="teamBId")
    type: str = "NOT_SIMULTANEOUS"  # NOT_SIMULTANEOUS | BACK_TO_BACK


class TrainingOccupancySchema(SerializableModel):
    """A dated training session projected by the backend from the EFFECTIVE
    schedule (ADR-0002 rules live backend-side — the engine stays flat)."""

    occupancy_date: date = Field(alias="date")
    start: time
    end: time
    coach_id: str = Field(alias="coachId")


class MatchPlacementInputSchema(SerializableModel):
    version: str = "2.2"
    club_id: str = Field(alias="clubId")
    season_id: str = Field(alias="seasonId")
    solver_seed: int = Field(default=42, alias="solverSeed")
    # 30 s default; the payload value is a CEILING, capped at 60 s — this
    # problem is tiny (~10^4 booleans), a long budget only hides a modelling bug.
    solver_timeout_seconds: int = Field(default=30, alias="solverTimeoutSeconds", ge=1, le=MAX_TIMEOUT_SECONDS)
    matches: list[MatchSchema] = Field(default_factory=list, max_length=MAX_MATCHES)
    venues: list[MatchVenueSchema] = Field(default_factory=list, max_length=MAX_MATCH_VENUES)
    teams: list[MatchTeamSchema] = Field(default_factory=list, max_length=MAX_MATCH_TEAMS)
    team_links: list[TeamLinkSchema] = Field(default_factory=list, alias="teamLinks", max_length=MAX_TEAM_LINKS)
    training_occupancies: list[TrainingOccupancySchema] = Field(
        default_factory=list, alias="trainingOccupancies", max_length=MAX_TRAINING_OCCUPANCIES
    )
