import { describe, expect, it } from "vitest";

import type { Fixture, Team, Venue } from "../api";
import { buildWeekendGrid, isPlacedOnGrid, listWeekends, weekendKeyOf } from "./weekendGrid";

const fixture = (over: Partial<Fixture> = {}): Fixture => ({
  id: "fx-1",
  teamId: "team-1",
  seasonId: "s",
  competitionId: null,
  matchDate: "2026-10-03",
  homeAway: "HOME",
  opponentLabel: "Adv",
  status: "PLACED",
  venueId: "venue-1",
  kickoffTime: "16:00",
  externalRef: null,
  ...over,
});

const venues = new Map<string, Venue>([["venue-1", { id: "venue-1", name: "Gymnase Alpha", color: "#00aa00" }]]);
const teams = new Map<string, Team>([["team-1", { id: "team-1", name: "U13", sportCategoryId: "cat", level: null, gender: null, priorityTierId: 3, tierOrder: 0 }]]);

describe("weekendKeyOf", () => {
  it("buckets Saturday and its Sunday into the same weekend (the Saturday)", () => {
    expect(weekendKeyOf("2026-10-03")).toBe("2026-10-03"); // Saturday
    expect(weekendKeyOf("2026-10-04")).toBe("2026-10-03"); // Sunday → same weekend
  });
});

describe("isPlacedOnGrid", () => {
  it("is true only for home fixtures with venue + kickoff", () => {
    expect(isPlacedOnGrid(fixture())).toBe(true);
    expect(isPlacedOnGrid(fixture({ kickoffTime: null }))).toBe(false);
    expect(isPlacedOnGrid(fixture({ venueId: null }))).toBe(false);
    expect(isPlacedOnGrid(fixture({ homeAway: "AWAY" }))).toBe(false);
  });
});

describe("listWeekends", () => {
  it("returns sorted distinct weekend buckets", () => {
    const list = listWeekends([fixture(), fixture({ id: "fx-2", matchDate: "2026-10-04" }), fixture({ id: "fx-3", matchDate: "2026-10-10" })]);
    expect(list).toEqual(["2026-10-03", "2026-10-10"]);
  });
});

describe("buildWeekendGrid", () => {
  it("is empty when no home fixture is placed", () => {
    const grid = buildWeekendGrid([fixture({ kickoffTime: null })], venues, teams);
    expect(grid.empty).toBe(true);
    expect(grid.cells).toHaveLength(0);
  });

  it("lays a placed match as a 2h15 footprint block in its date×venue column", () => {
    const grid = buildWeekendGrid([fixture()], venues, teams);
    expect(grid.empty).toBe(false);
    expect(grid.columns).toHaveLength(1);
    expect(grid.dateGroups[0].dateKey).toBe("2026-10-03");
    expect(grid.cells).toHaveLength(1);
    const cell = grid.cells[0];
    // 16:00 kickoff → footprint 15:30–17:45 = 135 min = 9 steps of 15 min.
    expect(cell.kickoffLabel).toBe("16:00");
    expect(cell.footprintLabel).toBe("15:30–17:45");
    expect(cell.gridRowSpan).toBe(9);
    expect(cell.outOfEnvelope).toBe(false);
  });

  it("marks a fixture flagged out of envelope", () => {
    const grid = buildWeekendGrid([fixture()], venues, teams, new Set(["fx-1"]));
    expect(grid.cells[0].outOfEnvelope).toBe(true);
  });

  it("puts two overlapping matches of the same venue in separate lanes", () => {
    const grid = buildWeekendGrid([fixture(), fixture({ id: "fx-2", kickoffTime: "16:30", opponentLabel: "Adv2" })], venues, teams);
    expect(grid.cells).toHaveLength(2);
    expect(grid.cells.map((c) => c.laneCount)).toEqual([2, 2]);
    expect(new Set(grid.cells.map((c) => c.lane))).toEqual(new Set([0, 1]));
  });
});
