import { api } from "@/shared/api/client";

/**
 * Planning read API. Tenant (club) + active season are resolved server-side from
 * the JWT (BL2) — no header is sent. Collections come back as API Platform
 * JSON-LD (`{ member: [...] }`); `collection()` also tolerates a plain array.
 */
async function collection<T>(path: string, searchParams?: Record<string, string>): Promise<T[]> {
  const raw = await api.get(path, searchParams ? { searchParams } : undefined).json<unknown>();
  if (Array.isArray(raw)) {
    return raw as T[];
  }
  if (null !== raw && typeof raw === "object" && Array.isArray((raw as { member?: unknown }).member)) {
    return (raw as { member: T[] }).member;
  }
  return [];
}

export type ScheduleStatus = "DRAFT" | "PENDING" | "GENERATING" | "COMPLETED" | "FAILED";
export type LockLevel = "NONE" | "SOFT" | "HARD";
export type DiagnosticSeverity = "ERROR" | "WARNING" | "INFO" | "SUCCESS";

export interface Schedule {
  id: string;
  name: string;
  status: ScheduleStatus;
  score: number | null;
  createdAt: string;
  updatedAt: string;
}

export interface Slot {
  id: string;
  scheduleId: string;
  teamId: string;
  venueId: string;
  coachId: string | null;
  dayOfWeek: number;
  startTime: string;
  durationMinutes: number;
  lockLevel: LockLevel;
  temporaryLock: boolean;
}

export interface Diagnostic {
  id: string;
  scheduleId: string;
  type: string;
  severity: DiagnosticSeverity;
  teamId: string | null;
  coachId: string | null;
  venueId: string | null;
  message: string;
  suggestions: unknown;
}

export interface Team {
  id: string;
  name: string;
  sportCategoryId: string;
}

export interface Venue {
  id: string;
  name: string;
  color: string | null;
}

export interface Coach {
  id: string;
  firstName: string;
  lastName: string;
}

export interface Category {
  id: string;
  name: string;
}

export interface SlotMovePatch {
  dayOfWeek?: number;
  startTime?: string;
  venueId?: string;
}

/** Lock (HARD) or unlock (NONE) a placed slot so the next solve keeps/frees it. */
export const lockSlot = (id: string, lockLevel: LockLevel): Promise<unknown> =>
  api.post(`schedule-slots/${id}/manual-edit/lock`, { json: { lockLevel } }).json();

/** Move a slot in place (day / time / venue) via the one-time edit endpoint. */
export const moveSlot = (id: string, patch: SlotMovePatch): Promise<unknown> =>
  api.post(`schedule-slots/${id}/manual-edit/one-time`, { json: patch }).json();

/** Queue a (re)generation of the schedule (202). Locked slots survive; the rest reshuffles. */
export const generateSchedule = (id: string): Promise<unknown> => api.post(`schedules/${id}/generate`).json();

export const listSchedules = (): Promise<Schedule[]> => collection<Schedule>("schedules");
export const getSlots = (scheduleId: string): Promise<Slot[]> => collection<Slot>("schedule_slot_templates", { scheduleId });
export const getDiagnostics = (scheduleId: string): Promise<Diagnostic[]> => collection<Diagnostic>("schedule_diagnostics", { scheduleId });
export const getTeams = (): Promise<Team[]> => collection<Team>("teams");
export const getVenues = (): Promise<Venue[]> => collection<Venue>("venues");
export const getCoaches = (): Promise<Coach[]> => collection<Coach>("coaches");
export const getCategories = (): Promise<Category[]> => collection<Category>("sport_categories");
