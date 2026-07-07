import { describe, expect, it } from "vitest";

import { pickDefaultSchedule, pickLandingScheduleId } from "./PlanningPage";

type S = { id: string; status: string; createdAt: string; calendarEntryId: string | null };

const seasonPlan = (id: string, status = "COMPLETED", createdAt = "2026-01-01T00:00:00Z"): S => ({ id, status, createdAt, calendarEntryId: null });
const overlay = (id: string, status = "COMPLETED", createdAt = "2026-02-01T00:00:00Z"): S => ({ id, status, createdAt, calendarEntryId: "entry-1" });

describe("pickLandingScheduleId (UX-02)", () => {
  it("opens on the baseline when it is a finished season plan", () => {
    const schedules = [seasonPlan("base"), seasonPlan("other", "COMPLETED", "2025-12-01T00:00:00Z")];
    expect(pickLandingScheduleId(schedules, "base")).toBe("base");
  });

  it("NEVER opens on an overlay baseline — falls back to a real season plan", () => {
    // The bug: baselineScheduleId points to a period overlay (empty "★ · période").
    const schedules = [overlay("ov-baseline"), seasonPlan("real-plan")];
    expect(pickLandingScheduleId(schedules, "ov-baseline")).toBe("real-plan");
  });

  it("skips a mid-flight baseline and lands on the latest finished season plan", () => {
    const schedules = [seasonPlan("regenerating", "GENERATING"), seasonPlan("ready", "COMPLETED", "2025-11-01T00:00:00Z")];
    expect(pickLandingScheduleId(schedules, "regenerating")).toBe("ready");
  });

  it("with no baseline, lands on the most recent finished season plan", () => {
    const schedules = [seasonPlan("old", "COMPLETED", "2025-01-01T00:00:00Z"), seasonPlan("new", "COMPLETED", "2026-06-01T00:00:00Z")];
    expect(pickLandingScheduleId(schedules, null)).toBe("new");
  });

  it("pickDefaultSchedule ignores overlays entirely", () => {
    const schedules = [overlay("ov"), seasonPlan("plan")];
    expect(pickDefaultSchedule(schedules)).toBe("plan");
    expect(pickDefaultSchedule([overlay("only-overlay")])).toBeNull();
  });
});
