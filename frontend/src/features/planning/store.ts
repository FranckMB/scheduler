import { create } from "zustand";
import { persist } from "zustand/middleware";

/** Which resource axis drives the grid sub-columns. Same data, different display. */
export type ViewMode = "gymnase" | "coach" | "equipe";

interface PlanningState {
  viewMode: ViewMode;
  selectedScheduleId: string | null;
  selectedSlotId: string | null;
  setViewMode: (viewMode: ViewMode) => void;
  setSelectedScheduleId: (id: string | null) => void;
  setSelectedSlotId: (id: string | null) => void;
}

export const usePlanningStore = create<PlanningState>()(
  persist(
    (set) => ({
      viewMode: "gymnase",
      selectedScheduleId: null,
      selectedSlotId: null,
      setViewMode: (viewMode) => set({ viewMode }),
      setSelectedScheduleId: (selectedScheduleId) => set({ selectedScheduleId, selectedSlotId: null }),
      setSelectedSlotId: (selectedSlotId) => set({ selectedSlotId }),
    }),
    {
      name: "cs-planning",
      version: 1,
      // Only the view preference is worth persisting; selections are per-session.
      partialize: (state) => ({ viewMode: state.viewMode }) as PlanningState,
      migrate: (persistedState) => {
        if (persistedState === null || typeof persistedState !== "object") {
          return { viewMode: "gymnase" } as PlanningState;
        }
        return persistedState as PlanningState;
      },
    },
  ),
);
