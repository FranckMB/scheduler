import { HTTPError } from "ky";

import { api } from "@/shared/api/client";
import { collection, collectionAll } from "@/shared/api/collection";

export type Gender = "M" | "F" | "MIXTE";

/** FFBB competition level (backend App\Enum\TeamLevel). LOISIR_* = non-competitive. */
export type TeamLevel =
  | "ELITE"
  | "NATIONAL"
  | "REGIONAL"
  | "PRE_REGION"
  | "DEPARTEMENTAL"
  | "HONNEUR"
  | "PROMOTION"
  | "LOISIR_ADULTE"
  | "LOISIR_JEUNE";

export interface Team {
  id: string;
  name: string;
  sportCategoryId: string;
  priorityTierId: number;
  tierOrder: number;
  gender: Gender | null;
  level: TeamLevel | null;
  sessionsPerWeek: number;
  isActive: boolean;
  /**
   * L'équipe joue déjà en compétition — vrai dès qu'elle porte AU MOINS UN match, quel
   * qu'en soit le statut : la correspondance faite par l'import FBI suffit, la
   * fédération la connaît. Donc ni suppression ni changement de niveau. Son nom, son
   * rang, son `isActive` et ses créneaux restent libres.
   *
   * Vient du serveur — celui-là même qui refuse ces écritures. Le recalculer ici
   * ferait un second endroit qui répond « engagée ? », et il finirait par répondre
   * autre chose que le serveur : l'écran offrirait un geste toujours refusé.
   */
  isEngaged?: boolean;
}

export interface SportCategory {
  id: string;
  name: string;
  sortOrder: number;
}

export interface PriorityTier {
  id: number;
  label: string;
  name: string;
  color: string | null;
}

export interface TeamPayload {
  name: string;
  sportCategoryId?: string;
  priorityTierId?: number;
  tierOrder?: number;
  gender?: Gender | null;
  level?: TeamLevel | null;
  sessionsPerWeek?: number;
  isActive?: boolean;
}

export const listTeams = (): Promise<Team[]> => collectionAll<Team>("teams");
export const listSportCategories = (): Promise<SportCategory[]> => collection<SportCategory>("sport_categories");
export const listPriorityTiers = (): Promise<PriorityTier[]> => collection<PriorityTier>("priority_tiers");

export const createTeam = (body: TeamPayload): Promise<Team> => api.post("teams", { json: body }).json();
export const updateTeam = (id: string, body: TeamPayload): Promise<Team> => api.put(`teams/${id}`, { json: body }).json();
/** Bulk reorder in one transaction (atomic) — avoids N concurrent PUTs racing on the optimistic-lock version. */
export const reorderTeams = (items: { id: string; priorityTierId: number; tierOrder: number }[]): Promise<unknown> =>
  api.post("teams/reorder", { json: { items } }).json();
export const deleteTeam = (id: string): Promise<void> => api.delete(`teams/${id}`).then(() => undefined);

// --- Venues + availability slots (W2) ---

export interface Venue {
  id: string;
  name: string;
  color: string | null;
  canSplit: boolean;
  isActive: boolean;
}

export interface VenueTrainingSlot {
  id: string;
  venueId: string;
  dayOfWeek: number;
  startTime: string;
  durationMinutes: number;
  capacity: number;
  /** null = créneau saisonnier (structure PARTAGÉE, inv. 6) ; set = prêté à CE plan (additif). */
  schedulePlanId?: string | null;
}

export interface VenuePayload {
  name: string;
  color?: string | null;
  canSplit?: boolean;
  isActive?: boolean;
}

export interface SlotPayload {
  venueId: string;
  dayOfWeek: number;
  startTime: string;
  durationMinutes: number;
  capacity: number;
  /** Period-editable structure: set to scope the slot to a PLAN (gym lent for the window). */
  schedulePlanId?: string | null;
}

export const listVenues = (): Promise<Venue[]> => collectionAll<Venue>("venues");
export const listVenueSlots = (): Promise<VenueTrainingSlot[]> => collectionAll<VenueTrainingSlot>("venue_training_slots");
/** Period-editable structure: the slots scoped to ONE period (the borrowed gyms). */
export const listPeriodSlots = (schedulePlanId: string): Promise<VenueTrainingSlot[]> => collectionAll<VenueTrainingSlot>("venue_training_slots", { schedulePlanId });
export const createVenue = (body: VenuePayload): Promise<Venue> => api.post("venues", { json: { source: "manual", ...body } }).json();
export const updateVenue = (id: string, body: VenuePayload): Promise<Venue> => api.put(`venues/${id}`, { json: { source: "manual", ...body } }).json();
export const deleteVenue = (id: string): Promise<void> => api.delete(`venues/${id}`).then(() => undefined);
export const createSlot = (body: SlotPayload): Promise<VenueTrainingSlot> => api.post("venue_training_slots", { json: body }).json();
export const updateSlot = (id: string, body: SlotPayload): Promise<VenueTrainingSlot> => api.put(`venue_training_slots/${id}`, { json: body }).json();
export const deleteSlot = (id: string): Promise<void> => api.delete(`venue_training_slots/${id}`).then(() => undefined);

