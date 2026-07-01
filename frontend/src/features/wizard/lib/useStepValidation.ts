import { useWizardTeams } from "../queries";
import { okValidation, type StepValidation, type WizardStepId } from "./steps";

/**
 * Validation of a step for the "Suivant" gate + nav badges. Teams is the only
 * blocking rule so far (≥1 team); later steps add their own (gym needs a slot,
 * ≥1 coach, no gross constraint error).
 */
export function useStepValidation(stepId: WizardStepId): StepValidation {
  const { data: teams = [] } = useWizardTeams();

  if ("teams" === stepId) {
    return { errors: 0 === teams.length ? ["Ajoutez au moins une équipe."] : [], warnings: [] };
  }
  return okValidation();
}
