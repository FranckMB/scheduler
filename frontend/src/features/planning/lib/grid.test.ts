import { describe, expect, it } from "vitest";

import type { Coach, Slot, Team, Venue } from "../api";
import { availableResources, buildGrid, computeTimeBounds, concernedSlots, formatMinutes, type Lookups, NO_COACH, parseTimeToMinutes, resourceKeysForSlot, toHourMinute } from "./grid";

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
  coaches: new Map<string, Coach>([
    ["c1", { id: "c1", firstName: "Jean", lastName: "Paul" }],
    ["c9", { id: "c9", firstName: "Team", lastName: "Coach" }],
  ]),
  teamCoach: new Map<string, string>(),
  teamPlayerCoaches: new Map<string, string[]>(),
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

describe("resourceKeysForSlot", () => {
  const s = slot({ venueId: "v1", coachId: "c1", teamId: "t1" });
  it("maps per view (single key)", () => {
    expect(resourceKeysForSlot(s, "gymnase", lookups)).toEqual(["v1"]);
    expect(resourceKeysForSlot(s, "coach", lookups)).toEqual(["c1"]);
    expect(resourceKeysForSlot(s, "equipe", lookups)).toEqual(["t1"]);
  });
  it("buckets a coachless slot with no team coach", () => {
    expect(resourceKeysForSlot(slot({ coachId: null }), "coach", lookups)).toEqual([NO_COACH]);
  });
  it("falls back to the team's main coach when the slot has none", () => {
    const withTeamCoach = { ...lookups, teamCoach: new Map([["t1", "c9"]]) };
    expect(resourceKeysForSlot(slot({ coachId: null, teamId: "t1" }), "coach", withTeamCoach)).toEqual(["c9"]);
  });
  it("also surfaces the coach under teams where he is a player", () => {
    const withPlayers = { ...lookups, teamCoach: new Map([["t1", "c9"]]), teamPlayerCoaches: new Map([["t1", ["p1"]]]) };
    expect(resourceKeysForSlot(slot({ coachId: null, teamId: "t1" }), "coach", withPlayers).sort()).toEqual(["c9", "p1"]);
  });
});

