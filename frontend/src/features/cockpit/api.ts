import { api } from "@/shared/api/client";
import { collectionAll } from "@/shared/api/collection";

export type CalendarEntryKind = "event" | "period";
export type CalendarEntryPeriodType = "closure" | "holiday" | "cutoff" | "mutualisation" | "custom";
export type CalendarEntryStatus = "proposed" | "active" | "ignored";

export interface CalendarEntry {
  id: string;
  kind: CalendarEntryKind;
  title: string;
  startDate: string;
  endDate: string;
  isDisruptive: boolean;
  periodType: CalendarEntryPeriodType | null;
  schoolHolidayId: string | null;
  /** Semaine ENFANT d'une période mère (P2-5 E1) ; null = entrée racine. */
  parentEntryId: string | null;
  status: CalendarEntryStatus;
  createdBy: string | null;
}

export type SchedulePlanType = "SEASON" | "CLOSURE" | "HOLIDAY";

/**
 * ADR-0002: le plan = LA RÉPONSE à un événement du calendrier. Un plan CLOSURE/HOLIDAY
 * naît avec le geste « ajuster cette période » (= la création du CalendarEntry), donc
 * il existe dès qu'une période existe — pas besoin d'attendre une génération.
 */
export interface SchedulePlan {
  id: string;
  type: SchedulePlanType;
  name: string;
  calendarEntryId: string | null;
  chosenScheduleId: string | null;
  /** Period-editable structure: has this plan's team selection been configured once (seed guard)? */
  teamSelectionInitialized: boolean;
}

/** Le plan d'une période. null si l'entrée n'en porte pas (cutoff/mutualisation — inv. 9). */
export const getSchedulePlanForEntry = async (calendarEntryId: string): Promise<SchedulePlan | null> => {
  const items = await collectionAll<SchedulePlan>("schedule_plans", { calendarEntryId });

  return items[0] ?? null;
};

/**
 * Tous les plans de la saison (tenant + saison résolus côté serveur). Le radar en
 * dérive, PAR PÉRIODE, sa « version active » = chosenScheduleId du plan (ADR-0002
 * lot D-b) — binaire : validé → on montre, non validé → on ajuste. Un seul appel
 * plutôt qu'un hook par entrée (règles des hooks dans la liste du radar).
 */
export const getAllSchedulePlans = async (): Promise<SchedulePlan[]> => collectionAll<SchedulePlan>("schedule_plans");

export interface SchoolHoliday {
  id: string;
  label: string;
  holidayType: string;
  startDate: string;
  endDate: string;
  schoolYear: string;
}

export interface SchoolHolidaysResponse {
  zone: string | null;
  items: SchoolHoliday[];
}

/** Single-day entry (unlike school holidays there is no start/end range). */
export interface PublicHoliday {
  id: string;
  date: string;
  label: string;
  national: boolean;
}

export interface PublicHolidaysResponse {
  zone: string | null;
  items: PublicHoliday[];
}

export interface EntryConflict {
  slotTemplateId: string;
  teamId: string;
  venueId: string;
  dayOfWeek: number;
  startTime: string;
  endTime: string;
  dates: string[];
}

export interface EntryConflictsResponse {
  entryId: string;
  venueIds: string[];
  conflicts: EntryConflict[];
  /**
   * Le plan de la saison pointe-t-il une version ? Sinon la saison n'a PAS de
   * calendrier et le radar n'a rien pu comparer : `conflicts: []` veut alors dire
   * « je ne sais pas », pas « aucun impact ». Sans ce drapeau les deux se lisent
   * pareil, et le gestionnaire conclut que sa fermeture de gymnase ne gêne personne.
   */
  seasonPlanChosen: boolean;
}

export interface CreateEventPayload {
  title: string;
  startDate: string;
  endDate: string;
  isDisruptive: boolean;
}

export interface CreateClosurePayload {
  title: string;
  startDate: string;
  endDate: string;
  venueId: string;
}

export interface CreateCutoffPayload {
  title: string;
  startDate: string;
  endDate: string;
}

/** Calendar entries overlapping the [from, to] window. Paginated endpoint → page through it. */
export const getCalendarEntries = (from: string, to: string): Promise<CalendarEntry[]> =>
  collectionAll<CalendarEntry>("calendar_entries", { from, to });

export const getCalendarEntry = (id: string): Promise<CalendarEntry> => api.get(`calendar_entries/${id}`).json();

export const createCalendarEntry = (json: Record<string, unknown>): Promise<CalendarEntry> =>
  api.post("calendar_entries", { json }).json();

export const deleteCalendarEntry = (id: string): Promise<unknown> => api.delete(`calendar_entries/${id}`).json();

export const getSchoolHolidays = (from?: string, to?: string): Promise<SchoolHolidaysResponse> =>
  api.get("school-holidays", from && to ? { searchParams: { from, to } } : undefined).json();

/** Explicit window: without from/to the backend needs an active season and 400s otherwise. */
export const getPublicHolidays = (from: string, to: string): Promise<PublicHolidaysResponse> =>
  api.get("public-holidays", { searchParams: { from, to } }).json();

export const getEntryConflicts = (entryId: string): Promise<EntryConflictsResponse> =>
  api.get(`calendar-entries/${entryId}/conflicts`).json();
