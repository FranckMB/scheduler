import type { Fixture, Team, Venue } from "../api";
import { timeToMinutes } from "./envelope";

/** Home match footprint (spec §4bis): warm-up before kickoff + play after. */
export const WARMUP_MINUTES = 30;
export const MATCH_MINUTES = 105;

/** minutes since midnight → "HH:MM". */
export function formatMinutes(total: number): string {
  const clamped = ((total % 1440) + 1440) % 1440;
  const h = Math.floor(clamped / 60);
  const m = clamped % 60;
  return `${String(h).padStart(2, "0")}:${String(m).padStart(2, "0")}`;
}

/** Local Y-m-d (never toISOString, which shifts to UTC and can flip the day). */
function toYmd(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
}

/** The Saturday (Y-m-d) of the week (Mon..Sun) containing a date — a match's weekend bucket. */
export function weekendKeyOf(dateStr: string): string {
  const date = new Date(`${dateStr}T00:00:00`);
  const day = date.getDay(); // 0=Sun..6=Sat
  const iso = 0 === day ? 7 : day; // 1..7
  date.setDate(date.getDate() + (6 - iso)); // shift to that week's Saturday
  return toYmd(date);
}

/** Human label for a weekend bucket ("Week-end du 4 oct."). */
export function weekendLabel(saturdayKey: string): string {
  const date = new Date(`${saturdayKey}T00:00:00`);
  return `Week-end du ${date.toLocaleDateString("fr-FR", { day: "numeric", month: "short" })}`;
}

/** Sorted distinct weekend buckets that contain at least one fixture. */
export function listWeekends(fixtures: Fixture[]): string[] {
  return [...new Set(fixtures.map((f) => weekendKeyOf(f.matchDate)))].sort();
}

/** A placed home fixture = one we can lay on the dated grid (venue + kickoff known). */
export function isPlacedOnGrid(fixture: Fixture): boolean {
  return "HOME" === fixture.homeAway && null !== fixture.venueId && null !== fixture.kickoffTime;
}

export interface WeekendColumn {
  key: string;
  dateKey: string;
  venueId: string;
  label: string;
  color: string | null;
}

export interface DateGroup {
  dateKey: string;
  label: string;
  /** 1-based CSS grid column where this date's block starts (col 1 = time gutter). */
  startColumn: number;
  span: number;
}

export interface WeekendCell {
  key: string;
  fixtureId: string;
  gridColumn: number;
  gridRowStart: number;
  gridRowSpan: number;
  lane: number;
  laneCount: number;
  teamLabel: string;
  opponentLabel: string;
  venueLabel: string;
  venueColor: string | null;
  kickoffLabel: string;
  footprintLabel: string;
  outOfEnvelope: boolean;
}

export interface WeekendGridRow {
  label: string | null;
  major: boolean;
}

export interface WeekendGridModel {
  columns: WeekendColumn[];
  dateGroups: DateGroup[];
  rows: WeekendGridRow[];
  cells: WeekendCell[];
  startMin: number;
  stepMin: number;
  empty: boolean;
}

interface Interval {
  startMin: number;
  endMin: number;
  cell: WeekendCell;
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

function dateLabel(dateKey: string): string {
  return new Date(`${dateKey}T00:00:00`).toLocaleDateString("fr-FR", { weekday: "short", day: "numeric", month: "short" });
}

/**
 * Pure layout of the placed home matches of ONE weekend. A date is a super-column
 * split into one sub-column per venue used that date; rows are 15-min steps from
 * the earliest footprint start to the latest end. Each match block spans its full
 * 2h15 footprint (kickoff−30 → kickoff+105), labelled at the kickoff time.
 */
export function buildWeekendGrid(
  fixtures: Fixture[],
  venues: Map<string, Venue>,
  teams: Map<string, Team>,
  outOfEnvelope: Set<string> = new Set(),
  stepMin = 15,
): WeekendGridModel {
  const placed = fixtures.filter(isPlacedOnGrid);
  if (0 === placed.length) {
    return { columns: [], dateGroups: [], rows: [], cells: [], startMin: 0, stepMin, empty: true };
  }

  let min = Infinity;
  let max = -Infinity;
  for (const fixture of placed) {
    const start = timeToMinutes(fixture.kickoffTime as string) - WARMUP_MINUTES;
    min = Math.min(min, start);
    max = Math.max(max, start + WARMUP_MINUTES + MATCH_MINUTES);
  }
  const startMin = Math.floor(min / 60) * 60;
  const endMin = Math.ceil(max / 60) * 60;

  const dateKeys = [...new Set(placed.map((f) => f.matchDate))].sort();
  const columns: WeekendColumn[] = [];
  const dateGroups: DateGroup[] = [];
  let cssColumn = 2; // col 1 is the time gutter
  for (const dateKey of dateKeys) {
    const dayFixtures = placed.filter((f) => f.matchDate === dateKey);
    const venueIds = [...new Set(dayFixtures.map((f) => f.venueId as string))].sort((a, b) =>
      (venues.get(a)?.name ?? "").localeCompare(venues.get(b)?.name ?? "", "fr"),
    );
    dateGroups.push({ dateKey, label: dateLabel(dateKey), startColumn: cssColumn, span: venueIds.length });
    for (const venueId of venueIds) {
      columns.push({
        key: `${dateKey}:${venueId}`,
        dateKey,
        venueId,
        label: venues.get(venueId)?.name ?? "Gymnase ?",
        color: venues.get(venueId)?.color ?? null,
      });
      cssColumn += 1;
    }
  }
  const columnIndex = new Map(columns.map((c, i) => [c.key, i]));

  const cells: WeekendCell[] = [];
  const intervals: Interval[] = [];
  for (const fixture of placed) {
    const idx = columnIndex.get(`${fixture.matchDate}:${fixture.venueId as string}`);
    if (undefined === idx) {
      continue;
    }
    const kickoff = timeToMinutes(fixture.kickoffTime as string);
    const start = kickoff - WARMUP_MINUTES;
    const end = kickoff + MATCH_MINUTES;
    const cell: WeekendCell = {
      key: fixture.id,
      fixtureId: fixture.id,
      gridColumn: 2 + idx,
      gridRowStart: 3 + Math.round((start - startMin) / stepMin),
      gridRowSpan: Math.max(1, Math.round((end - start) / stepMin)),
      lane: 0,
      laneCount: 1,
      teamLabel: teams.get(fixture.teamId)?.name ?? "Équipe ?",
      opponentLabel: fixture.opponentLabel,
      venueLabel: venues.get(fixture.venueId as string)?.name ?? "Gymnase ?",
      venueColor: venues.get(fixture.venueId as string)?.color ?? null,
      kickoffLabel: formatMinutes(kickoff),
      footprintLabel: `${formatMinutes(start)}–${formatMinutes(end)}`,
      outOfEnvelope: outOfEnvelope.has(fixture.id),
    };
    cells.push(cell);
    intervals.push({ startMin: start, endMin: end, cell });
  }
  assignLanes(intervals);

  const rows: WeekendGridRow[] = [];
  for (let t = startMin; t < endMin; t += stepMin) {
    rows.push({ label: 0 === t % 30 ? formatMinutes(t) : null, major: 0 === t % 60 });
  }

  return { columns, dateGroups, rows, cells, startMin, stepMin, empty: false };
}