/**
 * Period-editable structure: a sparse per-(plan, team) override — off for the period, or a
 * different session count. Ancré au PLAN (ADR-0002 inv. 5), pas au déclencheur calendrier :
 * c'est ce qui permettra à 2 semaines de vacances (2 plans) de porter 2 jeux de réglages.
 */
export interface TeamPeriodOverride {
  id: string;
  schedulePlanId: string;
  teamId: string;
  isActive: boolean;
  sessionsPerWeek: number | null;
}

export interface TeamPeriodOverridePayload {
  schedulePlanId: string;
  teamId: string;
  isActive: boolean;
  sessionsPerWeek?: number | null;
}

export const listTeamPeriodOverrides = (schedulePlanId: string): Promise<TeamPeriodOverride[]> => collectionAll<TeamPeriodOverride>("team_period_overrides", { schedulePlanId });
export const createTeamPeriodOverride = (body: TeamPeriodOverridePayload): Promise<TeamPeriodOverride> => api.post("team_period_overrides", { json: body }).json();
export const updateTeamPeriodOverride = (id: string, body: TeamPeriodOverridePayload): Promise<TeamPeriodOverride> => api.put(`team_period_overrides/${id}`, { json: body }).json();
export const deleteTeamPeriodOverride = (id: string): Promise<void> => api.delete(`team_period_overrides/${id}`).then(() => undefined);

/** Period-editable structure: a sparse per-(period, constraint) toggle — isActive=false disables the permanent constraint for the period. No row = applies as usual. */
export interface ConstraintPeriodOverride {
  id: string;
  schedulePlanId: string;
  constraintId: string;
  isActive: boolean;
}

export interface ConstraintPeriodOverridePayload {
  schedulePlanId: string;
  constraintId: string;
  isActive: boolean;
}

export const listConstraintPeriodOverrides = (schedulePlanId: string): Promise<ConstraintPeriodOverride[]> => collectionAll<ConstraintPeriodOverride>("constraint_period_overrides", { schedulePlanId });
export const createConstraintPeriodOverride = (body: ConstraintPeriodOverridePayload): Promise<ConstraintPeriodOverride> => api.post("constraint_period_overrides", { json: body }).json();
export const updateConstraintPeriodOverride = (id: string, body: ConstraintPeriodOverridePayload): Promise<ConstraintPeriodOverride> => api.put(`constraint_period_overrides/${id}`, { json: body }).json();
export const deleteConstraintPeriodOverride = (id: string): Promise<void> => api.delete(`constraint_period_overrides/${id}`).then(() => undefined);

/** A persistent team→slot HARD pin (base plan or a period overlay). Server-backed. */
export interface Reservation {
  id: string;
  schedulePlanId: string | null;
  teamId: string;
  venueId: string;
  dayOfWeek: number;
  startTime: string;
  durationMinutes: number;
}

export interface ReservationPayload {
  teamId: string;
  venueId: string;
  dayOfWeek: number;
  startTime: string;
  durationMinutes: number;
  schedulePlanId?: string | null;
}

export const listReservations = (params?: Record<string, string>): Promise<Reservation[]> => collectionAll<Reservation>("reservations", params);
export const createReservation = (body: ReservationPayload): Promise<Reservation> => api.post("reservations", { json: body }).json();
export const deleteReservation = (id: string): Promise<void> => api.delete(`reservations/${id}`).then(() => undefined);

// --- Coaches + links (W3) ---

export type TeamCoachRole = "MAIN" | "ASSISTANT";

export interface Coach {
  id: string;
  firstName: string;
  lastName: string;
  email: string | null;
  isEmployee: boolean;
  isActive: boolean;
}

export interface TeamCoach {
  id: string;
  teamId: string;
  coachId: string;
  role: TeamCoachRole;
}

export interface CoachPlayerMembership {
  id: string;
  teamId: string;
  coachId: string;
  isActive: boolean;
}

export interface CoachPayload {
  firstName: string;
  lastName?: string | null;
  email?: string | null;
  isEmployee?: boolean;
  isActive?: boolean;
}

export const listCoaches = (): Promise<Coach[]> => collectionAll<Coach>("coaches");
export const createCoach = (body: CoachPayload): Promise<Coach> => api.post("coaches", { json: body }).json();
export const updateCoach = (id: string, body: CoachPayload): Promise<Coach> => api.put(`coaches/${id}`, { json: body }).json();
export const deleteCoach = (id: string): Promise<void> => api.delete(`coaches/${id}`).then(() => undefined);

export const listTeamCoaches = (): Promise<TeamCoach[]> => collectionAll<TeamCoach>("team_coaches");
export const createTeamCoach = (body: { teamId: string; coachId: string; role: TeamCoachRole }): Promise<TeamCoach> => api.post("team_coaches", { json: body }).json();
export const deleteTeamCoach = (id: string): Promise<void> => api.delete(`team_coaches/${id}`).then(() => undefined);

