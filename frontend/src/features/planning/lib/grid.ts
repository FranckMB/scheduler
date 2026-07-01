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

/** Extract the first HH:MM from a time-ish string ("18:00:00", "1970-01-01T18:00:00+00:00", "18:00"). */
function firstHourMinute(time: string): [number, number] {
  const match = time.match(/(\d{1,2}):(\d{2})/);
  if (null === match) {
    return [0, 0];
  }
  return [Number(match[1]), Number(match[2])];
}

/** Time-ish string → minutes since midnight (tolerates ISO datetimes from the API). */
export function parseTimeToMinutes(time: string): number {
  const [h, m] = firstHourMinute(time);
  return h * 60 + m;
}

/** minutes → "HH:MM" (zero-padded). */
export function formatMinutes(total: number): string {
  const h = Math.floor(total / 60);
  const m = total % 60;
  return `${String(h).padStart(2, "0")}:${String(m).padStart(2, "0")}`;
}

/** Time-ish string → "HH:MM" (zero-padded). */
export function toHourMinute(time: string): string {
  const [h, m] = firstHourMinute(time);
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

function coachName(coaches: Map<string, Coach>, coachId: string | null): string {
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
    return id === NO_COACH ? "Sans coach" : coachName(lookups.coaches, id);
  }
  return lookups.teams.get(id)?.name ?? "Équipe ?";
}

export interface GridResource {
  id: string;
  label: string;
}

/** Distinct resources present across the schedule for the current view (for the filter picker). */
export function availableResources(slots: Slot[], viewMode: ViewMode, lookups: Lookups): GridResource[] {
  const ids = [...new Set(slots.map((s) => resourceKeyForSlot(s, viewMode)))];
  return ids.map((id) => ({ id, label: resourceLabel(id, viewMode, lookups) })).sort((a, b) => a.label.localeCompare(b.label, "fr"));
}

export interface GridColumn {
  key: string;
  day: number;
  resourceId: string;
  label: string;
  color: string | null;
}

export interface DayGroup {
  day: number;
  label: string;
  /** 1-based CSS grid column where this day's block starts (col 1 = time gutter). */
  startColumn: number;
  span: number;
}

export interface GridCell {
  slotId: string;
  gridColumn: number;
  gridRowStart: number;
  gridRowSpan: number;
  /** Horizontal lane within the column for time-overlapping slots (side-by-side). */
  lane: number;
  laneCount: number;
  teamLabel: string;
  venueLabel: string;
  venueColor: string | null;
  coachLabel: string;
  day: number;
  startLabel: string;
  endLabel: string;
  locked: boolean;
}

interface Interval {
  startMin: number;
  endMin: number;
  cell: GridCell;
}

/** Lay time-overlapping cells in the same column into side-by-side lanes. */
function assignLanes(intervals: Interval[]): void {
  const byColumn = new Map<number, Interval[]>();
  for (const interval of intervals) {
    const list = byColumn.get(interval.cell.gridColumn) ?? [];
    list.push(interval);
    byColumn.set(interval.cell.gridColumn, list);
  }

  for (const list of byColumn.values()) {
    list.sort((a, b) => a.startMin - b.startMin || a.endMin - b.endMin);

    let cluster: Interval[] = [];
    let clusterEnd = -1;
    const flush = (): void => {
      const laneEnds: number[] = [];
      for (const item of cluster) {
        let lane = laneEnds.findIndex((end) => end <= item.startMin);
        if (-1 === lane) {
          lane = laneEnds.length;
        }
        laneEnds[lane] = item.endMin;
        item.cell.lane = lane;
      }
      for (const item of cluster) {
        item.cell.laneCount = laneEnds.length;
      }
    };

    for (const interval of list) {
      if (cluster.length > 0 && interval.startMin >= clusterEnd) {
        flush();
        cluster = [];
        clusterEnd = -1;
      }
      cluster.push(interval);
      clusterEnd = Math.max(clusterEnd, interval.endMin);
    }
    if (cluster.length > 0) {
      flush();
    }
  }
}

export interface GridRow {
  /** Displayed only on hour / half-hour rows; null elsewhere (keeps the grid line). */
  label: string | null;
  /** True on the hour — drawn with a stronger separator. */
  major: boolean;
}

export interface GridModel {
  columns: GridColumn[];
  dayGroups: DayGroup[];
  bounds: TimeBounds;
  stepMin: number;
  rows: GridRow[];
  cells: GridCell[];
}

/**
 * Pure layout. A day is a super-column split into one sub-column per resource —
 * but ONLY the resources actually used that day are shown (empty columns are
 * hidden). An optional resource filter narrows what is displayed. Changing the
 * view only changes which resource forms the sub-columns (same slots, re-grouped).
 */
