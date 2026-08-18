import { describe, expect, it } from "vitest";

import type { Reservation, VenueTrainingSlot } from "../api";
import { orphanReservationIds, unservedReservationIds } from "./orphanReservations";
import cases from "./orphanReservations.parity.json";

interface ParityCase {
  name: string;
  slots: VenueTrainingSlot[];
  reservations: Reservation[];
  orphanIds: string[];
  unservedIds?: string[];
  disabledVenueIds?: string[];
  closedWeekdaysByVenue?: Record<string, number[]>;
}

/**
 * P4-88 + P2-37 D5 — CÔTÉ FRONT des DEUX prédicats miroir. Le MÊME fichier de cas alimente
 * `OrphanReservationsMirrorParityTest.php` (backend). Changer un prédicat d'un seul côté
 * rougit ce côté-là. Le bloqueur backend COMPLET (verrous HARD, messages) reste au-delà.
 */
describe("parité mécanique avec OrphanPinGuard (PHP)", () => {
  for (const raw of cases.cases) {
    const c = raw as ParityCase;

    it(`étroit — ${c.name}`, () => {
      const found = orphanReservationIds(c.reservations, c.slots);
      expect([...found].sort()).toEqual([...c.orphanIds].sort());
    });

    it(`large — ${c.name}`, () => {
      const found = unservedReservationIds(c.reservations, c.slots, c.disabledVenueIds ?? [], c.closedWeekdaysByVenue ?? {});
      expect([...found].sort()).toEqual([...(c.unservedIds ?? c.orphanIds)].sort());
    });
  }
});
