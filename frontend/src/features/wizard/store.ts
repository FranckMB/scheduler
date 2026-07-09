import { create } from "zustand";
import { persist } from "zustand/middleware";

import { WIZARD_STEPS, type WizardStepId } from "./lib/steps";

/** "season" = base plan (onboarding/libre); "period" = overlay of a CalendarEntry (palier B). */
export type WizardMode = "season" | "period";

// Reservations moved to server-backed queries (Reservation entity, base/overlay) —
// they are NO LONGER client state. See useReservations / ReservationPanel.

interface WizardState {
  stepId: WizardStepId;
  /** Furthest step index reached via "Suivant" — gates forward nav in guided mode. */
  maxIndex: number;
  mode: WizardMode;
  /** The period being adapted in "period" mode; null in "season" mode. */
  calendarEntryId: string | null;
  setStep: (id: WizardStepId) => void;
  /** Go to a step and unlock everything up to it (resume-to-first-gap). */
  jumpTo: (id: WizardStepId) => void;
  next: () => void;
  prev: () => void;
  /** Enter period mode for a calendar entry — lands on Contraintes (structure is inherited). */
  startPeriodMode: (calendarEntryId: string) => void;
  /** Back to base-plan mode. */
  exitPeriodMode: () => void;
}

const indexOf = (id: WizardStepId): number => WIZARD_STEPS.findIndex((s) => s.id === id);

export const useWizardStore = create<WizardState>()(
  persist(
    (set) => ({
      stepId: "teams",
      maxIndex: 0,
      mode: "season",
      calendarEntryId: null,
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
      startPeriodMode: (calendarEntryId) => set({ mode: "period", calendarEntryId, stepId: "constraints", maxIndex: WIZARD_STEPS.length - 1 }),
      exitPeriodMode: () => set({ mode: "season", calendarEntryId: null, stepId: "teams" }),
    }),
    {
      name: "cs-wizard",
      version: 4,
      migrate: (persistedState) => {
        // v4 dropped the client `reservations` slice (moved server-side).
        const prev = (null !== persistedState && "object" === typeof persistedState ? persistedState : {}) as Partial<WizardState>;
        return {
          stepId: prev.stepId ?? "teams",
          maxIndex: prev.maxIndex ?? 0,
          mode: prev.mode ?? "season",
          calendarEntryId: prev.calendarEntryId ?? null,
        } as WizardState;
      },
    },
  ),
);
