import type { Reservation, VenueTrainingSlot } from "../api";
import { slotKey } from "./reservationSlots";

/**
 * P4-44 — les réservations qui ne retombent sur AUCUN créneau de la grille.
 *
 * Une réservation désigne son créneau par le TRIPLET (gymnase, jour, heure), jamais par
 * son id : déplacer ou supprimer un créneau pouvait la laisser sur un horaire mort.
 *
 * Pourquoi cette règle vit ICI et pas seulement au serveur : l'écran « Réserver » ne
 * peut pas montrer ces lignes — sa grille boucle sur les CRÉNEAUX, donc une réservation
 * hors grille n'a aucune case où s'afficher. Le récap est le seul endroit qui les liste
 * (il boucle sur les réservations), c'est donc là que le geste correctif doit vivre.
 *
 * Fonction PURE : la règle se teste pour elle-même, pas à travers un écran (§7.2 pt 5).
 */
export function orphanReservationIds(reservations: Reservation[], slots: VenueTrainingSlot[]): Set<string> {
  const grid = new Set(slots.map((s) => slotKey(s.venueId, s.dayOfWeek, s.startTime)));

  return new Set(
    reservations
      .filter((r) => !grid.has(slotKey(r.venueId, r.dayOfWeek, r.startTime)))
      .map((r) => r.id),
  );
}