describe("buildGrid", () => {
  const slots = [
    slot({ id: "a", venueId: "v1", teamId: "t1", dayOfWeek: 1, startTime: "18:00:00", durationMinutes: 90 }),
    slot({ id: "b", venueId: "v2", teamId: "t2", dayOfWeek: 1, startTime: "19:00:00", durationMinutes: 60 }),
  ];

  it("places slots into day/resource columns and 15-min rows (gymnase)", () => {
    const model = buildGrid(slots, "gymnase", lookups);
    expect(model.columns.map((c) => c.label)).toEqual(["Alpha", "Beta"]);
    expect(model.dayGroups).toEqual([{ day: 1, label: "Lun", startColumn: 2, span: 2 }]);
    // 18:00→20:00 (b ends 20:00) at 15-min steps, labels only on the half hour.
    expect(model.rows.map((r) => r.label)).toEqual(["18:00", null, "18:30", null, "19:00", null, "19:30", null]);

    const a = model.cells.find((c) => c.slotId === "a")!;
    expect(a.gridColumn).toBe(2);
    expect(a.gridRowStart).toBe(3);
    expect(a.gridRowSpan).toBe(6); // 90min / 15
    expect(a.venueColor).toBe("#ff0000");

    const b = model.cells.find((c) => c.slotId === "b")!;
    expect(b.gridColumn).toBe(3);
    expect(b.gridRowStart).toBe(7); // 19:00 is 4 rows after 18:00
    expect(b.gridRowSpan).toBe(4); // 60min / 15
  });

  it("distinguishes quarter-hour starts/durations (real placement)", () => {
    const model = buildGrid(
      [
        slot({ id: "u21", startTime: "20:15:00", durationMinutes: 135 }),
        slot({ id: "indiv", startTime: "20:30:00", durationMinutes: 120 }),
      ],
      "gymnase",
      lookups,
    );
    const u21 = model.cells.find((c) => c.slotId === "u21")!;
    const indiv = model.cells.find((c) => c.slotId === "indiv")!;
    // Same end (22:30) but different starts → different heights.
    expect(u21.gridRowSpan).toBe(9);
    expect(indiv.gridRowSpan).toBe(8);
    expect(u21.gridRowStart).toBe(indiv.gridRowStart - 1);
  });

  it("re-groups the same slots when the view changes (equipe)", () => {
    const model = buildGrid(slots, "equipe", lookups);
    expect(model.columns.map((c) => c.label)).toEqual(["U11", "U13"]);
    expect(model.cells).toHaveLength(2);
  });

  it("hides empty columns and applies the resource filter", () => {
    const filtered = buildGrid(slots, "gymnase", lookups, new Set(["v1"]));
    expect(filtered.columns.map((c) => c.label)).toEqual(["Alpha"]);
    expect(filtered.cells).toHaveLength(1);
  });

  it("drops slots outside Mon-Sat", () => {
    const model = buildGrid([slot({ id: "sun", dayOfWeek: 7 })], "gymnase", lookups);
    expect(model.columns).toHaveLength(0);
    expect(model.cells).toHaveLength(0);
  });

  it("coach filter shows the slot only under the selected coach (no co-player columns)", () => {
    // Slot's team is coached by c9 and has player-coaches p1, p2 → 3 possible columns.
    const withCoaches = {
      ...lookups,
      teamCoach: new Map([["t1", "c9"]]),
      teamPlayerCoaches: new Map([["t1", ["p1", "p2"]]]),
    };
    const s = slot({ id: "sf2", coachId: null, teamId: "t1", dayOfWeek: 1 });
    expect(buildGrid([s], "coach", withCoaches).columns).toHaveLength(3);
    // Focused on c9: only c9's column, one cell.
    const focused = buildGrid([s], "coach", withCoaches, new Set(["c9"]));
    expect(focused.columns.map((c) => c.resourceId)).toEqual(["c9"]);
    expect(focused.cells).toHaveLength(1);
  });

  it("flags locked slots", () => {
    const model = buildGrid([slot({ id: "l", lockLevel: "HARD" })], "gymnase", lookups);
    expect(model.cells[0].locked).toBe(true);
  });

  it("lays time-overlapping slots of a column into side-by-side lanes", () => {
    const model = buildGrid(
      [
        slot({ id: "o1", venueId: "v1", dayOfWeek: 1, startTime: "18:00:00", durationMinutes: 60 }),
        slot({ id: "o2", venueId: "v1", dayOfWeek: 1, startTime: "18:30:00", durationMinutes: 60 }),
      ],
      "gymnase",
      lookups,
    );
    const o1 = model.cells.find((c) => c.slotId === "o1")!;
    const o2 = model.cells.find((c) => c.slotId === "o2")!;
    expect(o1.gridColumn).toBe(o2.gridColumn);
    expect(o1.laneCount).toBe(2);
    expect(o2.laneCount).toBe(2);
    expect(new Set([o1.lane, o2.lane])).toEqual(new Set([0, 1]));
  });

  it("keeps non-overlapping slots in a single lane", () => {
    const model = buildGrid(
      [
        slot({ id: "n1", venueId: "v1", dayOfWeek: 1, startTime: "18:00:00", durationMinutes: 60 }),
        slot({ id: "n2", venueId: "v1", dayOfWeek: 1, startTime: "19:00:00", durationMinutes: 60 }),
      ],
      "gymnase",
      lookups,
    );
    expect(model.cells.every((c) => c.laneCount === 1 && c.lane === 0)).toBe(true);
  });
});

describe("availableResources", () => {
  it("lists distinct resources for the view, sorted", () => {
    const slots = [slot({ venueId: "v2" }), slot({ venueId: "v1" }), slot({ venueId: "v1" })];
    expect(availableResources(slots, "gymnase", lookups)).toEqual([
      { id: "v1", label: "Alpha" },
      { id: "v2", label: "Beta" },
    ]);
  });
});

describe("concernedSlots", () => {
  it("lists the slots a venue conflict points at, with day + time + team", () => {
    const slots = [
      slot({ id: "x", venueId: "v1", teamId: "t1", dayOfWeek: 1, startTime: "18:00:00" }),
      slot({ id: "y", venueId: "v1", teamId: "t2", dayOfWeek: 1, startTime: "18:00:00" }),
      slot({ id: "z", venueId: "v2", teamId: "t1", dayOfWeek: 2, startTime: "19:00:00" }),
    ];
    const result = concernedSlots({ teamId: null, venueId: "v1", coachId: null }, slots, lookups);
    expect(result.map((r) => r.slotId)).toEqual(["x", "y"]);
    expect(result[0]).toMatchObject({ dayLabel: "Lun", timeLabel: "18:00", teamLabel: "U11", venueLabel: "Alpha" });
  });
});
