import { useEffect } from "react";

import { useThemeStore } from "@/shared/stores/themeStore";

/** Applies the theme mode (.dark class) and the optional club accent to <html>. */
export function useApplyTheme(): void {
  const mode = useThemeStore((state) => state.mode);
  const accent = useThemeStore((state) => state.accent);

  useEffect(() => {
    const root = document.documentElement;
    root.classList.toggle("dark", mode === "dark");
    if (accent) {
      root.style.setProperty("--accent", accent);
    } else {
      root.style.removeProperty("--accent");
    }
  }, [mode, accent]);
}
