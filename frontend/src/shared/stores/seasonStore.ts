import { create } from "zustand";
import { persist } from "zustand/middleware";

/**
 * Season the manager is WORKING IN. null = follow the server-derived current
 * season (no X-Season-Id header sent — the default, and the only state a
 * mono-season club ever has). A non-null id rides every API call as
 * X-Season-Id (see shared/api/client.ts) and is validated server-side.
 */
interface SeasonState {
  selectedSeasonId: string | null;
  setSelectedSeasonId: (id: string | null) => void;
  clear: () => void;
}

export const useSeasonStore = create<SeasonState>()(
  persist(
    (set) => ({
      selectedSeasonId: null,
      setSelectedSeasonId: (selectedSeasonId) => set({ selectedSeasonId }),
      clear: () => set({ selectedSeasonId: null }),
    }),
    {
      name: "cs-season",
      version: 1,
      // Zustand 5: persistedState may be null — null-check before use (anti-pattern #3).
      migrate: (persistedState) => {
        if (persistedState === null || typeof persistedState !== "object") {
          return { selectedSeasonId: null } as SeasonState;
        }
        return persistedState as SeasonState;
      },
    },
  ),
);
