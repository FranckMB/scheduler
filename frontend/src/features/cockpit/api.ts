import { api } from "@/shared/api/client";
import { collection } from "@/shared/api/collection";

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
  status: CalendarEntryStatus;
  overlayScheduleId: string | null;
  createdBy: string | null;
}

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

/** Calendar entries overlapping the [from, to] window (the visible month). */
export const getCalendarEntries = (from: string, to: string): Promise<CalendarEntry[]> =>
  collection<CalendarEntry>("calendar_entries", { from, to });

export const createCalendarEntry = (json: Record<string, unknown>): Promise<CalendarEntry> =>
  api.post("calendar_entries", { json }).json();

export const deleteCalendarEntry = (id: string): Promise<unknown> => api.delete(`calendar_entries/${id}`).json();

/** Constraints attached to a calendar entry (a closure's FACILITY constraints). */
export const getEntryConstraints = (calendarEntryId: string): Promise<{ id: string }[]> =>
  collection<{ id: string }>("constraints", { calendarEntryId });

export const createConstraint = (json: Record<string, unknown>): Promise<{ id: string }> =>
  api.post("constraints", { json }).json();

export const deleteConstraint = (id: string): Promise<unknown> => api.delete(`constraints/${id}`).json();

export const getSchoolHolidays = (): Promise<SchoolHolidaysResponse> => api.get("school-holidays").json();

export const getEntryConflicts = (entryId: string): Promise<EntryConflictsResponse> =>
  api.get(`calendar-entries/${entryId}/conflicts`).json();
