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

const PAGE_SIZE = 30;

/**
 * Reference collections are paginated (30/page) and unfiltered — page through them
 * so every club row is resolved (team/venue/coach names). Dedupe by id and stop on
 * a short page or a page that adds nothing new (guards against a no-op `page` param).
 */
async function collectionAll<T extends { id: string }>(path: string): Promise<T[]> {
  const seen = new Set<string>();
  const all: T[] = [];
  for (let page = 1; page <= 50; page += 1) {
    const batch = await collection<T>(path, { page: String(page) });
    const fresh = batch.filter((item) => !seen.has(item.id));
    for (const item of fresh) {
      seen.add(item.id);
      all.push(item);
    }
    if (batch.length < PAGE_SIZE || 0 === fresh.length) {
      break;
    }
  }
  return all;
}

export type ScheduleStatus = "DRAFT" | "PENDING" | "GENERATING" | "COMPLETED" | "FAILED" | "VALIDATED";

/** Canonical FR labels for a schedule status (toolbar + cockpit banner). */
export const STATUS_LABELS: Record<ScheduleStatus, string> = {
  DRAFT: "Brouillon",
  PENDING: "En attente",
  GENERATING: "Génération…",
  COMPLETED: "Terminé",
  FAILED: "Échec",
  VALIDATED: "Validé",
};
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

export interface TeamCoach {
  id: string;
  teamId: string;
  coachId: string;
  role: "MAIN" | "ASSISTANT";
}

export interface CoachPlayerMembership {
  id: string;
  teamId: string;
  coachId: string;
  isActive: boolean;
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

/** Validate a COMPLETED schedule → VALIDATED (finished, read-only). */
export const validateSchedule = (id: string): Promise<unknown> => api.post(`schedules/${id}/validate`).json();

/** Reopen a VALIDATED schedule → COMPLETED (editable again). */
export const reopenSchedule = (id: string): Promise<unknown> => api.post(`schedules/${id}/reopen`).json();

/** Designate a finished schedule as the season's main plan (baseline). */
export const setBaseline = (id: string): Promise<unknown> => api.post(`schedules/${id}/set-baseline`).json();

/** Rename a schedule (status echoed as required by the input DTO; blocked server-side when VALIDATED). */
export const renameSchedule = (id: string, name: string, status: ScheduleStatus): Promise<unknown> =>
  api.put(`schedules/${id}`, { json: { name, status } }).json();

export const listSchedules = (): Promise<Schedule[]> => collectionAll<Schedule>("schedules");
export const getSlots = (scheduleId: string): Promise<Slot[]> => collection<Slot>("schedule_slot_templates", { scheduleId });
export const getDiagnostics = (scheduleId: string): Promise<Diagnostic[]> => collection<Diagnostic>("schedule_diagnostics", { scheduleId });
export const getTeams = (): Promise<Team[]> => collectionAll<Team>("teams");
export const getVenues = (): Promise<Venue[]> => collectionAll<Venue>("venues");
export const getCoaches = (): Promise<Coach[]> => collectionAll<Coach>("coaches");
export const getCategories = (): Promise<Category[]> => collectionAll<Category>("sport_categories");
export const getTeamCoaches = (): Promise<TeamCoach[]> => collectionAll<TeamCoach>("team_coaches");
export const getCoachPlayers = (): Promise<CoachPlayerMembership[]> => collectionAll<CoachPlayerMembership>("coach_player_memberships");
