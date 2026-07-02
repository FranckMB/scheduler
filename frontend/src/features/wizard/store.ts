import { create } from "zustand";
import { persist } from "zustand/middleware";

import { WIZARD_STEPS, type WizardStepId } from "./lib/steps";

/** A slot reserved for a team before generation, applied as a HARD lock at launch. */
export interface Reservation {
  id: string;
  teamId: string;
  venueId: string;
  dayOfWeek: number;
  startTime: string;
  durationMinutes: number;
}

interface WizardState {
  stepId: WizardStepId;
  /** Furthest step index reached via "Suivant" — gates forward nav in guided mode. */
  maxIndex: number;
  reservations: Reservation[];
  setStep: (id: WizardStepId) => void;
  /** Go to a step and unlock everything up to it (resume-to-first-gap). */
  jumpTo: (id: WizardStepId) => void;
  next: () => void;
  prev: () => void;
  addReservation: (r: Omit<Reservation, "id">) => void;
  removeReservation: (id: string) => void;
  clearReservations: () => void;
}

const indexOf = (id: WizardStepId): number => WIZARD_STEPS.findIndex((s) => s.id === id);

export const useWizardStore = create<WizardState>()(
  persist(
    (set) => ({
      stepId: "teams",
      maxIndex: 0,
      reservations: [],
      setStep: (stepId) => set({ stepId }),
      jumpTo: (stepId) => set((state) => ({ stepId, maxIndex: Math.max(state.maxIndex, indexOf(stepId)) })),
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
      addReservation: (r) => set((state) => ({ reservations: [...state.reservations, { ...r, id: crypto.randomUUID() }] })),
      removeReservation: (id) => set((state) => ({ reservations: state.reservations.filter((x) => x.id !== id) })),
      clearReservations: () => set({ reservations: [] }),
    }),
    {
      name: "cs-wizard",
      version: 2,
      migrate: (persistedState) => {
        const prev = (null !== persistedState && "object" === typeof persistedState ? persistedState : {}) as Partial<WizardState>;
        return {
          stepId: prev.stepId ?? "teams",
          maxIndex: prev.maxIndex ?? 0,
          reservations: prev.reservations ?? [],
        } as WizardState;
      },
    },
  ),
);
