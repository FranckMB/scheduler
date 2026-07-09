import { describe, expect, it } from "vitest";

import type { PriorityTier, Reservation, Team, VenueTrainingSlot } from "../api";
import { assignableTeams, effectiveSlotCapacity, reservedTeamsBySlot, teamReservationCount } from "./reservationSlots";

const slot = (id: string, venueId: string, dayOfWeek: number, startTime: string, capacity = 1): VenueTrainingSlot =>
  ({ id, venueId, dayOfWeek, startTime, durationMinutes: 90, capacity }) as VenueTrainingSlot;

const resa = (teamId: string, venueId: string, dayOfWeek: number, startTime: string): Reservation =>
  ({ id: `${teamId}-${venueId}-${dayOfWeek}-${startTime}`, teamId, venueId, dayOfWeek, startTime, durationMinutes: 90 }) as Reservation;

const team = (id: string, name: string, priorityTierId: number, sessionsPerWeek: number, tierOrder = 0): Team =>
  ({ id, name, priorityTierId, tierOrder, sessionsPerWeek, sportCategoryId: "c" }) as Team;

const TIERS: PriorityTier[] = [
  { id: 1, label: "S", name: "Fanion", color: null },
  { id: 5, label: "D", name: "Bonus", color: null },
];

const NON_SPLIT = new Map([["v1", false]]);
const SPLIT = new Map([["v1", true]]);

describe("effectiveSlotCapacity", () => {
  it("caps a known non-divisible gym at 1, else trusts slot.capacity", () => {
    expect(effectiveSlotCapacity(slot("s", "v1", 2, "18:00", 2), NON_SPLIT)).toBe(1);
    expect(effectiveSlotCapacity(slot("s", "v1", 2, "18:00", 2), SPLIT)).toBe(2);
    expect(effectiveSlotCapacity(slot("s", "v1", 2, "18:00", 2), new Map())).toBe(2); // venue not loaded
  });
});

describe("reservedTeamsBySlot / teamReservationCount", () => {
  it("groups teams per slot and counts per team", () => {
    const reservations = [resa("a", "v1", 2, "18:00"), resa("b", "v1", 2, "18:00"), resa("a", "v1", 4, "18:00")];
    expect(reservedTeamsBySlot(reservations).get("v1|2|18:00")).toEqual(["a", "b"]);
    expect(teamReservationCount(reservations).get("a")).toBe(2);
    expect(teamReservationCount(reservations).get("b")).toBe(1);
  });
});

describe("assignableTeams", () => {
  const teams = [team("d1", "Alpha", 5, 2), team("s1", "Zoulou", 1, 2)]; // Alpha=D, Zoulou=S(fanion)

  it("orders by rank (fanion first), not alphabetically", () => {
    expect(assignableTeams(teams, TIERS, slot("s", "v1", 2, "18:00"), [], NON_SPLIT).map((t) => t.id)).toEqual(["s1", "d1"]);
  });

  it("excludes a team already reserved on the slot", () => {
    const reservations = [resa("s1", "v1", 2, "18:00")];
    // capacity 1 → slot full after one team → nothing offered
    expect(assignableTeams(teams, TIERS, slot("s", "v1", 2, "18:00", 1), reservations, NON_SPLIT)).toEqual([]);
  });

  it("keeps the free seat of a divisible slot for the OTHER team", () => {
    const reservations = [resa("s1", "v1", 2, "18:00")];
    expect(assignableTeams(teams, TIERS, slot("s", "v1", 2, "18:00", 2), reservations, SPLIT).map((t) => t.id)).toEqual(["d1"]);
  });

  it("drops a team that reached its sessionsPerWeek ceiling — even on a different free slot", () => {
    // Zoulou (2 sessions) already has 2 reservations elsewhere → gone everywhere.
    const reservations = [resa("s1", "v1", 3, "18:00"), resa("s1", "v1", 5, "18:00")];
    expect(assignableTeams(teams, TIERS, slot("s", "v1", 2, "18:00"), reservations, NON_SPLIT).map((t) => t.id)).toEqual(["d1"]);
  });

  it("offers nothing once the slot is full", () => {
    const reservations = [resa("a", "v1", 2, "18:00"), resa("b", "v1", 2, "18:00")];
    expect(assignableTeams(teams, TIERS, slot("s", "v1", 2, "18:00", 2), reservations, SPLIT)).toEqual([]);
  });
});
