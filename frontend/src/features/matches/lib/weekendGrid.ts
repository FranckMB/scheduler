import type { Fixture, Team, TeamMatchHabit, Venue } from "../api";
import { isoWeekday, timeToMinutes } from "./envelope";

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
  date.setDate(date.getDate() + (6 - isoWeekday(dateStr))); // shift to that week's Saturday
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
  /** P1-4 PR C — a HABIT ghost, not a match: the team's protected window on a
   * weekend its calendar has not reached yet. Purely visual, never blocking. */
  ghost: boolean;
  /** P1-4 PR E1 — anchor badge: MANUAL (or legacy null) placement, the solver
   * never moves it. SOLVER-placed = re-solvable, no padlock. */
  locked: boolean;
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

/** The date (Y-m-d) of an ISO weekday inside the bucket week of a Saturday key. */
function dateOfWeekday(saturdayKey: string, isoDay: number): string {
  const date = new Date(`${saturdayKey}T00:00:00`);
  date.setDate(date.getDate() - (6 - isoDay));
  return toYmd(date);
}

interface GhostSlot {
  dateKey: string;
  venueId: string;
  teamId: string;
  kickoffMin: number;
}

/**
 * Habit ghosts of a weekend bucket (P1-4 PR C): one per venue-anchored habit
 * whose team has NO fixture on that weekday's date — the reality (any fixture,
 * home OR away) dissolves the ghost: an away match FREES the habitual slot
 * (« la fenêtre se libère — signalée, comblable »).
 */
function ghostSlots(habits: TeamMatchHabit[], fixtures: Fixture[], weekendKey: string | null): GhostSlot[] {
  if (null === weekendKey) {
    return [];
  }
  const ghosts: GhostSlot[] = [];
  for (const habit of habits) {
    if (null === habit.venueId) {
      continue; // the grid is venue-columned — a day+time habit has no column
    }
    const dateKey = dateOfWeekday(weekendKey, habit.dayOfWeek);
    if (fixtures.some((f) => f.teamId === habit.teamId && f.matchDate === dateKey)) {
      continue;
    }
    ghosts.push({ dateKey, venueId: habit.venueId, teamId: habit.teamId, kickoffMin: timeToMinutes(habit.kickoffTime) });
  }
  return ghosts;
}

/**
 * Pure layout of the placed home matches of ONE weekend. A date is a super-column
 * split into one sub-column per venue used that date; rows are 15-min steps from
 * the earliest footprint start to the latest end. Each match block spans its full
 * 2h15 footprint (kickoff−30 → kickoff+105), labelled at the kickoff time.
 * Habit ghosts (P1-4 PR C) join the layout as translucent, non-blocking blocks.
 */
export function buildWeekendGrid(
  fixtures: Fixture[],
  venues: Map<string, Venue>,
  teams: Map<string, Team>,
  outOfEnvelope: Set<string> = new Set(),
  habits: TeamMatchHabit[] = [],
  weekendKey: string | null = null,
  stepMin = 15,
): WeekendGridModel {
  const placed = fixtures.filter(isPlacedOnGrid);
  const ghosts = ghostSlots(habits, fixtures, weekendKey);
  if (0 === placed.length && 0 === ghosts.length) {
    return { columns: [], dateGroups: [], rows: [], cells: [], startMin: 0, stepMin, empty: true };
  }

  let min = Infinity;
  let max = -Infinity;
  for (const fixture of placed) {
    const start = timeToMinutes(fixture.kickoffTime as string) - WARMUP_MINUTES;
    min = Math.min(min, start);
    max = Math.max(max, start + WARMUP_MINUTES + MATCH_MINUTES);
  }
  for (const ghost of ghosts) {
    min = Math.min(min, ghost.kickoffMin - WARMUP_MINUTES);
    max = Math.max(max, ghost.kickoffMin + MATCH_MINUTES);
  }
  const startMin = Math.floor(min / 60) * 60;
  const endMin = Math.ceil(max / 60) * 60;

  const dateKeys = [...new Set([...placed.map((f) => f.matchDate), ...ghosts.map((g) => g.dateKey)])].sort();
  const columns: WeekendColumn[] = [];
  const dateGroups: DateGroup[] = [];
  let cssColumn = 2; // col 1 is the time gutter
  for (const dateKey of dateKeys) {
    const dayFixtures = placed.filter((f) => f.matchDate === dateKey);
    const dayGhosts = ghosts.filter((g) => g.dateKey === dateKey);
    const venueIds = [...new Set([...dayFixtures.map((f) => f.venueId as string), ...dayGhosts.map((g) => g.venueId)])].sort((a, b) =>
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
      ghost: false,
      locked: "SOLVER" !== fixture.placementSource,
    };
    cells.push(cell);
    intervals.push({ startMin: start, endMin: end, cell });
  }

  // Habit ghosts share the lane layout so a manual placement lands BESIDE the
  // protected window instead of hiding it.
  for (const ghost of ghosts) {
    const idx = columnIndex.get(`${ghost.dateKey}:${ghost.venueId}`);
    if (undefined === idx) {
      continue;
    }
    const start = ghost.kickoffMin - WARMUP_MINUTES;
    const end = ghost.kickoffMin + MATCH_MINUTES;
    const cell: WeekendCell = {
      key: `ghost:${ghost.teamId}:${ghost.dateKey}`,
      fixtureId: "",
      gridColumn: 2 + idx,
      gridRowStart: 3 + Math.round((start - startMin) / stepMin),
      gridRowSpan: Math.max(1, Math.round((end - start) / stepMin)),
      lane: 0,
      laneCount: 1,
      teamLabel: teams.get(ghost.teamId)?.name ?? "Équipe ?",
      opponentLabel: "",
      venueLabel: venues.get(ghost.venueId)?.name ?? "Gymnase ?",
      venueColor: venues.get(ghost.venueId)?.color ?? null,
      kickoffLabel: formatMinutes(ghost.kickoffMin),
      footprintLabel: `${formatMinutes(start)}–${formatMinutes(end)}`,
      outOfEnvelope: false,
      ghost: true,
      locked: false,
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
