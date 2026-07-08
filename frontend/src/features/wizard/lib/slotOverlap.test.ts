import { describe, expect, it } from "vitest";

import type { VenueTrainingSlot } from "../api";
import { conflictMessage, findSlotConflict, toMinutes } from "./slotOverlap";

const slot = (over: Partial<VenueTrainingSlot>): VenueTrainingSlot =>
  ({ id: "s", venueId: "v", dayOfWeek: 1, startTime: "17:00", durationMinutes: 90, capacity: 1, ...over }) as VenueTrainingSlot;

describe("findSlotConflict", () => {
  const existing = [slot({ id: "a", dayOfWeek: 1, startTime: "17:00", durationMinutes: 90 })]; // Mon 17:00–18:30

  it("flags a slot that starts before and ends inside the window", () => {
    // Mon 16:30–18:00 overlaps 17:00–18:30.
    expect(findSlotConflict(existing, 1, "16:30", 90)?.id).toBe("a");
  });

  it("flags a slot fully contained in the window", () => {
    expect(findSlotConflict(existing, 1, "17:30", 30)?.id).toBe("a");
  });

  it("does not flag a back-to-back slot (touching edges)", () => {
    // 18:30 starts exactly when the other ends — no shared time.
    expect(findSlotConflict(existing, 1, "18:30", 60)).toBeNull();
    expect(findSlotConflict(existing, 1, "15:30", 90)).toBeNull(); // ends 17:00
  });

  it("does not flag a slot on a different weekday", () => {
    expect(findSlotConflict(existing, 2, "17:00", 90)).toBeNull();
  });

  it("ignores the slot being edited (already excluded by caller)", () => {
    // When editing 'a' itself, the caller passes an empty 'others' list.
    expect(findSlotConflict([], 1, "17:00", 90)).toBeNull();
  });
});

describe("conflictMessage / toMinutes", () => {
  it("names the conflicting slot's day + window", () => {
    expect(conflictMessage(slot({ dayOfWeek: 1, startTime: "17:00", durationMinutes: 90 }))).toContain("17:00–18:30");
  });

  it("converts HH:MM to minutes", () => {
    expect(toMinutes("18:30")).toBe(18 * 60 + 30);
  });
});
