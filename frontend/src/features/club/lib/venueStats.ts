/**
 * Statistiques par gymnase (demande fondateur 2026-08-04) — calculées sur le
 * planning EN VIGUEUR (les séances réellement placées, pas la disponibilité
 * saisie). Règles pures : testées sans écran.
 */

interface StatSlot {
  venueId: string;
  teamId: string;
  durationMinutes: number;
}

interface StatVenue {
  id: string;
  name: string;
}

export interface VenueStatRow {
  venueId: string;
  name: string;
  /** Heures hebdomadaires de séances placées dans ce gymnase. */
  hoursPerWeek: number;
  /** Équipes DISTINCTES qui s'y entraînent au moins une fois par semaine. */
  teamCount: number;
}

/** Tri : le plus utilisé d'abord — c'est la question posée (« combien par gymnase ? »). */
export function computeVenueStats(slots: StatSlot[], venues: StatVenue[]): VenueStatRow[] {
  const nameOf = new Map(venues.map((v) => [v.id, v.name]));
  const minutes = new Map<string, number>();
  const teams = new Map<string, Set<string>>();
  for (const slot of slots) {
    minutes.set(slot.venueId, (minutes.get(slot.venueId) ?? 0) + slot.durationMinutes);
    if (!teams.has(slot.venueId)) {
      teams.set(slot.venueId, new Set());
    }
    teams.get(slot.venueId)?.add(slot.teamId);
  }

  return [...minutes.entries()]
    .map(([venueId, mins]) => ({
      venueId,
      name: nameOf.get(venueId) ?? "?",
      hoursPerWeek: mins / 60,
      teamCount: teams.get(venueId)?.size ?? 0,
    }))
    .sort((a, b) => b.hoursPerWeek - a.hoursPerWeek || a.name.localeCompare(b.name));
}

/**
 * Le nombre de semaines de la saison, borné à ≥ 1 — la base de la PROJECTION
 * annuelle (hebdo × semaines). Assumé et DIT à l'écran : les vacances et
 * périodes ne sont pas déduites, c'est un ordre de grandeur, pas un décompte.
 */
export function seasonWeeks(startDate: string, endDate: string): number {
  const ms = new Date(`${endDate}T00:00:00Z`).getTime() - new Date(`${startDate}T00:00:00Z`).getTime();

  return Math.max(1, Math.round(ms / (7 * 24 * 3_600_000)));
}

/** « 12 h » / « 7,5 h » — une décimale seulement quand elle existe. */
export function formatHours(hours: number): string {
  const rounded = Math.round(hours * 10) / 10;

  return `${rounded.toLocaleString("fr-FR")} h`;
}