export function buildGrid(slots: Slot[], viewMode: ViewMode, lookups: Lookups, filter: Set<string> = new Set(), stepMin = 15): GridModel {
  const visible = slots.filter(
    (s) => s.dayOfWeek >= 1 && s.dayOfWeek <= 6 && (0 === filter.size || filter.has(resourceKeyForSlot(s, viewMode))),
  );

  const bounds = computeTimeBounds(visible);

  const columns: GridColumn[] = [];
  const dayGroups: DayGroup[] = [];
  let cssColumn = 2; // col 1 is the time gutter

  for (const day of DAYS) {
    const daySlots = visible.filter((s) => s.dayOfWeek === day.n);
    if (0 === daySlots.length) {
      continue; // hide days with no slot
    }
    const resourceIds = [...new Set(daySlots.map((s) => resourceKeyForSlot(s, viewMode)))]
      .map((id) => ({ id, label: resourceLabel(id, viewMode, lookups) }))
      .sort((a, b) => a.label.localeCompare(b.label, "fr"));

    dayGroups.push({ day: day.n, label: day.label, startColumn: cssColumn, span: resourceIds.length });
    for (const { id, label } of resourceIds) {
      columns.push({
        key: `${day.n}:${id}`,
        day: day.n,
        resourceId: id,
        label,
        color: "gymnase" === viewMode ? (lookups.venues.get(id)?.color ?? null) : null,
      });
      cssColumn += 1;
    }
  }

  const columnIndex = new Map(columns.map((c, i) => [c.key, i]));

  const cells: GridCell[] = [];
  const intervals: Interval[] = [];
  for (const slot of visible) {
    const idx = columnIndex.get(`${slot.dayOfWeek}:${resourceKeyForSlot(slot, viewMode)}`);
    if (undefined === idx) {
      continue;
    }
    const start = parseTimeToMinutes(slot.startTime);
    const venue = lookups.venues.get(slot.venueId);
    const cell: GridCell = {
      slotId: slot.id,
      gridColumn: 2 + idx,
      gridRowStart: 3 + Math.round((start - bounds.startMin) / stepMin),
      gridRowSpan: Math.max(1, Math.round(slot.durationMinutes / stepMin)),
      lane: 0,
      laneCount: 1,
      teamLabel: lookups.teams.get(slot.teamId)?.name ?? "Équipe ?",
      venueLabel: venue?.name ?? "Gymnase ?",
      venueColor: venue?.color ?? null,
      coachLabel: coachName(lookups.coaches, slot.coachId),
      day: slot.dayOfWeek,
      startLabel: formatMinutes(start),
      endLabel: formatMinutes(start + slot.durationMinutes),
      locked: "NONE" !== slot.lockLevel || slot.temporaryLock,
    };
    cells.push(cell);
    intervals.push({ startMin: start, endMin: start + slot.durationMinutes, cell });
  }
  assignLanes(intervals);

  const rows: GridRow[] = [];
  for (let t = bounds.startMin; t < bounds.endMin; t += stepMin) {
    const onHalfHour = 0 === t % 30;
    rows.push({ label: onHalfHour ? formatMinutes(t) : null, major: 0 === t % 60 });
  }

  return { columns, dayGroups, bounds, stepMin, rows, cells };
}

export interface ConcernedSlot {
  slotId: string;
  dayLabel: string;
  timeLabel: string;
  teamLabel: string;
  venueLabel: string;
}

const dayLabelOf = (day: number): string => DAYS.find((d) => d.n === day)?.label ?? "?";

/**
 * The slots a diagnostic points at (its team / venue / coach), sorted by day+time
 * so the "when + which teams" of a conflict is spelled out instead of implied.
 */
export function concernedSlots(
  diagnostic: { teamId: string | null; venueId: string | null; coachId: string | null },
  slots: Slot[],
  lookups: Lookups,
): ConcernedSlot[] {
  const matches = slots.filter(
    (s) =>
      (null !== diagnostic.teamId && diagnostic.teamId === s.teamId) ||
      (null !== diagnostic.venueId && diagnostic.venueId === s.venueId) ||
      (null !== diagnostic.coachId && diagnostic.coachId === s.coachId),
  );

  return matches
    .map((s) => ({
      slotId: s.id,
      day: s.dayOfWeek,
      startMin: parseTimeToMinutes(s.startTime),
      dayLabel: dayLabelOf(s.dayOfWeek),
      timeLabel: toHourMinute(s.startTime),
      teamLabel: lookups.teams.get(s.teamId)?.name ?? "Équipe ?",
      venueLabel: lookups.venues.get(s.venueId)?.name ?? "Gymnase ?",
    }))
    .sort((a, b) => a.day - b.day || a.startMin - b.startMin)
    .map(({ slotId, dayLabel, timeLabel, teamLabel, venueLabel }) => ({ slotId, dayLabel, timeLabel, teamLabel, venueLabel }));
}
