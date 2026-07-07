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
}

export interface Competition {
  id: string;
  teamId: string;
  name: string;
  competitionType: string;
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

export interface Conflict {
  type: "MATCH_MATCH" | "MATCH_TRAINING";
  coachId: string;
  /** Overlap segment (ISO datetimes). */
  start: string;
  end: string;
  left?: ConflictFixtureView;
  right?: ConflictFixtureView;
  fixture?: ConflictFixtureView;
  training?: ConflictTrainingView;
}

export interface ConflictsResponse {
  clubId: string;
  seasonId: string | null;
  conflicts: Conflict[];
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

export interface ImportFbiResult {
  message: string;
  created: number;
  skipped: number;
  errors: string[];
}

/** Upload one FBI export (.xlsx) for ONE team (multipart) → per-row report. */
export const importFbiFixtures = (teamId: string, file: File): Promise<ImportFbiResult> => {
  const form = new FormData();
  form.append("file", file);
  return api.post(`teams/${teamId}/fixtures/import`, { body: form }).json<ImportFbiResult>();
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
