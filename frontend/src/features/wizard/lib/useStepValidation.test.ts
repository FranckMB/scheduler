import { describe, expect, it } from "vitest";

import type { Team, Venue, VenueTrainingSlot } from "../api";
import type { Reservation } from "../store";
import { computeReservationWarnings } from "./useStepValidation";

const team = (id: string, name: string, sessionsPerWeek: number): Team => ({
  id,
  name,
  sportCategoryId: "c",
  priorityTierId: 1,
  tierOrder: 0,
  gender: null,
  sessionsPerWeek,
  isActive: true,
});

const venue = (id: string, name: string, canSplit: boolean): Venue => ({ id, name, color: null, canSplit, isActive: true });

const slot = (venueId: string, dayOfWeek: number, startTime: string, capacity: number): VenueTrainingSlot => ({
  id: `${venueId}-${dayOfWeek}-${startTime}`,
  venueId,
  dayOfWeek,
  startTime,
  durationMinutes: 90,
  capacity,
});

const reservation = (id: string, teamId: string, venueId: string, dayOfWeek: number, startTime: string): Reservation => ({
  id,
  teamId,
  venueId,
  dayOfWeek,
  startTime,
  durationMinutes: 90,
});

describe("computeReservationWarnings (W6)", () => {
  it("returns no warning for a clean set", () => {
    const teams = [team("t1", "U13", 1), team("t2", "U15", 1)];
    const venues = [venue("v1", "Gymnase A", false)];
    const slots = [slot("v1", 2, "18:00", 1), slot("v1", 4, "18:00", 1)];
    const reservations = [reservation("r1", "t1", "v1", 2, "18:00"), reservation("r2", "t2", "v1", 4, "18:00")];
    expect(computeReservationWarnings(reservations, teams, venues, slots)).toEqual([]);
  });

  it("warns when a non-splittable slot is shared by two teams", () => {
    const teams = [team("t1", "U13", 1), team("t2", "U15", 1)];
    const venues = [venue("v1", "Gymnase A", false)];
    const slots = [slot("v1", 2, "18:00", 1)];
    const reservations = [reservation("r1", "t1", "v1", 2, "18:00"), reservation("r2", "t2", "v1", 2, "18:00")];
    const warnings = computeReservationWarnings(reservations, teams, venues, slots);
    expect(warnings).toHaveLength(1);
    expect(warnings[0]).toContain("Créneau partagé par 2 équipes (max 1)");
  });

  it("does not warn when a splittable slot with capacity 2 holds two teams", () => {
    const teams = [team("t1", "U13", 1), team("t2", "U15", 1)];
    const venues = [venue("v1", "Gymnase A", true)];
    const slots = [slot("v1", 2, "18:00", 2)];
    const reservations = [reservation("r1", "t1", "v1", 2, "18:00"), reservation("r2", "t2", "v1", 2, "18:00")];
    expect(computeReservationWarnings(reservations, teams, venues, slots)).toEqual([]);
  });

  it("warns when a team reserves more slots than its sessions/week", () => {
    const teams = [team("t1", "U13", 2)];
    const venues = [venue("v1", "Gymnase A", false)];
    const slots = [slot("v1", 1, "18:00", 1), slot("v1", 3, "18:00", 1), slot("v1", 5, "18:00", 1)];
    const reservations = [
      reservation("r1", "t1", "v1", 1, "18:00"),
      reservation("r2", "t1", "v1", 3, "18:00"),
      reservation("r3", "t1", "v1", 5, "18:00"),
    ];
    const warnings = computeReservationWarnings(reservations, teams, venues, slots);
    expect(warnings).toContainEqual("U13 : 3 réservations pour 2 séance(s)/semaine.");
  });

  it("warns when a team has two sessions on the same day", () => {
    const teams = [team("t1", "U13", 2)];
    const venues = [venue("v1", "Gymnase A", false)];
    const slots = [slot("v1", 2, "18:00", 1), slot("v1", 2, "20:00", 1)];
    const reservations = [reservation("r1", "t1", "v1", 2, "18:00"), reservation("r2", "t1", "v1", 2, "20:00")];
    const warnings = computeReservationWarnings(reservations, teams, venues, slots);
    expect(warnings).toContainEqual("U13 : 2 séances le même jour (mardi).");
  });
});
