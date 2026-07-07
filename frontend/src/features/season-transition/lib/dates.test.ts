import { describe, expect, it } from "vitest";

import { NEXT_SEASON_SHIFT_DAYS, shiftIsoDays } from "./dates";

describe("shiftIsoDays", () => {
  it("shifts by +364 days keeping the weekday (52 weeks)", () => {
    // 2026-10-03 is a Saturday → 2027-10-02 is a Saturday too.
    const shifted = shiftIsoDays("2026-10-03", NEXT_SEASON_SHIFT_DAYS);
    expect(shifted).toBe("2027-10-02");
    expect(new Date(`${shifted}T00:00:00`).getDay()).toBe(new Date("2026-10-03T00:00:00").getDay());
  });

  it("crosses a leap day without drifting the weekday", () => {
    // 2027-09-04 (Saturday) + 364 → 2028-09-02; 2028 is a leap year.
    const shifted = shiftIsoDays("2027-09-04", NEXT_SEASON_SHIFT_DAYS);
    expect(shifted).toBe("2028-09-02");
    expect(new Date(`${shifted}T00:00:00`).getDay()).toBe(6);
  });

  it("handles month boundaries in local time", () => {
    expect(shiftIsoDays("2026-12-31", 1)).toBe("2027-01-01");
    expect(shiftIsoDays("2026-03-01", -1)).toBe("2026-02-28");
  });
});
