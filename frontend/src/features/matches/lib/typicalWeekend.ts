import type { TeamMatchHabit } from "../api";

/**
 * P1-4 PR E2 — the « week-end type » view (founder reframing of « semaine
 * type », 2026-08-03): the manager's IDEAL weekend template — every team's
 * habitual window laid out Sat/Sun × venues, date-less. Read-only (habits are
 * edited in HabitsLinksDialog). Pure layout, MÊME empreinte que la grille datée
 * (constantes importées de `weekendGrid`, elles-mêmes alignées sur `MatchFootprint.php`).
 */

// D-02 : ces deux constantes valaient 30/135 ici et 30/105 dans `weekendGrid` — or le
// serveur fait foi (`MatchFootprint.php` : 30 + 105). Le « week-end type » dessinait donc des
// blocs de 2h15 pour des matchs que le solveur traite comme 1h45, et l'en-tête ci-dessus
// affirmait pourtant « same footprint geometry as the dated grid ».
import { MATCH_MINUTES, WARMUP_MINUTES } from "./weekendGrid";
import { parseTime } from "@/shared/lib/time";

export interface TypicalColumn {
  key: string;
  dayOfWeek: 6 | 7;
  venueId: string;
}

export interface TypicalBlock {
  key: string;
  teamId: string;
  columnKey: string;
  /** Minutes since midnight of the 2h15 footprint. */
  startMin: number;
  endMin: number;
  kickoff: string;
  lane: number;
  laneCount: number;
}

export interface TypicalWeekendModel {
  columns: TypicalColumn[];
  blocks: TypicalBlock[];
  /** Habits without a venue — listed apart (the grid is venue-columned). */
  venueless: TeamMatchHabit[];
  startMin: number;
  endMin: number;
  empty: boolean;
}

function toMinutes(time: string): number {
  // D-21 : lecture partagée, repli 0 explicite (mise en page seule).
  return parseTime(time) ?? 0;
}

export function buildTypicalWeekend(habits: TeamMatchHabit[]): TypicalWeekendModel {
  const weekend = habits.filter((h) => 6 === h.dayOfWeek || 7 === h.dayOfWeek);
  const withVenue = weekend.filter((h) => null !== h.venueId);
  const venueless = weekend.filter((h) => null === h.venueId);

  if (0 === withVenue.length) {
    return { columns: [], blocks: [], venueless, startMin: 0, endMin: 0, empty: 0 === weekend.length };
  }

  const columnKeys = new Set(withVenue.map((h) => `${h.dayOfWeek}:${h.venueId as string}`));
  const columns: TypicalColumn[] = [...columnKeys].sort().map((key) => {
    const [day, venueId] = key.split(":") as [string, string];
    return { key, dayOfWeek: Number(day) as 6 | 7, venueId };
  });

  let min = Infinity;
  let max = -Infinity;
  const blocks: TypicalBlock[] = withVenue.map((habit) => {
    const kickoffMin = toMinutes(habit.kickoffTime);
    const startMin = kickoffMin - WARMUP_MINUTES;
    const endMin = kickoffMin + MATCH_MINUTES;
    min = Math.min(min, startMin);
    max = Math.max(max, endMin);
    return {
      key: habit.id,
      teamId: habit.teamId,
      columnKey: `${habit.dayOfWeek}:${habit.venueId as string}`,
      startMin,
      endMin,
      kickoff: habit.kickoffTime,
      lane: 0,
      laneCount: 1,
    };
  });

  // Lane overlapping blocks of the same column side by side (same rule as the
  // dated grid: a template collision must be SEEN, not hidden).
  for (const column of columns) {
    const columnBlocks = blocks.filter((b) => b.columnKey === column.key).sort((a, b) => a.startMin - b.startMin);
    const laneEnds: number[] = [];
    for (const block of columnBlocks) {
      let lane = laneEnds.findIndex((end) => end <= block.startMin);
      if (-1 === lane) {
        lane = laneEnds.length;
        laneEnds.push(0);
      }
      laneEnds[lane] = block.endMin;
      block.lane = lane;
    }
    for (const block of columnBlocks) {
      block.laneCount = laneEnds.length;
    }
  }

  return {
    columns,
    blocks,
    venueless,
    startMin: Math.floor(min / 60) * 60,
    endMin: Math.ceil(max / 60) * 60,
    empty: false,
  };
}
