import { describe, expect, it } from "vitest";

import type { Reservation, VenueTrainingSlot } from "../api";
import { orphanReservationIds } from "./orphanReservations";
import cases from "./orphanReservations.parity.json";

/**
 * P4-88 — CÔTÉ FRONT de la parité mécanique du prédicat ÉTROIT « orpheline par triplet ».
 * Le MÊME fichier de cas alimente `OrphanReservationsMirrorParityTest.php` (backend,
 * `OrphanPinGuard::orphanTripletIds`). Changer le prédicat étroit d'un seul côté rougit
 * ce côté-là. Le bloqueur backend COMPLET est un sur-ensemble assumé (cas `divergence`).
 */
describe("orphanReservationIds — parité mécanique avec OrphanPinGuard::orphanTripletIds (PHP)", () => {
  for (const c of cases.cases) {
    it(c.name, () => {
      const found = orphanReservationIds(c.reservations as Reservation[], c.slots as VenueTrainingSlot[]);
      expect([...found].sort()).toEqual([...c.orphanIds].sort());
    });
  }
});
