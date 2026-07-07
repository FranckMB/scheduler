import { useEffect } from "react";

import { useMe } from "@/features/auth/queries";
import { useThemeStore } from "@/shared/stores/themeStore";
import { accentForMode, readableForeground } from "@/shared/lib/color";

/**
 * Applies the club's accent colour (from /me) to the theme by overriding the
 * `--accent` CSS variables on the document root. Source of truth is the server,
 * not persisted locally (a club's accent can change and clubs differ). Reacts to
 * the dark/light mode so a dark accent stays legible on dark surfaces.
 */
export function useApplyClubTheme(): void {
  const { data: me } = useMe();
  const mode = useThemeStore((s) => s.mode);
  const accentLight = me?.club?.accentColor ?? null;
  const accentDark = me?.club?.accentColorDark ?? null;
  const palette = me?.club?.accentPalette ?? null;

  useEffect(() => {
    const root = document.documentElement;
    // Dark mode uses the club's explicit dark accent when set; otherwise it
    // falls back to deriving a dark-legible tone from the light accent (the
    // previous single-colour behaviour). Light mode always uses accentLight.
    const explicitDark = "dark" === mode && null !== accentDark;
    const base = "dark" === mode ? (accentDark ?? accentLight) : accentLight;
    if (null === base) {
      root.style.removeProperty("--accent");
      root.style.removeProperty("--accent-foreground");
      root.style.removeProperty("--accent-2");
      return;
    }
    // An explicit per-mode colour is honoured as chosen; a derived one is adjusted.
    const c = explicitDark ? base : accentForMode(base, mode);
    root.style.setProperty("--accent", c);
    root.style.setProperty("--accent-foreground", readableForeground(c));
    // Secondary tint (from the logo palette) for signature surfaces later.
    const second = palette?.[1];
    if (undefined !== second) {
      root.style.setProperty("--accent-2", accentForMode(second, mode));
    } else {
      root.style.removeProperty("--accent-2");
    }
  }, [accentLight, accentDark, palette, mode]);
}
