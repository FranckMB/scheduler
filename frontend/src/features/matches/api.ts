import { api } from "@/shared/api/client";
import { collection, collectionAll } from "@/shared/api/collection";

/**
 * Matches read/write API (module matchs, palier A PR-3). Tenant (club) + active
 * season are resolved server-side from the JWT — no header is sent. Consumes only
 * the endpoints delivered by PR-1/PR-2; this PR adds no backend.
 */

export type HomeAway = "HOME" | "AWAY";
export type FixtureStatus = "UNPLACED" | "PLACED" | "SUBMITTED" | "VALIDATED";

export interface Fixture {
  id: string;
  teamId: string;
  seasonId: string;
  competitionId: string | null;
  /** Y-m-d */
  matchDate: string;
  homeAway: HomeAway;
  opponentLabel: string;
  status: FixtureStatus;
  venueId: string | null;
  /** HH:MM, null until placed/estimated */
  kickoffTime: string | null;
  /** FBI match number (import idempotence key) — null for manual entries. */
  externalRef: string | null;
  /** Raw FBI « Salle » label, HOME and AWAY — never a Venue reference. */
  fbiVenueLabel: string | null;
  /** MANUAL | SOLVER | null — who placed it (re-solve anchor marker, PR D). */
  placementSource: "MANUAL" | "SOLVER" | null;
}

export interface Competition {
  id: string;
  teamId: string;
  name: string;
  competitionType: string;
  /** FBI club-team label — set when two club teams share one division. */
  fbiTeamLabel?: string | null;
}

export interface LeagueWindow {
  id: string;
  league: string;
  category: string;
  level: string;
  gender: string | null;
  /** ISO 1..7 */
  dayOfWeek: number;
  /** HH:MM */
  kickoffMin: string;
  kickoffMax: string;
}

export interface LeagueWindowsResponse {
  league: string;
  items: LeagueWindow[];
}

/** One side of a conflict — the fixture and its computed occupancy window. */
export interface ConflictFixtureView {
  fixtureId: string;
  teamId: string;
  homeAway: HomeAway;
  matchDate: string;
  kickoffTime: string | null;
  /** P1-4 PR C — the window borrows the team's HABITUAL kickoff (away match
   * without a real hour): say « heure estimée ». */
  estimatedKickoff?: boolean;
  windowStart: string;
  windowEnd: string;
}

export interface ConflictTrainingView {
  slotTemplateId: string;
  scheduleId: string;
  teamId: string;
  venueId: string;
  dayOfWeek: number;
  startTime: string;
  durationMinutes: number;
  windowStart: string;
  windowEnd: string;
}

/** VENUE_UNAVAILABLE side — no occupancy window: the DATE match suffices. */
export interface ConflictUnavailableFixtureView {
  fixtureId: string;
  teamId: string;
  homeAway: HomeAway;
  matchDate: string;
  kickoffTime: string | null;
  /** Never set on this variant (no window, so nothing to estimate). */
  estimatedKickoff?: boolean;
  status: FixtureStatus;
}

export interface Conflict {
  type: "MATCH_MATCH" | "MATCH_TRAINING" | "VENUE_UNAVAILABLE" | "TEAM_LINK_OVERLAP";
  coachId?: string;
  /** TEAM_LINK_OVERLAP only. */
  teamLinkId?: string;
  /** Overlap segment (ISO datetimes) — coach conflicts only. */
  start?: string;
  end?: string;
  left?: ConflictFixtureView;
  right?: ConflictFixtureView;
  fixture?: ConflictFixtureView | ConflictUnavailableFixtureView;
  training?: ConflictTrainingView;
  /** VENUE_UNAVAILABLE only. */
  venueId?: string;
  unavailabilityId?: string;
  label?: string | null;
  unavailableFrom?: string;
  unavailableUntil?: string;
}

export interface ConflictsResponse {
  clubId: string;
  seasonId: string | null;
  conflicts: Conflict[];
  /**
   * Le plan de la saison pointe-t-il une version ? Sinon la saison n'a pas de
   * calendrier : les conflits match↔entraînement ne peuvent pas être détectés hors
   * période, et `conflicts: []` devient indiscernable d'une saison saine. Atteignable
   * quand /api/me est périmé (staleTime 60 s) face à un planning rouvert ailleurs.
   */
  seasonPlanChosen: boolean;
}

/** Team reference row — carries the axes the league envelope maps on. */
export interface Team {
  id: string;
  name: string;
  sportCategoryId: string;
  level: string | null;
  gender: string | null;
  // Priority tier (S/A/B/C/D) — used to group teams in selectors, same
  // découpage as the wizard's teams step.
  priorityTierId: number;
  tierOrder: number;
}

export interface PriorityTier {
  id: number;
  label: string;
  name: string;
  color: string | null;
}

export interface Venue {
  id: string;
  name: string;
  color: string | null;
}

export interface Category {
  id: string;
  name: string;
}

export interface Coach {
  id: string;
  firstName: string;
  lastName: string;
}

