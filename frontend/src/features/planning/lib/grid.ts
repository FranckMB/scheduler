import type { Coach, Slot, Team, Venue } from "../api";
import type { ViewMode } from "../store";

/** Monday→Saturday. dayOfWeek is 1..7 (ISO); the training week shown is 1..6. */
export const DAYS: { n: number; label: string }[] = [
  { n: 1, label: "Lun" },
  { n: 2, label: "Mar" },
  { n: 3, label: "Mer" },
  { n: 4, label: "Jeu" },
  { n: 5, label: "Ven" },
  { n: 6, label: "Sam" },
];

export const NO_COACH = "__none__";

/** "18:00" or "18:00:00" → minutes since midnight. */
export function parseTimeToMinutes(time: string): number {
  const [h, m] = time.split(":");
  return Number(h) * 60 + Number(m ?? 0);
}

/** minutes → "HH:MM" (zero-padded). */
export function formatMinutes(total: number): string {
  const h = Math.floor(total / 60);
  const m = total % 60;
  return `${String(h).padStart(2, "0")}:${String(m).padStart(2, "0")}`;
}

export interface TimeBounds {
  startMin: number;
  endMin: number;
}

/** Grid vertical extent: floor(min start) → ceil(max end) to the hour; sane fallback when empty. */
export function computeTimeBounds(slots: Slot[], fallback: TimeBounds = { startMin: 17 * 60, endMin: 21 * 60 }): TimeBounds {
  if (0 === slots.length) {
    return fallback;
  }
  let min = Infinity;
  let max = -Infinity;
  for (const slot of slots) {
    const start = parseTimeToMinutes(slot.startTime);
    const end = start + slot.durationMinutes;
    min = Math.min(min, start);
    max = Math.max(max, end);
  }
  return { startMin: Math.floor(min / 60) * 60, endMin: Math.ceil(max / 60) * 60 };
}

/** The resource id a slot belongs to for the current view axis. */
export function resourceKeyForSlot(slot: Slot, viewMode: ViewMode): string {
  if ("gymnase" === viewMode) {
    return slot.venueId;
  }
  if ("coach" === viewMode) {
    return slot.coachId ?? NO_COACH;
  }
  return slot.teamId;
}

export interface Lookups {
  teams: Map<string, Team>;
  venues: Map<string, Venue>;
  coaches: Map<string, Coach>;
}

export interface GridResource {
  id: string;
  label: string;
}

export interface GridCell {
  slotId: string;
  day: number;
  resourceId: string;
  /** 1-based CSS grid column (1 = time gutter). */
  gridColumn: number;
  /** 1-based CSS grid row (1 = day header, 2 = resource header). */
  gridRowStart: number;
  gridRowSpan: number;
  teamLabel: string;
  venueLabel: string;
  venueColor: string | null;
  coachLabel: string;
  startLabel: string;
  endLabel: string;
  locked: boolean;
}

export interface GridModel {
  days: { n: number; label: string }[];
  resources: GridResource[];
  bounds: TimeBounds;
  stepMin: number;
  rowLabels: string[];
  cells: GridCell[];
}

function coachLabel(coaches: Map<string, Coach>, coachId: string | null): string {
  if (null === coachId) {
    return "Sans coach";
  }
  const coach = coaches.get(coachId);
  return coach ? `${coach.firstName} ${coach.lastName}` : "Coach ?";
}

function resourceLabel(id: string, viewMode: ViewMode, lookups: Lookups): string {
  if ("gymnase" === viewMode) {
    return lookups.venues.get(id)?.name ?? "Gymnase ?";
  }
  if ("coach" === viewMode) {
    return id === NO_COACH ? "Sans coach" : coachLabel(lookups.coaches, id);
  }
  return lookups.teams.get(id)?.name ?? "Équipe ?";
}

/**
 * Pure layout: maps slots to grid cells for the chosen view. A day is a
 * super-column split into one sub-column per resource; changing the view only
 * changes which resource forms the sub-columns (same slots, re-grouped).
 */
export function buildGrid(slots: Slot[], viewMode: ViewMode, lookups: Lookups, stepMin = 30): GridModel {
  const bounds = computeTimeBounds(slots);

  const resourceIds = [...new Set(slots.map((s) => resourceKeyForSlot(s, viewMode)))];
  const resources: GridResource[] = resourceIds
    .map((id) => ({ id, label: resourceLabel(id, viewMode, lookups) }))
    .sort((a, b) => a.label.localeCompare(b.label, "fr"));

  const resIndex = new Map(resources.map((r, i) => [r.id, i]));

  const cells: GridCell[] = [];
  for (const slot of slots) {
    if (slot.dayOfWeek < 1 || slot.dayOfWeek > 6) {
      continue;
    }
    const dayIndex = slot.dayOfWeek - 1;
    const rid = resourceKeyForSlot(slot, viewMode);
    const ri = resIndex.get(rid);
    if (undefined === ri) {
      continue;
    }
    const start = parseTimeToMinutes(slot.startTime);
    const venue = lookups.venues.get(slot.venueId);
    cells.push({
      slotId: slot.id,
      day: slot.dayOfWeek,
      resourceId: rid,
      gridColumn: 2 + dayIndex * resources.length + ri,
      gridRowStart: 3 + Math.round((start - bounds.startMin) / stepMin),
      gridRowSpan: Math.max(1, Math.round(slot.durationMinutes / stepMin)),
      teamLabel: lookups.teams.get(slot.teamId)?.name ?? "Équipe ?",
      venueLabel: venue?.name ?? "Gymnase ?",
      venueColor: venue?.color ?? null,
      coachLabel: coachLabel(lookups.coaches, slot.coachId),
      startLabel: formatMinutes(start),
      endLabel: formatMinutes(start + slot.durationMinutes),
      locked: "NONE" !== slot.lockLevel || slot.temporaryLock,
    });
  }

  const rowLabels: string[] = [];
  for (let t = bounds.startMin; t < bounds.endMin; t += stepMin) {
    rowLabels.push(formatMinutes(t));
  }

  return { days: DAYS, resources, bounds, stepMin, rowLabels, cells };
}
