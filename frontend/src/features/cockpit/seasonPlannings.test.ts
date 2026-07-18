import { describe, expect, it } from "vitest";

import type { Schedule } from "@/features/planning/api";

import { seasonPlannings } from "./seasonPlannings";

const s = (over: Partial<Schedule>): Schedule => ({ id: "id", name: "Plan", status: "COMPLETED", score: null, createdAt: "2026-07-01T10:00:00+00:00", updatedAt: "", planType: "SEASON", schedulePlanId: "season-plan", ...over });

describe("seasonPlannings — open plannings & plan name (founder feedback 2026-07-18)", () => {
  it("labels the season row with the plan's real name when provided", () => {
    const rows = seasonPlannings([s({ id: "v1" })], "Planning de la saison 2026-2027");
    expect(rows[0].label).toBe("Planning de la saison 2026-2027");
  });

  it("falls back to « Planning principal » without a plan name", () => {
    expect(seasonPlannings([s({ id: "v1" })])[0].label).toBe("Planning principal");
  });

  it("lists an overlay with NO finished version as an OPEN row on its latest version", () => {
    const rows = seasonPlannings([
      s({ id: "o1", name: "Vacances Noël", status: "PENDING", planType: "CLOSURE", schedulePlanId: "p2", createdAt: "2026-07-03T10:00:00+00:00" }),
      s({ id: "o2", name: "Vacances Noël", status: "GENERATING", planType: "CLOSURE", schedulePlanId: "p2", createdAt: "2026-07-04T10:00:00+00:00" }),
    ]);
    expect(rows).toHaveLength(1);
    expect(rows[0]).toMatchObject({ id: "o2", isOpen: true, isOverlay: true, schedulePlanId: "p2" });
  });

  it("a planning with a finished version stays a closed row (isOpen false), even with newer in-flight versions", () => {
    const rows = seasonPlannings([
      s({ id: "v1", status: "COMPLETED" }),
      s({ id: "v2", status: "GENERATING", createdAt: "2026-07-05T10:00:00+00:00" }),
    ]);
    expect(rows).toHaveLength(1);
    expect(rows[0]).toMatchObject({ id: "v1", isOpen: false });
  });
});
