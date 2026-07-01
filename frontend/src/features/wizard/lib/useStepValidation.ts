import { useVenueSlots, useWizardTeams, useWizardVenues } from "../queries";
import { okValidation, type StepValidation, type WizardStepId } from "./steps";

/**
 * Validation of a step for the "Suivant" gate + nav badges. Blocking rules per
 * step: ≥1 team; every gym has ≥1 availability slot; (later) ≥1 coach, no gross
 * constraint error.
 */
export function useStepValidation(stepId: WizardStepId): StepValidation {
  const { data: teams = [] } = useWizardTeams();
  const { data: venues = [] } = useWizardVenues();
  const { data: slots = [] } = useVenueSlots();

  if ("teams" === stepId) {
    return { errors: 0 === teams.length ? ["Ajoutez au moins une équipe."] : [], warnings: [] };
  }
  if ("venues" === stepId) {
    const withSlot = new Set(slots.map((s) => s.venueId));
    const empty = venues.filter((v) => !withSlot.has(v.id));
    const errors: string[] = [];
    if (0 === venues.length) {
      errors.push("Ajoutez au moins un gymnase.");
    }
    if (empty.length > 0) {
      errors.push(`Gymnase(s) sans créneau : ${empty.map((v) => v.name).join(", ")}.`);
    }
    return { errors, warnings: [] };
  }
  return okValidation();
}
