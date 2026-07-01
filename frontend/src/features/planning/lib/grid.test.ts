import { describe, expect, it } from "vitest";

import type { Coach, Slot, Team, Venue } from "../api";
import { buildGrid, computeTimeBounds, formatMinutes, type Lookups, NO_COACH, parseTimeToMinutes, resourceKeyForSlot, toHourMinute } from "./grid";

function slot(over: Partial<Slot>): Slot {
  return {
    id: "id",
    scheduleId: "s",
    teamId: "t1",
    venueId: "v1",
    coachId: "c1",
    dayOfWeek: 1,
    startTime: "18:00:00",
    durationMinutes: 90,
    lockLevel: "NONE",
    temporaryLock: false,
    ...over,
  };
}

const lookups: Lookups = {
  teams: new Map<string, Team>([
    ["t1", { id: "t1", name: "U11", sportCategoryId: "cat1" }],
    ["t2", { id: "t2", name: "U13", sportCategoryId: "cat1" }],
  ]),
  venues: new Map<string, Venue>([
    ["v1", { id: "v1", name: "Alpha", color: "#ff0000" }],
    ["v2", { id: "v2", name: "Beta", color: null }],
  ]),
  coaches: new Map<string, Coach>([["c1", { id: "c1", firstName: "Jean", lastName: "Paul" }]]),
};

describe("time helpers", () => {
  it("parses and formats", () => {
    expect(parseTimeToMinutes("18:00:00")).toBe(1080);
    expect(parseTimeToMinutes("18:30")).toBe(1110);
    // The API serializes TimeImmutable as an ISO datetime — the time must still parse.
    expect(parseTimeToMinutes("1970-01-01T18:30:00+00:00")).toBe(1110);
    expect(toHourMinute("1970-01-01T18:05:00+00:00")).toBe("18:05");
    expect(formatMinutes(1080)).toBe("18:00");
    expect(formatMinutes(1110)).toBe("18:30");
  });

  it("computes bounds floored/ceiled to the hour, with fallback", () => {
    expect(computeTimeBounds([])).toEqual({ startMin: 17 * 60, endMin: 21 * 60 });
    const bounds = computeTimeBounds([slot({ startTime: "18:15:00", durationMinutes: 90 })]);
    expect(bounds).toEqual({ startMin: 1080, endMin: 1200 });
  });
});

describe("resourceKeyForSlot", () => {
  const s = slot({ venueId: "v1", coachId: "c1", teamId: "t1" });
  it("maps per view", () => {
    expect(resourceKeyForSlot(s, "gymnase")).toBe("v1");
    expect(resourceKeyForSlot(s, "coach")).toBe("c1");
    expect(resourceKeyForSlot(s, "equipe")).toBe("t1");
  });
  it("buckets a coachless slot", () => {
    expect(resourceKeyForSlot(slot({ coachId: null }), "coach")).toBe(NO_COACH);
  });
});

describe("buildGrid", () => {
  const slots = [
    slot({ id: "a", venueId: "v1", teamId: "t1", dayOfWeek: 1, startTime: "18:00:00", durationMinutes: 90 }),
    slot({ id: "b", venueId: "v2", teamId: "t2", dayOfWeek: 1, startTime: "19:00:00", durationMinutes: 60 }),
  ];

  it("places slots into day/resource columns and time rows (gymnase)", () => {
    const model = buildGrid(slots, "gymnase", lookups);
    expect(model.resources.map((r) => r.label)).toEqual(["Alpha", "Beta"]);
    expect(model.rowLabels).toEqual(["18:00", "18:30", "19:00", "19:30"]);

    const a = model.cells.find((c) => c.slotId === "a")!;
    expect(a.gridColumn).toBe(2);
    expect(a.gridRowStart).toBe(3);
    expect(a.gridRowSpan).toBe(3);
    expect(a.venueColor).toBe("#ff0000");

    const b = model.cells.find((c) => c.slotId === "b")!;
    expect(b.gridColumn).toBe(3);
    expect(b.gridRowStart).toBe(5);
    expect(b.gridRowSpan).toBe(2);
  });

  it("re-groups the same slots when the view changes (equipe)", () => {
    const model = buildGrid(slots, "equipe", lookups);
    expect(model.resources.map((r) => r.label)).toEqual(["U11", "U13"]);
    expect(model.cells).toHaveLength(2);
  });

  it("drops slots outside Mon-Sat", () => {
    const model = buildGrid([slot({ id: "sun", dayOfWeek: 7 })], "gymnase", lookups);
    expect(model.cells).toHaveLength(0);
  });

  it("flags locked slots", () => {
    const model = buildGrid([slot({ id: "l", lockLevel: "HARD" })], "gymnase", lookups);
    expect(model.cells[0].locked).toBe(true);
  });
});
