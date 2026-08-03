import type { Fixture, TeamMatchHabit } from "../api";
import { isoWeekday } from "./envelope";

export interface HabitSuggestion {
  teamId: string;
  dayOfWeek: number;
  /** HH:MM */
  kickoffTime: string;
  venueId: string | null;
  /** Timed fixtures backing the suggestion (« 6 matchs »). */
  count: number;
}

/**
 * Pure habit inference (cadrage P1-4 §5.3 — « on accompagne, on ne décide
 * pas ») : per team, group the TIMED fixtures by (weekday, kickoff); suggest
 * the majority group when it holds ≥ 3 fixtures AND ≥ 50 % of the team's
 * timed fixtures (seuils fondateur). The venue rides along when ≥ 50 % of the
 * group's placed HOME fixtures share it. A day already carrying a DECLARED
 * habit is never re-suggested. A suggestion is a button, never a write.
 */
export function inferHabits(fixtures: Fixture[], declaredHabits: TeamMatchHabit[]): HabitSuggestion[] {
  const declaredDays = new Set(declaredHabits.map((h) => `${h.teamId}|${h.dayOfWeek}`));

  const timedByTeam = new Map<string, Fixture[]>();
  for (const fixture of fixtures) {
    if (null === fixture.kickoffTime) {
      continue;
    }
    timedByTeam.set(fixture.teamId, [...(timedByTeam.get(fixture.teamId) ?? []), fixture]);
  }

  const suggestions: HabitSuggestion[] = [];
  timedByTeam.forEach((timed, teamId) => {
    const groups = new Map<string, Fixture[]>();
    for (const fixture of timed) {
      const key = `${isoWeekday(fixture.matchDate)}|${fixture.kickoffTime as string}`;
      groups.set(key, [...(groups.get(key) ?? []), fixture]);
    }
    let best: { key: string; group: Fixture[] } | null = null;
    groups.forEach((group, key) => {
      if (null === best || group.length > best.group.length) {
        best = { key, group };
      }
    });
    if (null === best) {
      return;
    }
    const { key, group } = best as { key: string; group: Fixture[] };
    if (group.length < 3 || group.length * 2 < timed.length) {
      return;
    }
    const [day, kickoff] = key.split("|");
    const dayOfWeek = Number(day);
    if (declaredDays.has(`${teamId}|${dayOfWeek}`)) {
      return;
    }

    // Venue: shared by ≥ 50 % of the group's placed HOME fixtures, else null.
    // The FBI text label (fbiVenueLabel) is NEVER resolved to a Venue (PR A).
    const homeVenues = group.filter((f) => "HOME" === f.homeAway && null !== f.venueId).map((f) => f.venueId as string);
    let venueId: string | null = null;
    if (homeVenues.length > 0) {
      const counts = new Map<string, number>();
      for (const id of homeVenues) {
        counts.set(id, (counts.get(id) ?? 0) + 1);
      }
      counts.forEach((count, id) => {
        if (count * 2 >= homeVenues.length && (null === venueId || count > (counts.get(venueId) ?? 0))) {
          venueId = id;
        }
      });
    }

    suggestions.push({ teamId, dayOfWeek, kickoffTime: kickoff, venueId, count: group.length });
  });

  return suggestions;
}
