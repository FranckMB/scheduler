import { HTTPError } from "ky";

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

export type ScheduleStatus = "DRAFT" | "PENDING" | "GENERATING" | "COMPLETED" | "FAILED" | "VALIDATED" | "ARCHIVED";

/** Canonical FR labels for a schedule status (toolbar + cockpit banner). */
export const STATUS_LABELS: Record<ScheduleStatus, string> = {
  DRAFT: "Brouillon",
  PENDING: "En attente",
  GENERATING: "Génération…",
  COMPLETED: "Terminé",
  FAILED: "Échec",
  VALIDATED: "Validé",
  // planning-versions: sibling version set aside at validation — hidden from the selector.
  ARCHIVED: "Archivé",
};
// ENG-21: SOFT is not a supported lock (it had no solver effect); only NONE/HARD.
export type LockLevel = "NONE" | "HARD";
export type DiagnosticSeverity = "ERROR" | "WARNING" | "INFO" | "SUCCESS";

export interface Schedule {
  id: string;
  name: string;
  status: ScheduleStatus;
  score: number | null;
  createdAt: string;
  updatedAt: string;
  /** Non-null → this schedule is a period overlay (palier B), not a season plan. */
  calendarEntryId: string | null;
  /** PDF/PNG export lifecycle (async worker): null | pending | generating | completed | failed. */
  pdfExportStatus?: string | null;
  pdfExportUrl?: string | null;
  pngExportUrl?: string | null;
  /** Teams in the frozen solve input — divergence banner ("générée avec N équipes"). */
  generatedTeamCount?: number | null;
}

/** Export scope: all venues (null) or a single one. */
export type ExportVenueScope = string | null;

/** Queue a PDF+PNG export (async worker). Poll the schedule for pdfExportStatus/Url. */
export const exportSchedulePdf = (id: string, venueId: ExportVenueScope): Promise<unknown> =>
  api.post(`schedules/${id}/export-pdf`, { json: null === venueId ? {} : { venueId } }).json();

/** Download the schedule as an .xlsx (synchronous stream). Returns the blob. */
export const exportScheduleXlsx = async (id: string, venueId: ExportVenueScope): Promise<Blob> =>
  api.post(`schedules/${id}/export-xlsx`, { json: null === venueId ? {} : { venueId } }).blob();

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
  /** Priority tier (1=S…5=D) + manual order within it — drives the canonical
   *  team ordering (see shared/lib/teamTiers). Exposed by TeamResource. */
  priorityTierId: number;
  tierOrder: number;
}

export interface Venue {
  id: string;
  name: string;
  color: string | null;
}

/** A venue availability window (defined in the wizard). A window with no team
 *  placement in the schedule is an "empty slot" surfaced in the grid. */
export interface VenueTrainingSlot {
  id: string;
  venueId: string;
  dayOfWeek: number;
  startTime: string;
  durationMinutes: number;
  capacity: number;
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
/**
 * Validate a COMPLETED version → it becomes THE plan (baseline). Validating a
 * non-baseline version while period overlays exist → 409 overlays_exist unless
 * confirmDeleteOverlays is passed (same destructive idiom as reopen).
 */
export async function validateSchedule(id: string, opts?: { confirmDeleteOverlays?: boolean }): Promise<unknown> {
  try {
    return await api.post(`schedules/${id}/validate`, opts?.confirmDeleteOverlays ? { json: { confirmDeleteOverlays: true } } : undefined).json();
  } catch (error) {
    if (error instanceof HTTPError && 409 === error.response.status) {
      const body = ((error as { data?: unknown }).data ?? {}) as { code?: string; count?: number; overlays?: { entryId: string; title: string; overlayScheduleId: string }[] };
      if ("overlays_exist" === body.code) {
        throw new OverlaysExistError(body.count ?? 0, body.overlays ?? []);
      }
    }
    throw error;
  }
}

/** Thrown when reopening the baseline would delete period overlays (409). */
export class OverlaysExistError extends Error {
  readonly count: number;
  readonly overlays: { entryId: string; title: string; overlayScheduleId: string }[];

