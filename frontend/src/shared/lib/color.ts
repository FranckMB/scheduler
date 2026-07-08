/** Small colour helpers for the per-club accent (hex in → CSS values out). */

type Rgb = { r: number; g: number; b: number };

const HEX = /^#([0-9a-fA-F]{6})$/;

export function parseHex(hex: string): Rgb | null {
  const m = HEX.exec(hex.trim());
  if (null === m) {
    return null;
  }
  const n = Number.parseInt(m[1], 16);
  return { r: (n >> 16) & 0xff, g: (n >> 8) & 0xff, b: n & 0xff };
}

function toHex({ r, g, b }: Rgb): string {
  const c = (v: number) => Math.max(0, Math.min(255, Math.round(v))).toString(16).padStart(2, "0");
  return `#${c(r)}${c(g)}${c(b)}`;
}

/** WCAG relative luminance (0 = black, 1 = white). */
export function luminance(hex: string): number {
  const rgb = parseHex(hex);
  if (null === rgb) {
    return 0;
  }
  const lin = (v: number) => {
    const c = v / 255;
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
  };
  return 0.2126 * lin(rgb.r) + 0.7152 * lin(rgb.g) + 0.0722 * lin(rgb.b);
}

/** AA-ish foreground (near-black or white) to place text on top of `hex`. */
export function readableForeground(hex: string): string {
  return luminance(hex) > 0.42 ? "#0b0b0c" : "#ffffff";
}

/** Mix a colour toward white (amount 0..1) — used to lift a dark accent in dark mode. */
export function lighten(hex: string, amount: number): string {
  const rgb = parseHex(hex);
  if (null === rgb) {
    return hex;
  }
  return toHex({
    r: rgb.r + (255 - rgb.r) * amount,
    g: rgb.g + (255 - rgb.g) * amount,
    b: rgb.b + (255 - rgb.b) * amount,
  });
}

/**
 * Accent adjusted for a theme mode: in dark mode a very dark club colour is
 * lifted a bit so it stays legible on dark surfaces; otherwise used as-is.
 */
export function accentForMode(hex: string, mode: "dark" | "light"): string {
  if ("dark" === mode && luminance(hex) < 0.22) {
    return lighten(hex, 0.35);
  }
  return hex;
}

/**
 * Distinguishable venue colours ordered as a rainbow (no black/white/grey) so a
 * freshly created gym gets a vivid default instead of a flat grey. Vivid enough
 * to tell two gyms apart on the planning grid, none so dark it needs lifting.
 */
export const VENUE_PALETTE = [
  "#E6194B", // red
  "#F58231", // orange
  "#FFD21E", // yellow
  "#BFEF45", // lime
  "#3CB44B", // green
  "#469990", // teal
  "#42D4F4", // cyan
  "#4363D8", // blue
  "#911EB4", // purple
  "#F032E6", // magenta
  "#FA5A8C", // pink
  "#9A6324", // brown
] as const;

/**
 * Next default colour for a new venue: the first palette hue not already used,
 * so gyms stay visually distinct; once the palette is exhausted it cycles by
 * count. Comparison is case-insensitive on the hex string.
 */
export function nextVenueColor(usedColors: readonly (string | null)[]): string {
  const used = new Set(usedColors.filter((c): c is string => null !== c).map((c) => c.toLowerCase()));
  const free = VENUE_PALETTE.find((c) => !used.has(c.toLowerCase()));
  return free ?? VENUE_PALETTE[used.size % VENUE_PALETTE.length];
}