export interface CreateFixtureInput {
  teamId: string;
  matchDate: string;
  homeAway: HomeAway;
  opponentLabel: string;
  competitionId?: string | null;
}

/** The placement of a home fixture: venue + kickoff, status → PLACED. */
export interface PlaceFixtureInput {
  venueId: string;
  kickoffTime: string;
}

/** The API omits null props from JSON → coerce the optionals back to null so
 * `null !==` guards and the grid/envelope logic never see `undefined`. */
function normalizeFixture(raw: Fixture): Fixture {
  return {
    ...raw,
    competitionId: raw.competitionId ?? null,
    venueId: raw.venueId ?? null,
    kickoffTime: raw.kickoffTime ?? null,
    externalRef: raw.externalRef ?? null,
    fbiVenueLabel: raw.fbiVenueLabel ?? null,
    placementSource: raw.placementSource ?? null,
  };
}

export const getFixtures = async (): Promise<Fixture[]> => (await collectionAll<Fixture>("fixtures")).map(normalizeFixture);
export const getCompetitions = (): Promise<Competition[]> => collectionAll<Competition>("competitions");
export const getTeams = (): Promise<Team[]> => collectionAll<Team>("teams");
// Tiers are a tiny fixed set (S/A/B/C/D) and their id is numeric, so use the
// unpaginated `collection` (collectionAll constrains T to a string id).
export const getPriorityTiers = (): Promise<PriorityTier[]> => collection<PriorityTier>("priority_tiers");
export const getVenues = (): Promise<Venue[]> => collectionAll<Venue>("venues");
export const getCategories = (): Promise<Category[]> => collectionAll<Category>("sport_categories");
export const getCoaches = (): Promise<Coach[]> => collectionAll<Coach>("coaches");

/** The league match-kickoff windows inherited by the club (envelope, AURA default). */
export const getLeagueWindows = (): Promise<LeagueWindowsResponse> =>
  api.get("league-match-windows").json<LeagueWindowsResponse>();

/** Same-coach conflict radar, recomputed server-side on every call. */
export const getConflicts = (): Promise<ConflictsResponse> => api.get("fixtures/conflicts").json<ConflictsResponse>();

// ── Capacity layer (P1-4 PR B) ───────────────────────────────────────────────

/** Match access window of a venue — ≥ 1 window makes it a MATCH venue. */
export interface VenueMatchWindow {
  id: string;
  venueId: string;
  /** ISO 1..7 */
  dayOfWeek: number;
  /** HH:MM */
  startTime: string;
  /** HH:MM, same-day, exclusive. */
  endTime: string;
}

/** All-circumstances venue closure — alerts, never blocks. */
export interface VenueUnavailability {
  id: string;
  venueId: string;
  /** Y-m-d, inclusive. */
  startDate: string;
  endDate: string;
  label: string | null;
}

export interface UnavailabilityImpactItem {
  unavailabilityId: string;
  venueId: string;
  startDate: string;
  endDate: string;
  label: string | null;
  affectedFixtures: { fixtureId: string; teamId: string; matchDate: string; kickoffTime: string | null; status: FixtureStatus }[];
  /** Dated training sessions inside the range. */
  trainingOccurrences: number;
  /** Distinct weekly slots affected. */
  trainingSlotCount: number;
}

export interface UnavailabilityImpactResponse {
  clubId: string;
  seasonId: string | null;
  items: UnavailabilityImpactItem[];
}

export const getVenueMatchWindows = (): Promise<VenueMatchWindow[]> => collectionAll<VenueMatchWindow>("venue_match_windows");

export const createVenueMatchWindow = (input: { venueId: string; dayOfWeek: number; startTime: string; endTime: string }): Promise<VenueMatchWindow> =>
  api.post("venue_match_windows", { json: input }).json<VenueMatchWindow>();

export const deleteVenueMatchWindow = (id: string): Promise<void> => api.delete(`venue_match_windows/${id}`).then(() => undefined);

export const getVenueUnavailabilities = (): Promise<VenueUnavailability[]> =>
  (async () => (await collectionAll<VenueUnavailability>("venue_unavailabilities")).map((u) => ({ ...u, label: u.label ?? null })))();

export const createVenueUnavailability = (input: { venueId: string; startDate: string; endDate: string; label?: string }): Promise<VenueUnavailability> =>
  api.post("venue_unavailabilities", { json: input }).json<VenueUnavailability>();

export const deleteVenueUnavailability = (id: string): Promise<void> => api.delete(`venue_unavailabilities/${id}`).then(() => undefined);

/** Alert-only impact feed (cockpit card): what each unavailability affects. */
export const getUnavailabilityImpact = (): Promise<UnavailabilityImpactResponse> =>
  api.get("venue-unavailability-impact").json<UnavailabilityImpactResponse>();

// ── Preferences layer (P1-4 PR C) ────────────────────────────────────────────

/** A team's habitual match window — one per weekday, venue optional. */
export interface TeamMatchHabit {
  id: string;
  teamId: string;
  /** ISO 1..7 */
  dayOfWeek: number;
  /** HH:MM — an instant, not a range. */
  kickoffTime: string;
  venueId: string | null;
}