  constructor(count: number, overlays: { entryId: string; title: string; overlayScheduleId: string }[]) {
    super("overlays_exist");
    this.name = "OverlaysExistError";
    this.count = count;
    this.overlays = overlays;
  }
}

/**
 * Reopen a VALIDATED schedule → COMPLETED. Reopening the baseline with overlays
 * → 409 {code:"overlays_exist"} unless confirmDeleteOverlays is passed.
 */
export async function reopenSchedule(id: string, opts?: { confirmDeleteOverlays?: boolean }): Promise<unknown> {
  try {
    return await api.post(`schedules/${id}/reopen`, opts?.confirmDeleteOverlays ? { json: { confirmDeleteOverlays: true } } : undefined).json();
  } catch (error) {
    if (error instanceof HTTPError && 409 === error.response.status) {
      // ky 2.x parses the error body into error.data (re-reading the response
      // throws "body stream already read").
      const body = ((error as { data?: unknown }).data ?? {}) as { code?: string; count?: number; overlays?: { entryId: string; title: string; overlayScheduleId: string }[] };
      if ("overlays_exist" === body.code) {
        throw new OverlaysExistError(body.count ?? 0, body.overlays ?? []);
      }
    }
    throw error;
  }
}

/** Designate a finished schedule as the season's main plan (baseline). */
export const setBaseline = (id: string): Promise<unknown> => api.post(`schedules/${id}/set-baseline`).json();

/** Rename a schedule (status echoed as required by the input DTO; blocked server-side when VALIDATED). */
export const renameSchedule = (id: string, name: string, status: ScheduleStatus): Promise<unknown> =>
  api.put(`schedules/${id}`, { json: { name, status } }).json();

/** Delete a work version (server refuses baseline / VALIDATED / in-flight with 409). */
export const deleteSchedule = (id: string): Promise<void> => api.delete(`schedules/${id}`).then(() => undefined);

/**
 * planning-versions D3: restore this version's structure photo and queue a
 * fresh generation → a new linear version. Returns the new schedule id.
 */
export const regenerateFromVersion = (id: string): Promise<{ id: string }> => api.post(`schedules/${id}/regenerate-from`).json();
/** planning-versions: the plain "Régénérer" creates a NEW linear version (V2…)
 *  from the current structure, carrying the version's HARD-locked slots. */
export const regenerate = (id: string): Promise<{ id: string }> => api.post(`schedules/${id}/regenerate`).json();

/** planning-versions (overlay versions): create a new overlay version (DRAFT) for
 *  a period — the caller then generates it. A period may hold several versions. */
export const createOverlayVersion = (calendarEntryId: string): Promise<{ id: string }> =>
  api.post("schedules", { json: { calendarEntryId, name: "Version de période", status: "DRAFT" } }).json();

// API Platform 4 OMITS null fields from JSON, so a plan's null nullable fields
// arrive ABSENT (undefined), not null. calendarEntryId → every
// `null === calendarEntryId` overlay check silently fails (UX-02 journey
// regression); score → a null-score plan (DRAFT/in-flight) renders the literal
// "score undefined". Normalise BOTH at the boundary so the type is honest and
// every consumer sees a real null.
export const listSchedules = (): Promise<Schedule[]> =>
  collectionAll<Schedule>("schedules").then((rows) =>
    rows.map((s) => ({ ...s, calendarEntryId: s.calendarEntryId ?? null, score: s.score ?? null })),
  );
export const getSchedule = (id: string): Promise<Schedule> => api.get(`schedules/${id}`).json<Schedule>();
export const getSlots = (scheduleId: string): Promise<Slot[]> => collection<Slot>("schedule_slot_templates", { scheduleId });
export const getDiagnostics = (scheduleId: string): Promise<Diagnostic[]> => collection<Diagnostic>("schedule_diagnostics", { scheduleId });
export const getTeams = (): Promise<Team[]> => collectionAll<Team>("teams");
export const getVenues = (): Promise<Venue[]> => collectionAll<Venue>("venues");
export const getTrainingSlots = (): Promise<VenueTrainingSlot[]> => collectionAll<VenueTrainingSlot>("venue_training_slots");
export const getCoaches = (): Promise<Coach[]> => collectionAll<Coach>("coaches");
export const getCategories = (): Promise<Category[]> => collectionAll<Category>("sport_categories");
export const getTeamCoaches = (): Promise<TeamCoach[]> => collectionAll<TeamCoach>("team_coaches");
export const getCoachPlayers = (): Promise<CoachPlayerMembership[]> => collectionAll<CoachPlayerMembership>("coach_player_memberships");
