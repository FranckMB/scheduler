import { create } from "zustand";

/**
 * UI state of the "Préparer la saison suivante" confirm dialog. Lives in a
 * store (not SeasonSelector-local state) so other surfaces — the anticipation
 * banner — can open the SAME dialog without duplicating the transition logic.
 * Not persisted: a confirm is per-session by nature.
 */
interface TransitionUiState {
  confirmOpen: boolean;
  openConfirm: () => void;
  closeConfirm: () => void;
}

export const useTransitionUiStore = create<TransitionUiState>()((set) => ({
  confirmOpen: false,
  openConfirm: () => set({ confirmOpen: true }),
  closeConfirm: () => set({ confirmOpen: false }),
}));
