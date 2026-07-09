import { create } from "zustand";
import { persist } from "zustand/middleware";

export type ThemeMode = "dark" | "light";

/** zustand-persist storage key — shared so pre-paint init + tests don't hardcode it. */
export const THEME_STORAGE_KEY = "cs-theme";

/**
 * Reads the persisted theme mode BEFORE the store hydrates, for the pre-paint
 * `.dark` init in main.tsx (avoids a flash of the wrong theme + a sub-AA
 * transition). Normalises exactly like the store + useApplyTheme: absent or
 * unparseable → the store default (dark); any value that isn't "dark" → light.
 */
export function readPersistedThemeMode(): ThemeMode {
  try {
    const raw = localStorage.getItem(THEME_STORAGE_KEY);
    if (null === raw) {
      return "dark";
    }
    return "dark" === (JSON.parse(raw) as { state?: { mode?: unknown } })?.state?.mode ? "dark" : "light";
  } catch {
    return "dark";
  }
}

interface ThemeState {
  mode: ThemeMode;
  /** Club accent override (CSS color string, e.g. oklch/hex). null = default neutral accent. */
  accent: string | null;
  setMode: (mode: ThemeMode) => void;
  toggleMode: () => void;
  setAccent: (accent: string | null) => void;
}

export const useThemeStore = create<ThemeState>()(
  persist(
    (set) => ({
      mode: "dark",
      accent: null,
      setMode: (mode) => set({ mode }),
      toggleMode: () => set((state) => ({ mode: state.mode === "dark" ? "light" : "dark" })),
      setAccent: (accent) => set({ accent }),
    }),
    {
      name: "cs-theme",
      version: 1,
      migrate: (persistedState) => {
        if (persistedState === null || typeof persistedState !== "object") {
          return { mode: "dark", accent: null } as ThemeState;
        }
        return persistedState as ThemeState;
      },
    },
  ),
);
