import { create } from "zustand";

interface MatchesState {
  /** Saturday key of the weekend shown on the grid; null = auto (first available). */
  selectedWeekend: string | null;
  /** Fixture being placed (opens the placement panel); null = none. */
  selectedFixtureId: string | null;
  /** Manual fixture-entry dialog open. */
  fixtureFormOpen: boolean;
  setSelectedWeekend: (key: string | null) => void;
  setSelectedFixtureId: (id: string | null) => void;
  setFixtureFormOpen: (open: boolean) => void;
}

/** Per-session UI state — nothing worth persisting (selections are ephemeral). */
export const useMatchesStore = create<MatchesState>((set) => ({
  selectedWeekend: null,
  selectedFixtureId: null,
  fixtureFormOpen: false,
  setSelectedWeekend: (selectedWeekend) => set({ selectedWeekend }),
  setSelectedFixtureId: (selectedFixtureId) => set({ selectedFixtureId }),
  setFixtureFormOpen: (fixtureFormOpen) => set({ fixtureFormOpen }),
}));
