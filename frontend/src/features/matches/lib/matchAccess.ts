import type { VenueMatchWindow, VenueUnavailability } from "../api";

const DAY_LABELS = ["", "lundi", "mardi", "mercredi", "jeudi", "vendredi", "samedi", "dimanche"];

/** ISO weekday (1..7) of a Y-m-d date — UTC noon so no TZ edge flips the day. */
export function isoDayOf(matchDate: string): number {
  const day = new Date(`${matchDate}T12:00:00Z`).getUTCDay();
  return 0 === day ? 7 : day;
}

/** Venues that hold ≥ 1 match access window — the derived « match venue » flag. */
export function matchVenueIds(windows: VenueMatchWindow[]): Set<string> {
  return new Set(windows.map((w) => w.venueId));
}

/**
 * The capacity guard of the placement gesture (cadrage P1-4 §5) — HARD, no
 * degradation: these are the CLUB's own declarations, there is no mapping
 * ambiguity (contrary to the league envelope). Returns the human reason, or
 * null when the placement is allowed.
 *
 * Rules, in blocking order:
 * 1. venue unavailable on the match date (all-circumstances closure);
 * 2. the club declares match windows but this venue has none on that day;
 * 3. a kickoff outside every window of (venue, day).
 * A club with NO window anywhere has not adopted the data → nothing blocks.
 */
export function venueAccessError(
  venueId: string,
  venueName: string,
  matchDate: string,
  kickoff: string | null,
  windows: VenueMatchWindow[],
  unavailabilities: VenueUnavailability[],
): string | null {
  for (const unavailability of unavailabilities) {
    if (unavailability.venueId === venueId && matchDate >= unavailability.startDate && matchDate <= unavailability.endDate) {
      const label = null !== unavailability.label ? ` (${unavailability.label})` : "";
      return `${venueName} est indisponible du ${frDate(unavailability.startDate)} au ${frDate(unavailability.endDate)}${label}.`;
    }
  }

  if (0 === windows.length) {
    return null; // the club has not adopted match windows — nothing to enforce
  }

  const day = isoDayOf(matchDate);
  const dayWindows = windows.filter((w) => w.venueId === venueId && w.dayOfWeek === day);
  if (0 === dayWindows.length) {
    return `Pas d'accès match le ${DAY_LABELS[day] ?? "?"} à ${venueName}.`;
  }
  if (null !== kickoff && "" !== kickoff && !dayWindows.some((w) => kickoff >= w.startTime && kickoff < w.endTime)) {
    const ranges = dayWindows.map((w) => `${w.startTime}–${w.endTime}`).join(", ");
    return `Hors fenêtre d'accès match (${ranges}).`;
  }

  return null;
}

function frDate(ymd: string): string {
  return new Date(`${ymd}T12:00:00Z`).toLocaleDateString("fr-FR", { day: "numeric", month: "short" });
}
