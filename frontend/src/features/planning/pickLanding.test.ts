import { describe, expect, it } from "vitest";

import { pickDefaultSchedule, pickLandingScheduleId } from "./lib/pickLandingSchedule";

type S = { id: string; status: string; createdAt: string; calendarEntryId: string | null; isChosen?: boolean };

// « En vigueur » se lit sur la version elle-même (isChosen) — plus de pointeur passé
// de l'extérieur, donc plus de risque qu'il désigne autre chose que ce qu'on scanne.
const seasonPlan = (id: string, status = "COMPLETED", createdAt = "2026-01-01T00:00:00Z", isChosen = false): S => ({ id, status, createdAt, calendarEntryId: null, isChosen });
const overlay = (id: string, status = "COMPLETED", createdAt = "2026-02-01T00:00:00Z", isChosen = false): S => ({ id, status, createdAt, calendarEntryId: "entry-1", isChosen });

describe("pickLandingScheduleId (UX-02)", () => {
  it("opens on the version in force when it is a finished season plan", () => {
    const schedules = [seasonPlan("base", "COMPLETED", "2026-01-01T00:00:00Z", true), seasonPlan("other", "COMPLETED", "2025-12-01T00:00:00Z")];
    expect(pickLandingScheduleId(schedules)).toBe("base");
  });

  it("NEVER opens on an overlay — falls back to a real season plan", () => {
    // The bug: the pointer names a period overlay (empty "★ · période").
    const schedules = [overlay("ov-chosen", "COMPLETED", "2026-02-01T00:00:00Z", true), seasonPlan("real-plan")];
    expect(pickLandingScheduleId(schedules)).toBe("real-plan");
  });

  it("skips a mid-flight chosen version and lands on the latest finished season plan", () => {
    const schedules = [seasonPlan("regenerating", "GENERATING", "2026-01-01T00:00:00Z", true), seasonPlan("ready", "COMPLETED", "2025-11-01T00:00:00Z")];
    expect(pickLandingScheduleId(schedules)).toBe("ready");
  });

  it("with nothing in force, lands on the most recent finished season plan", () => {
    const schedules = [seasonPlan("old", "COMPLETED", "2025-01-01T00:00:00Z"), seasonPlan("new", "COMPLETED", "2026-06-01T00:00:00Z")];
    expect(pickLandingScheduleId(schedules)).toBe("new");
  });

  it("pickDefaultSchedule ignores overlays entirely", () => {
    const schedules = [overlay("ov"), seasonPlan("plan")];
    expect(pickDefaultSchedule(schedules)).toBe("plan");
    expect(pickDefaultSchedule([overlay("only-overlay")])).toBeNull();
  });
});
