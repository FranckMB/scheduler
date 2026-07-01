import { useVenueSlots, useWizardCoachPlayers, useWizardCoaches, useWizardTeamCoaches, useWizardTeams, useWizardVenues } from "../queries";
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
  const { data: coaches = [] } = useWizardCoaches();
  const { data: teamCoaches = [] } = useWizardTeamCoaches();
  const { data: coachPlayers = [] } = useWizardCoachPlayers();

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
  if ("coaches" === stepId) {
    const linked = new Set([...teamCoaches.map((l) => l.coachId), ...coachPlayers.map((l) => l.coachId)]);
    const unlinked = coaches.filter((c) => !linked.has(c.id));
    return {
      errors: 0 === coaches.length ? ["Ajoutez au moins un coach."] : [],
      warnings: unlinked.length > 0 ? [`Coach(s) sans équipe : ${unlinked.map((c) => `${c.firstName} ${c.lastName}`.trim()).join(", ")}.`] : [],
    };
  }
  return okValidation();
}
