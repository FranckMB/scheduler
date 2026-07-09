import { describe, expect, it } from "vitest";

import { luminance, nextVenueColor, readableForeground, VENUE_PALETTE } from "./color";

const contrast = (a: string, b: string): number => {
  const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
  return (hi + 0.05) / (lo + 0.05);
};

describe("readableForeground", () => {
  // Every club accent — including the mid-tones that fooled the old 0.42
  // threshold — must pair with an AA foreground (A11Y-06). Sweep the value axis.
  it("always yields a WCAG-AA foreground on the accent", () => {
    for (let l = 0; l <= 255; l += 5) {
      for (const hex of [`#${l.toString(16).padStart(2, "0").repeat(3)}`, "#3280dc", "#4d96f0", "#b45309"]) {
        expect(contrast(hex, readableForeground(hex)), `${hex} paired with ${readableForeground(hex)}`).toBeGreaterThanOrEqual(4.5);
      }
    }
  });
});

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
