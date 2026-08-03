import { describe, expect, it } from "vitest";

import type { TeamMatchHabit } from "../api";
import { buildTypicalWeekend } from "./typicalWeekend";

const habit = (over: Partial<TeamMatchHabit> = {}): TeamMatchHabit => ({
  id: "h-1",
  teamId: "team-1",
  dayOfWeek: 6,
  kickoffTime: "15:30",
  venueId: "venue-1",
  ...over,
});

describe("buildTypicalWeekend (P1-4 PR E2)", () => {
  it("lays a venue-anchored habit as a 2h15 footprint in its day×venue column", () => {
    const model = buildTypicalWeekend([habit()]);
    expect(model.empty).toBe(false);
    expect(model.columns).toEqual([{ key: "6:venue-1", dayOfWeek: 6, venueId: "venue-1" }]);
    expect(model.blocks[0]).toMatchObject({ startMin: 15 * 60, endMin: 17 * 60 + 45, kickoff: "15:30" });
  });

  it("keeps only weekend habits and lists venue-less ones apart", () => {
    const model = buildTypicalWeekend([
      habit(),
      habit({ id: "h-2", teamId: "team-2", dayOfWeek: 3 }), // Wednesday: not a weekend habit
      habit({ id: "h-3", teamId: "team-3", dayOfWeek: 7, venueId: null }),
    ]);
    expect(model.blocks).toHaveLength(1);
    expect(model.venueless.map((h) => h.id)).toEqual(["h-3"]);
  });

  it("lanes overlapping habits of the same column side by side (a template collision must be SEEN)", () => {
    const model = buildTypicalWeekend([habit(), habit({ id: "h-2", teamId: "team-2", kickoffTime: "16:00" })]);
    const lanes = model.blocks.map((b) => b.lane).sort();
    expect(lanes).toEqual([0, 1]);
    expect(model.blocks.every((b) => 2 === b.laneCount)).toBe(true);
  });

  it("is empty only when NO weekend habit exists at all", () => {
    expect(buildTypicalWeekend([]).empty).toBe(true);
    expect(buildTypicalWeekend([habit({ venueId: null })]).empty).toBe(false); // venue-less still worth showing
  });
});
