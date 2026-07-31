import type { Team, TeamPeriodOverride, Venue, VenuePeriodOverride } from "../api";

/**
 * P2-15 — LA RÈGLE : « ce que la période contient », en fonctions PURES.
 *
 * Elle vit ici, et pas dans les hooks, pour une raison vérifiée : tant qu'elle était
 * dans les hooks, les tests d'écran la MOCKAIENT et ne gardaient donc que le câblage —
 * neutraliser le filtrage laissait la suite entièrement verte. Une règle testable sans
 * React est une règle qu'on peut réellement épingler.
 *
 * ⚠ Ces fonctions n'implémentent AUCUNE règle métier : elles APPLIQUENT des overrides
 * déjà chargés. Les vraies décisions — défauts par type de période, résolution des tags,
 * héritage des contraintes — restent côté serveur (`PeriodConstraintSelector`, #340) et
 * doivent y rester : c'est ce qui les distingue d'un énième miroir (cf. P2-14, P3-19).
 */

/**
 * Les gymnases qui SERVENT la période.
 *
 * Seul `DISABLED` retire un gymnase. `BLANK` (« vierge ») vide sa grille mais le gymnase
 * sert toujours — les confondre retirerait de l'écran un gymnase que le solveur voit.
 */
export function disabledVenueIds(overrides: VenuePeriodOverride[]): Set<string> {
  return new Set(overrides.filter((o) => "DISABLED" === o.mode).map((o) => o.venueId));
}

export function activeVenues(venues: Venue[], overrides: VenuePeriodOverride[]): Venue[] {
  const disabled = disabledVenueIds(overrides);

  return 0 === disabled.size ? venues : venues.filter((v) => !disabled.has(v.id));
}

/** Les équipes mises en pause pour la période (sparse : pas de ligne = l'équipe joue). */
export function pausedTeamIds(overrides: TeamPeriodOverride[]): Set<string> {
  return new Set(overrides.filter((o) => !o.isActive).map((o) => o.teamId));
}

/** Les équipes qui seront GÉNÉRÉES — celles que le payload solveur contiendra. */
export function activeTeams(teams: Team[], paused: Set<string>): Team[] {
  return 0 === paused.size ? teams : teams.filter((t) => !paused.has(t.id));
}
