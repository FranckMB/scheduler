import { describe, expect, it } from "vitest";

import { buildMonthGrid, daysUntil, isWithin, monthWindow, toISODate } from "./date";

describe("cockpit date utils", () => {
  it("builds a 42-cell Monday-first grid", () => {
    // May 2026: 1 May is a Friday.
    const grid = buildMonthGrid(2026, 4);
    expect(grid).toHaveLength(42);
    // First cell is the Monday on/before 1 May 2026 → Mon 27 Apr 2026.
    expect(grid[0].iso).toBe("2026-04-27");
    expect(grid[0].inMonth).toBe(false);
    // 1 May sits at index 4 (Fri).
    const may1 = grid.find((c) => c.iso === "2026-05-01");
    expect(may1?.inMonth).toBe(true);
  });

  it("handles a leap February", () => {
    const grid = buildMonthGrid(2028, 1); // Feb 2028 (leap)
    const feb29 = grid.find((c) => c.iso === "2028-02-29");
    expect(feb29?.inMonth).toBe(true);
  });

  it("does not shift dates across timezones", () => {
    expect(toISODate(new Date(2026, 0, 1))).toBe("2026-01-01");
    expect(toISODate(new Date(2026, 11, 31))).toBe("2026-12-31");
  });

  it("computes the month window from grid bounds", () => {
    const { from, to } = monthWindow(2026, 4);
    expect(from).toBe("2026-04-27");
    expect(to <= "2026-06-08").toBe(true);
    expect(from < to).toBe(true);
  });

  it("checks inclusive range membership", () => {
    expect(isWithin("2026-05-04", "2026-05-04", "2026-05-10")).toBe(true);
    expect(isWithin("2026-05-10", "2026-05-04", "2026-05-10")).toBe(true);
    expect(isWithin("2026-05-11", "2026-05-04", "2026-05-10")).toBe(false);
  });

  it("counts whole days until", () => {
    expect(daysUntil("2026-05-01", "2026-05-25")).toBe(24);
    expect(daysUntil("2026-05-25", "2026-05-01")).toBe(-24);
    expect(daysUntil("2026-05-01", "2026-05-01")).toBe(0);
  });
});
