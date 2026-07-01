import { create } from "zustand";
import { persist } from "zustand/middleware";

export type ThemeMode = "dark" | "light";

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
