import type { TeamCoach, VenueTrainingSlot } from "../api";

/**
 * Team names a coach handles (via TeamCoach links), in link order. Shared by the
 * recap and the period read-only coach views so both list the same teams.
 */
export function coachTeamNames(coachId: string, teamCoaches: TeamCoach[], teamName: Map<string, string>): string[] {
  return teamCoaches
    .filter((tc) => tc.coachId === coachId)
    .map((tc) => teamName.get(tc.teamId) ?? "")
    .filter((n) => "" !== n);
}

/** Per-venue availability-slot count. Single source for the "X créneau(x)" meta. */
export function countSlotsByVenue(slots: VenueTrainingSlot[]): Map<string, number> {
  const byVenue = new Map<string, number>();
  for (const s of slots) {
    byVenue.set(s.venueId, (byVenue.get(s.venueId) ?? 0) + 1);
  }
  return byVenue;
}
