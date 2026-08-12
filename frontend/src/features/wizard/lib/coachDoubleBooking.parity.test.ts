import { describe, expect, it } from "vitest";

import { type Booking, bookingsCollide } from "./coachDoubleBooking";
import cases from "./coachDoubleBooking.parity.json";

/**
 * P4-88 — CÔTÉ FRONT de la parité mécanique de la règle de dédoublement de coach.
 * Le MÊME fichier de cas alimente `CoachDoubleBookingMirrorParityTest.php` (backend) :
 * chaque `collide` est le verdict attendu des deux implémentations. Changer la règle
 * ici sans porter le cas partagé rougit ce test ; l'inverse rougit le test PHP.
 */
describe("bookingsCollide — parité mécanique avec CoachDoubleBookingDetector (PHP)", () => {
  for (const c of cases.cases) {
    it(c.name, () => {
      expect(bookingsCollide(c.a as Booking, c.b as Booking)).toBe(c.collide);
      // Symétrique : l'ordre des deux séances ne change jamais le verdict.
      expect(bookingsCollide(c.b as Booking, c.a as Booking)).toBe(c.collide);
    });
  }
});
