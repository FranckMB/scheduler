import type { Fixture, LeagueWindow } from "../api";

/** Time-ish string ("18:00", "18:00:00", ISO) → minutes since midnight. */
export function timeToMinutes(time: string | null | undefined): number {
  // D-21 : lecture partagée, repli 0 explicite (une enveloppe se calcule, elle ne valide pas).
  return parseTime(time) ?? 0;
}

// D-30 : cette version calculait le jour à MINUIT LOCAL — elle basculait d'un jour hors
// fuseau français, là où `matchAccess` (midi UTC) restait juste. Foyer unique partagé.
import { isoDayOf as isoWeekday } from "@/shared/lib/days";
import { parseTime } from "@/shared/lib/time";

export { isoWeekday };

export interface EnvelopeResult {
  /** True only when the team maps to at least one league window (HARD guard active). */
  mapped: boolean;
  /** The windows that apply to the fixture's team (empty when unmapped). */
  windows: LeagueWindow[];
  /** Whether the fixture's DATE falls on an allowed day (only meaningful when mapped). */
  dayOk: boolean;
  /** Whether the kickoff falls inside an allowed window (only meaningful when mapped && dayOk). */
  timeOk: (kickoff: string) => boolean;
}

/**
 * Resolve the league-envelope windows that apply to a fixture's team and expose
 * day/time validators.
 *
 * P1-4 PR E2 (dette iv): the team↔window join lives on the SERVER
 * (`LeagueEnvelopeResolver`, the same resolution the solver enforces as HARD) —
 * the client only looks up `resolvedTeamWindows` from GET /api/league-match-windows.
 * The old client-side tolerant join is gone: two implementations of the same
 * join had already started to diverge. An unmapped team ([] or absent) keeps
 * the PR-2 degradation: advisory reference, never a block.
 */
export function resolveEnvelope(
  fixture: Fixture,
  resolvedTeamWindows: Record<string, string[]>,
  windows: LeagueWindow[],
): EnvelopeResult {
  const ids = new Set(resolvedTeamWindows[fixture.teamId] ?? []);
  const matched = windows.filter((w) => ids.has(w.id));

  const day = isoWeekday(fixture.matchDate);
  const dayWindows = matched.filter((w) => w.dayOfWeek === day);

  return {
    mapped: matched.length > 0,
    windows: matched,
    dayOk: dayWindows.length > 0,
    timeOk: (kickoff: string) => {
      const min = timeToMinutes(kickoff);
      return dayWindows.some((w) => min >= timeToMinutes(w.kickoffMin) && min <= timeToMinutes(w.kickoffMax));
    },
  };
}

/**
 * Whether a placement (date already fixed, kickoff being chosen) sits inside the
 * envelope. Unmapped teams are never blocked (advisory only) → always in-envelope.
 */
export function isInEnvelope(envelope: EnvelopeResult, kickoff: string): boolean {
  if (!envelope.mapped) {
    return true;
  }
  return envelope.dayOk && envelope.timeOk(kickoff);
}
