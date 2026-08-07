import { describe, expect, it } from "vitest";

import type { Reservation, VenueTrainingSlot } from "../api";
import { orphanReservationIds } from "./orphanReservations";

const slot = (venueId: string, dayOfWeek: number, startTime: string): VenueTrainingSlot =>
  ({ id: `s-${venueId}-${dayOfWeek}-${startTime}`, venueId, dayOfWeek, startTime, durationMinutes: 90, capacity: 1 }) as VenueTrainingSlot;

const reservation = (id: string, venueId: string, dayOfWeek: number, startTime: string): Reservation =>
  ({ id, schedulePlanId: null, teamId: "t1", venueId, dayOfWeek, startTime, durationMinutes: 90 }) as Reservation;

describe("orphanReservationIds — P4-44", () => {
  it("ne signale rien quand la réservation retombe sur son créneau", () => {
    const found = orphanReservationIds([reservation("r1", "v1", 2, "18:00")], [slot("v1", 2, "18:00")]);

    expect([...found]).toEqual([]);
  });

  it("signale la réservation dont le créneau a été DÉPLACÉ (l'horaire n'existe plus)", () => {
    // Le scénario réel : le gestionnaire corrige « 18h00 » en « 18h30 » à l'étape
    // Gymnases. Sans suivi, la réservation reste sur un horaire mort — que le moteur
    // place quand même sur le socle, en silence.
    const found = orphanReservationIds([reservation("r1", "v1", 2, "18:00")], [slot("v1", 2, "18:30")]);

    expect([...found]).toEqual(["r1"]);
  });

  it("signale la réservation dont le créneau a changé de JOUR ou de GYMNASE", () => {
    const reservations = [reservation("jour", "v1", 2, "18:00"), reservation("gym", "v1", 3, "18:00")];
    const found = orphanReservationIds(reservations, [slot("v1", 3, "18:00"), slot("v2", 2, "18:00")]);

    expect([...found]).toEqual(["jour"]);
  });

  it("compare les heures NORMALISÉES : « 18:00:00 » et « 18:00 » sont le même créneau", () => {
    // La grille rend parfois l'heure avec les secondes, la réservation sans — une
    // comparaison brute inventerait des orphelines sur des données pourtant saines.
    const found = orphanReservationIds([reservation("r1", "v1", 2, "18:00")], [slot("v1", 2, "18:00:00")]);

    expect([...found]).toEqual([]);
  });

  it("sans aucun créneau, TOUTES les réservations sont orphelines", () => {
    const found = orphanReservationIds([reservation("r1", "v1", 2, "18:00"), reservation("r2", "v1", 3, "19:00")], []);

    expect(found.size).toBe(2);
  });
});