export type TeamLinkType = "NOT_SIMULTANEOUS" | "BACK_TO_BACK";

/** A declared team bridge — symmetric (teamAId < teamBId), one per couple. */
export interface TeamLink {
  id: string;
  teamAId: string;
  teamBId: string;
  linkType: TeamLinkType;
}

export const getTeamMatchHabits = (): Promise<TeamMatchHabit[]> =>
  (async () => (await collectionAll<TeamMatchHabit>("team_match_habits")).map((h) => ({ ...h, venueId: h.venueId ?? null })))();

export const createTeamMatchHabit = (input: { teamId: string; dayOfWeek: number; kickoffTime: string; venueId?: string }): Promise<TeamMatchHabit> =>
  api.post("team_match_habits", { json: input }).json<TeamMatchHabit>();

export const deleteTeamMatchHabit = (id: string): Promise<void> => api.delete(`team_match_habits/${id}`).then(() => undefined);

export const getTeamLinks = (): Promise<TeamLink[]> => collectionAll<TeamLink>("team_links");

export const createTeamLink = (input: { teamAId: string; teamBId: string; linkType: TeamLinkType }): Promise<TeamLink> =>
  api.post("team_links", { json: input }).json<TeamLink>();

export const deleteTeamLink = (id: string): Promise<void> => api.delete(`team_links/${id}`).then(() => undefined);

// ── Auto-placement (P1-4 PR D) ───────────────────────────────────────────────

export type UnplacedReason = "no_access_window" | "no_league_intersection" | "venue_unavailable" | "venue_full";

export interface PlaceMatchesResult {
  placed: number;
  /** Placements refused at write time — a manual gesture won during the solve. */
  skipped: number;
  unplaced: { matchId: string; reason: UnplacedReason; message: string }[];
  diagnostics: { type: string; severity: string; message: string }[];
}

/** Synchronous solve: the engine places every placeable home match (seconds).
 * A non-placeable match is NOT an error — it comes back named in `unplaced`. */
export const placeMatches = (): Promise<PlaceMatchesResult> => api.post("fixtures/place").json<PlaceMatchesResult>();

/** One division group of the analyzed file, resolved (or not) against the
 * persisted Division↔team mapping. `fbiTeamLabel` is only set when TWO club
 * teams share the division (the FBI suffix disambiguates them). */
export interface ImportAnalysisDivision {
  name: string;
  fbiTeamLabel: string | null;
  rowCount: number;
  teamId: string | null;
  competitionId: string | null;
}

export interface ImportFbiAnalysis {
  divisions: ImportAnalysisDivision[];
  totalRows: number;
  exempted: number;
  errors: string[];
}

export interface ImportFbiWarning {
  type: "RESCHEDULED" | "SWITCHED";
  division: string;
  externalRef: string;
  message: string;
}

export interface ImportFbiResult {
  message: string;
  created: number;
  updated: number;
  unchanged: number;
  exempted: number;
  errors: string[];
  warnings: ImportFbiWarning[];
  unmappedDivisions: { name: string; fbiTeamLabel: string | null; rowCount: number }[];
}

/** A manager's mapping choice for one division group of the analyze step. */
export interface FbiMapping {
  division: string;
  fbiTeamLabel: string | null;
  teamId: string;
}

/** Dry-run: parse the club-wide FBI export and return its mapping table. */
export const analyzeFbiFixtures = (file: File): Promise<ImportFbiAnalysis> => {
  const form = new FormData();
  form.append("file", file);
  return api.post("fixtures/import/analyze", { body: form }).json<ImportFbiAnalysis>();
};

/** One-pass import: the SAME file + the completed mappings (multipart). */
export const importFbiFixtures = (file: File, mappings: FbiMapping[]): Promise<ImportFbiResult> => {
  const form = new FormData();
  form.append("file", file);
  if (mappings.length > 0) {
    form.append("mappings", JSON.stringify(mappings));
  }
  return api.post("fixtures/import", { body: form }).json<ImportFbiResult>();
};

export const createFixture = (input: CreateFixtureInput): Promise<Fixture> =>
  api.post("fixtures", { json: { competitionId: null, ...input } }).json<Fixture>();

/**
 * Place a home fixture. PUT is a full replace in this API, so the whole fixture
 * body is resent — only venue/kickoff/status change; identity + opponent +
 * competition are echoed so they are not wiped.
 */
export const placeFixture = (fixture: Fixture, input: PlaceFixtureInput): Promise<Fixture> =>
  api
    .put(`fixtures/${fixture.id}`, {
      json: {
        teamId: fixture.teamId,
        matchDate: fixture.matchDate,
        homeAway: fixture.homeAway,
        opponentLabel: fixture.opponentLabel,
        competitionId: fixture.competitionId,
        venueId: input.venueId,
        kickoffTime: input.kickoffTime,
        status: "PLACED",
      },
    })
    .json<Fixture>();
