import { describe, expect, it } from "vitest";

import { nextVenueColor, VENUE_PALETTE } from "./color";

describe("nextVenueColor", () => {
  it("returns the first palette hue when nothing is used", () => {
    expect(nextVenueColor([])).toBe(VENUE_PALETTE[0]);
  });

  it("skips already-used colours (case-insensitive)", () => {
    expect(nextVenueColor([VENUE_PALETTE[0].toLowerCase()])).toBe(VENUE_PALETTE[1]);
    expect(nextVenueColor([VENUE_PALETTE[0], VENUE_PALETTE[1]])).toBe(VENUE_PALETTE[2]);
  });

  it("ignores null entries", () => {
    expect(nextVenueColor([null, null])).toBe(VENUE_PALETTE[0]);
  });

  it("cycles once the palette is exhausted", () => {
    const all = [...VENUE_PALETTE];
    expect(nextVenueColor(all)).toBe(VENUE_PALETTE[0]);
  });

  it("never returns black or white", () => {
    for (const c of VENUE_PALETTE) {
      expect(c.toLowerCase()).not.toBe("#000000");
      expect(c.toLowerCase()).not.toBe("#ffffff");
    }
  });
});
