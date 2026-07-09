import { describe, expect, it } from "vitest";

import type { Reservation, VenueTrainingSlot } from "../api";
import { availableReservationSlots } from "./reservationSlots";

const slot = (id: string, venueId: string, dayOfWeek: number, startTime: string, capacity = 1): VenueTrainingSlot =>
  ({ id, venueId, dayOfWeek, startTime, durationMinutes: 90, capacity }) as VenueTrainingSlot;

const resa = (teamId: string, venueId: string, dayOfWeek: number, startTime: string): Reservation =>
  ({ id: `${teamId}-${venueId}-${dayOfWeek}`, teamId, venueId, dayOfWeek, startTime, durationMinutes: 90 }) as Reservation;

const NON_SPLIT = new Map([["v1", false]]);
const SPLIT = new Map([["v1", true]]);

describe("availableReservationSlots", () => {
  it("drops a full capacity-1 slot for other teams (non-divisible gym)", () => {
    const slots = [slot("s1", "v1", 2, "18:00:00")];
    const reservations = [resa("teamA", "v1", 2, "18:00")];
    expect(availableReservationSlots(slots, reservations, NON_SPLIT, "teamB").map((s) => s.id)).toEqual([]);
  });

  it("keeps a capacity-2 slot for one more team, then drops it when full", () => {
    const slots = [slot("s1", "v1", 2, "18:00:00", 2)];
    // one team booked → still one seat left for another team
    expect(availableReservationSlots(slots, [resa("teamA", "v1", 2, "18:00")], SPLIT, "teamB").map((s) => s.id)).toEqual(["s1"]);
    // two distinct teams booked → full
    const full = [resa("teamA", "v1", 2, "18:00"), resa("teamB", "v1", 2, "18:00")];
    expect(availableReservationSlots(slots, full, SPLIT, "teamC")).toEqual([]);
  });

  it("never offers a slot to a team that already reserved it (even with a free seat)", () => {
    const slots = [slot("s1", "v1", 2, "18:00:00", 2)];
    expect(availableReservationSlots(slots, [resa("teamA", "v1", 2, "18:00")], SPLIT, "teamA")).toEqual([]);
  });

  it("ignores capacity on a non-divisible gym (treated as 1)", () => {
    const slots = [slot("s1", "v1", 2, "18:00:00", 2)];
    expect(availableReservationSlots(slots, [resa("teamA", "v1", 2, "18:00")], NON_SPLIT, "teamB")).toEqual([]);
  });

  it("matches slot start with seconds against a HH:MM reservation", () => {
    const slots = [slot("s1", "v1", 2, "18:00:00")];
    // reservation stored as HH:MM must still collide with the seconds-form slot
    expect(availableReservationSlots(slots, [resa("teamA", "v1", 2, "18:00")], NON_SPLIT, "teamB")).toEqual([]);
  });
});
