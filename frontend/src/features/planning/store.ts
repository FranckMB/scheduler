import { create } from "zustand";
import { persist } from "zustand/middleware";

/** Which resource axis drives the grid sub-columns. Same data, different display. */
export type ViewMode = "gymnase" | "coach" | "equipe";

interface PlanningState {
  viewMode: ViewMode;
  selectedScheduleId: string | null;
  selectedSlotId: string | null;
  /** Resource ids to show for the current view; empty = show all used resources. */
  resourceFilter: string[];
  setViewMode: (viewMode: ViewMode) => void;
  setSelectedScheduleId: (id: string | null) => void;
  setSelectedSlotId: (id: string | null) => void;
  toggleResource: (id: string) => void;
  clearResourceFilter: () => void;
}

export const usePlanningStore = create<PlanningState>()(
  persist(
    (set) => ({
      viewMode: "gymnase",
      selectedScheduleId: null,
      selectedSlotId: null,
      resourceFilter: [],
      // Switching view invalidates the resource selection (different resource set).
      setViewMode: (viewMode) => set({ viewMode, resourceFilter: [], selectedSlotId: null }),
      setSelectedScheduleId: (selectedScheduleId) => set({ selectedScheduleId, selectedSlotId: null }),
      setSelectedSlotId: (selectedSlotId) => set({ selectedSlotId }),
      toggleResource: (id) =>
        set((state) => ({
          resourceFilter: state.resourceFilter.includes(id) ? state.resourceFilter.filter((r) => r !== id) : [...state.resourceFilter, id],
        })),
      clearResourceFilter: () => set({ resourceFilter: [] }),
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
