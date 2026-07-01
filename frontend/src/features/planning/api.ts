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

export const listSchedules = (): Promise<Schedule[]> => collection<Schedule>("schedules");
export const getSlots = (scheduleId: string): Promise<Slot[]> => collection<Slot>("schedule_slot_templates", { scheduleId });
export const getDiagnostics = (scheduleId: string): Promise<Diagnostic[]> => collection<Diagnostic>("schedule_diagnostics", { scheduleId });
export const getTeams = (): Promise<Team[]> => collection<Team>("teams");
export const getVenues = (): Promise<Venue[]> => collection<Venue>("venues");
export const getCoaches = (): Promise<Coach[]> => collection<Coach>("coaches");
export const getCategories = (): Promise<Category[]> => collection<Category>("sport_categories");
