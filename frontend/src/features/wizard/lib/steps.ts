export type WizardStepId = "teams" | "venues" | "coaches" | "constraints" | "recap" | "generate";

export interface StepDef {
  id: WizardStepId;
  label: string;
}

/** Wizard flow. Teams first; ranking (tierOrder) lives inside the Teams step. */
export const WIZARD_STEPS: StepDef[] = [
  { id: "teams", label: "Équipes" },
  { id: "venues", label: "Gymnases" },
  { id: "coaches", label: "Coachs" },
  { id: "constraints", label: "Contraintes" },
  { id: "recap", label: "Récapitulatif" },
  { id: "generate", label: "Génération" },
];

export interface StepValidation {
  errors: string[];
  warnings: string[];
  /** True while the data behind the verdict is still loading. Consumers that
   * gate an action (e.g. the generate launch) must treat pending as blocked so
   * the gate is fail-closed during the load window, not briefly open. */
  pending?: boolean;
}

export const okValidation = (): StepValidation => ({ errors: [], warnings: [] });
