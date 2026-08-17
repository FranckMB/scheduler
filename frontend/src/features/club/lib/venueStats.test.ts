import { describe, expect, it } from "vitest";

import { formatHours } from "./venueStats";

describe("formatHours", () => {
  it("une décimale seulement quand elle existe, virgule française", () => {
    expect(formatHours(4)).toBe("4 h");
    expect(formatHours(7.5)).toBe("7,5 h");
    expect(formatHours(7.54)).toBe("7,5 h");
  });
});