export const listCoachPlayers = (): Promise<CoachPlayerMembership[]> => collectionAll<CoachPlayerMembership>("coach_player_memberships");
export const createCoachPlayer = (body: { teamId: string; coachId: string; isActive: boolean }): Promise<CoachPlayerMembership> =>
  api.post("coach_player_memberships", { json: body }).json();
export const deleteCoachPlayer = (id: string): Promise<void> => api.delete(`coach_player_memberships/${id}`).then(() => undefined);

// --- Constraints (W4) ---

export type ConstraintFamily = "TIME" | "DAY" | "FACILITY" | "COACH_AVAILABILITY" | "FACILITY_CAPACITY";
export type ConstraintScope = "CLUB" | "TEAM" | "COACH" | "FACILITY";
export type ConstraintRuleType = "HARD" | "PREFERRED" | "BONUS" | "LOCK";

export interface Constraint {
  id: string;
  name: string;
  scope: ConstraintScope;
  scopeTargetId: string | null;
  family: ConstraintFamily;
  ruleType: ConstraintRuleType;
  config: Record<string, unknown>;
  isActive: boolean;
}

export interface ConstraintPayload {
  name: string;
  scope: ConstraintScope;
  scopeTargetId?: string | null;
  family: ConstraintFamily;
  ruleType: ConstraintRuleType;
  config: Record<string, unknown>;
  isActive?: boolean;
  /** Attach to a CalendarEntry (period) → dated constraint, excluded from base generation. */
  calendarEntryId?: string;
}

export type TeamTagAxis = "GENRE" | "NIVEAU" | "AGE";

export interface TeamTag {
  id: string;
  name: string;
  color: string | null;
  isSystem: boolean;
  /** Constraint-target grouping axis; null when the tag fits none of the three. */
  axis: TeamTagAxis | null;
}

export const listTeamTags = (): Promise<TeamTag[]> => collectionAll<TeamTag>("team_tags");

/** Explicit team→tag link (season-scoped). A tag with no assignment concerns no
 *  team of the club — it must not appear in the constraint group selector. */
export interface TeamTagAssignment {
  id: string;
  teamId: string;
  tagId: string;
  seasonId: string;
}

export const listTeamTagAssignments = (): Promise<TeamTagAssignment[]> => collectionAll<TeamTagAssignment>("team_tag_assignments");

/** Base plan constraints (permanent=1) or a period's dated constraints (calendarEntryId). */
export const listConstraints = (params?: Record<string, string>): Promise<Constraint[]> => collectionAll<Constraint>("constraints", params);
export const createConstraint = (body: ConstraintPayload): Promise<Constraint> => api.post("constraints", { json: { isActive: true, ...body } }).json();
export const updateConstraint = (id: string, body: ConstraintPayload): Promise<Constraint> => api.put(`constraints/${id}`, { json: body }).json();
export const deleteConstraint = (id: string): Promise<void> => api.delete(`constraints/${id}`).then(() => undefined);

// --- Recap + generate (W5) ---

export interface ValidateResult {
  valid: boolean;
  errors: Record<string, string[]>;
  conflicts: { constraint1Id: string; constraint2Id: string; reason: string }[];
}

/** Pre-solve gate (BW3). Returns the body whether valid (200) or not (422). Period mode scopes to a calendar entry. */
export async function validateConstraints(calendarEntryId?: string): Promise<ValidateResult> {
  try {
    return await api.post("constraints/validate", calendarEntryId ? { json: { calendarEntryId } } : undefined).json<ValidateResult>();
  } catch (error) {
    if (error instanceof HTTPError) {
      // ky 2.x parses the 422 body into error.data — re-reading the response
      // throws "body stream already read".
      const data = (error as { data?: unknown }).data;
      if (null !== data && typeof data === "object") {
        return data as ValidateResult;
      }
    }
    throw error;
  }
}

/** Create a base plan (name only) or a period overlay (calendarEntryId set). */
export const createSchedule = (name: string, calendarEntryId?: string): Promise<{ id: string }> =>
  api.post("schedules", { json: { name, status: "DRAFT", ...(calendarEntryId ? { calendarEntryId } : {}) } }).json();
export const generateSchedule = (id: string): Promise<unknown> => api.post(`schedules/${id}/generate`).json();

export type ScheduleStatus = "DRAFT" | "PENDING" | "GENERATING" | "COMPLETED" | "FAILED";
export const getSchedule = (id: string): Promise<{ id: string; status: ScheduleStatus }> => api.get(`schedules/${id}`).json();

export interface SlotTemplatePayload {
  scheduleId: string;
  teamId: string;
  venueId: string;
  dayOfWeek: number;
  startTime: string;
  durationMinutes: number;
  lockLevel: "HARD";
}

export const createSlotTemplate = (body: SlotTemplatePayload): Promise<{ id: string }> => api.post("schedule_slot_templates", { json: body }).json();
