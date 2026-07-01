import { create } from "zustand";
import { persist } from "zustand/middleware";

import { WIZARD_STEPS, type WizardStepId } from "./lib/steps";

interface WizardState {
  stepId: WizardStepId;
  /** Furthest step index reached via "Suivant" — gates forward nav in guided mode. */
  maxIndex: number;
  setStep: (id: WizardStepId) => void;
  next: () => void;
  prev: () => void;
}

const indexOf = (id: WizardStepId): number => WIZARD_STEPS.findIndex((s) => s.id === id);

export const useWizardStore = create<WizardState>()(
  persist(
    (set) => ({
      stepId: "teams",
      maxIndex: 0,
      setStep: (stepId) => set({ stepId }),
      next: () =>
        set((state) => {
          const ni = Math.min(indexOf(state.stepId) + 1, WIZARD_STEPS.length - 1);
          return { stepId: WIZARD_STEPS[ni].id, maxIndex: Math.max(state.maxIndex, ni) };
        }),
      prev: () =>
        set((state) => {
          const i = indexOf(state.stepId);
          return { stepId: WIZARD_STEPS[Math.max(i - 1, 0)].id };
        }),
    }),
    {
      name: "cs-wizard",
      version: 1,
      migrate: (persistedState) => {
        if (persistedState === null || typeof persistedState !== "object") {
          return { stepId: "teams", maxIndex: 0 } as WizardState;
        }
        return persistedState as WizardState;
      },
    },
  ),
);
