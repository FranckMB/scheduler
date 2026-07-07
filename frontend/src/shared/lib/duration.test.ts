import { describe, expect, it } from "vitest";

import { formatDuration } from "./duration";

describe("formatDuration", () => {
  it("keeps sub-hour durations in minutes", () => {
    expect(formatDuration(30)).toBe("30 min");
    expect(formatDuration(45)).toBe("45 min");
  });

  it("renders whole hours without minutes", () => {
    expect(formatDuration(60)).toBe("1h");
    expect(formatDuration(120)).toBe("2h");
  });

  it("zero-pads the minutes part past the hour", () => {
    expect(formatDuration(75)).toBe("1h15");
    expect(formatDuration(90)).toBe("1h30");
    expect(formatDuration(105)).toBe("1h45");
    expect(formatDuration(135)).toBe("2h15");
  });
});
