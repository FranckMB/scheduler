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
 *
 * ⚠️ MIROIR DÉCLARÉ (régime 2, P4-88) — prédicat ÉTROIT, SOUS-ENSEMBLE ASSUMÉ du bloqueur
 * backend `App\Service\OrphanPinGuard`. Ce front ne fait QUE « triplet ∉ grille » ; le
 * bloqueur backend retire EN PLUS les gymnases désactivés et les jours de fermeture, et
 * couvre les verrous HARD, puis REFUSE la génération. Le front LISTE (pour supprimer), le
 * backend REFUSE — c'est LUI qui fait foi. Parité MÉCANIQUE du prédicat étroit :
 * `OrphanPinGuard::orphanTripletIds`, cas partagés `orphanReservations.parity.json`, gardée
 * par `OrphanReservationsMirrorParityTest`. Les cas où le bloqueur complet refuse en plus
 * (gymnase désactivé, fermeture) y sont marqués comme divergence VOULUE (« front: non,
 * backend complet: oui »). Ce module figure au registre `FrontRederivationRegistryTest`.
 */
export function orphanReservationIds(reservations: Reservation[], slots: VenueTrainingSlot[]): Set<string> {
  const grid = new Set(slots.map((s) => slotKey(s.venueId, s.dayOfWeek, s.startTime)));

  return new Set(
    reservations
      .filter((r) => !grid.has(slotKey(r.venueId, r.dayOfWeek, r.startTime)))
      .map((r) => r.id),
  );
}
