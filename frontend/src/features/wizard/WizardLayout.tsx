import { AlertTriangle } from "lucide-react";

import { Button } from "@/shared/components/ui/button";
import { cn } from "@/shared/lib/utils";

import { WIZARD_STEPS, type WizardStepId } from "./lib/steps";
import { useStepValidation } from "./lib/useStepValidation";
import { CoachesStep } from "./steps/CoachesStep";
import { ConstraintsStep } from "./steps/ConstraintsStep";
import { PlaceholderStep } from "./steps/PlaceholderStep";
import { TeamsStep } from "./steps/TeamsStep";
import { VenuesStep } from "./steps/VenuesStep";
import { useWizardStore } from "./store";

function StepContent({ stepId }: { stepId: WizardStepId }) {
  switch (stepId) {
    case "teams":
      return <TeamsStep />;
    case "venues":
      return <VenuesStep />;
    case "coaches":
      return <CoachesStep />;
    case "constraints":
      return <ConstraintsStep />;
    case "recap":
      return <PlaceholderStep title="Récapitulatif" />;
  }
}

export function WizardPage() {
  const { stepId, setStep, next, prev } = useWizardStore();
  const validation = useStepValidation(stepId);
  const index = WIZARD_STEPS.findIndex((s) => s.id === stepId);
  const blocked = validation.errors.length > 0;
  const isLast = index === WIZARD_STEPS.length - 1;

  return (
    <div className="flex flex-col gap-6 md:flex-row">
      {/* Left step navigation (free navigation) */}
      <nav className="shrink-0 md:w-52">
        <ol className="flex flex-col gap-1">
          {WIZARD_STEPS.map((step, i) => (
            <li key={step.id}>
              <button
                type="button"
                onClick={() => setStep(step.id)}
                aria-current={step.id === stepId ? "step" : undefined}
                className={cn(
                  "flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm transition",
                  step.id === stepId ? "bg-muted font-medium text-foreground" : "text-muted-foreground hover:bg-muted/60",
                )}
              >
                <span className="flex size-5 shrink-0 items-center justify-center rounded-full border border-border text-xs">{i + 1}</span>
                {step.label}
              </button>
            </li>
          ))}
        </ol>
      </nav>

      {/* Current step */}
      <div className="min-w-0 flex-1">
        <StepContent stepId={stepId} />

        {validation.errors.map((error) => (
          <p key={error} role="alert" className="mt-3 flex items-center gap-2 text-sm text-destructive">
            <AlertTriangle className="size-4 shrink-0" />
            {error}
          </p>
        ))}
        {validation.warnings.map((warning) => (
          <p key={warning} className="mt-3 flex items-center gap-2 text-sm text-amber-500">
            <AlertTriangle className="size-4 shrink-0" />
            {warning}
          </p>
        ))}

        <div className="mt-6 flex justify-between border-t border-border pt-4">
          <Button variant="outline" disabled={0 === index} onClick={prev}>
            Précédent
          </Button>
          <Button disabled={blocked || isLast} onClick={next}>
            Suivant
          </Button>
        </div>
      </div>
    </div>
  );
}
