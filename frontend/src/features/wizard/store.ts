import { create } from "zustand";
import { persist } from "zustand/middleware";

import { armNavTransition } from "@/shared/stores/navTransitionStore";

import type { DeepLinkOrigin } from "./lib/deepLink";
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
  /**
   * P2-25 — origine du RETOUR NOMMÉ quand on est arrivé par un deep-link (règle C fondateur) :
   * ÉPHÉMÈRE (jamais persisté, cf. partialize) et effacé dès qu'on agit ou qu'on repart, sinon
   * il mentirait sur la provenance ou traînerait un contexte périmé.
   */
  deepLinkOrigin: DeepLinkOrigin | null;
  setDeepLinkOrigin: (origin: DeepLinkOrigin | null) => void;
  clearDeepLinkOrigin: () => void;
}

const indexOf = (id: WizardStepId): number => WIZARD_STEPS.findIndex((s) => s.id === id);

export const useWizardStore = create<WizardState>()(
  persist(
    (set) => ({
      stepId: "teams",
      maxIndex: 0,
      mode: "season",
      calendarEntryId: null,
      // Chaque changement d'étape est une TRANSITION déclenchée par le gestionnaire : elle arme le
      // contexte « Changement de page » du voile (lot C). Un simple montage du wizard, lui, n'arme
      // rien — cf. `shared/stores/navTransitionStore`.
      setStep: (stepId) => {
        armNavTransition();
        set({ stepId });
      },
      jumpTo: (stepId) => {
        armNavTransition();
        set((state) => ({ stepId, maxIndex: Math.max(state.maxIndex, indexOf(stepId)) }));
      },
      next: () => {
        armNavTransition();
        set((state) => {
          const ni = Math.min(indexOf(state.stepId) + 1, WIZARD_STEPS.length - 1);
          return { stepId: WIZARD_STEPS[ni].id, maxIndex: Math.max(state.maxIndex, ni) };
        });
      },
      prev: () => {
        armNavTransition();
        set((state) => {
          const i = indexOf(state.stepId);
          return { stepId: WIZARD_STEPS[Math.max(i - 1, 0)].id };
        });
      },
      startPeriodMode: (calendarEntryId) => {
        armNavTransition();
        set({ mode: "period", calendarEntryId, stepId: "constraints", maxIndex: WIZARD_STEPS.length - 1 });
      },
      exitPeriodMode: () => {
        armNavTransition();
        set({ mode: "season", calendarEntryId: null, stepId: "teams" });
      },
      deepLinkOrigin: null,
      setDeepLinkOrigin: (deepLinkOrigin) => set({ deepLinkOrigin }),
      clearDeepLinkOrigin: () => set({ deepLinkOrigin: null }),
    }),
    {
      name: "cs-wizard",
      version: 4,
      // Le retour nommé est un état de session, jamais du localStorage : le rehydrater
      // ferait réapparaître « ← Retour à … » sur une simple ouverture du wizard, sans lien.
      partialize: (state) => ({ stepId: state.stepId, maxIndex: state.maxIndex, mode: state.mode, calendarEntryId: state.calendarEntryId }),
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
