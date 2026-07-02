import { useEffect } from "react";

import { useThemeStore } from "@/shared/stores/themeStore";

/**
 * Applies the theme mode (.dark class) to <html>. The club accent is applied
 * separately by useApplyClubTheme (from /me) — this hook must NOT touch
 * `--accent`, otherwise it wipes the club accent on every mode toggle.
 */
export function useApplyTheme(): void {
  const mode = useThemeStore((state) => state.mode);

  useEffect(() => {
    document.documentElement.classList.toggle("dark", mode === "dark");
  }, [mode]);
}
