import { AlertTriangle, Lock, PanelLeftClose, PanelLeftOpen } from "lucide-react";
import { useEffect, useRef, useState } from "react";

import { useMe } from "@/features/auth/queries";
import { Button } from "@/shared/components/ui/button";
import { cn } from "@/shared/lib/utils";

import { WIZARD_STEPS, type WizardStepId } from "./lib/steps";
import { useStepValidation } from "./lib/useStepValidation";
import { useVenueSlots, useWizardCoaches, useWizardTeams, useWizardVenues } from "./queries";
import { CoachesStep } from "./steps/CoachesStep";
import { ConstraintsStep } from "./steps/ConstraintsStep";
import { GenerateStep } from "./steps/GenerateStep";
import { RecapStep } from "./steps/RecapStep";
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
      return <RecapStep />;
    case "generate":
      return <GenerateStep />;
  }
}

export function WizardPage() {
  const { stepId, maxIndex, setStep, jumpTo, next, prev } = useWizardStore();
  const { data: me } = useMe();
  const validation = useStepValidation(stepId);
  const index = WIZARD_STEPS.findIndex((s) => s.id === stepId);
  const currentStep = WIZARD_STEPS[index];
  const blocked = validation.errors.length > 0;
  const isLast = index === WIZARD_STEPS.length - 1;
  const [navCollapsed, setNavCollapsed] = useState(false);
  // First-time club (not yet onboarded) → guided: forward steps stay locked
  // until reached via "Suivant". Existing clubs edit freely.
  const guided = me?.club?.onboardingCompleted === false;

  // On first entry of a guided wizard, land on the first incomplete step
  // (no team → Équipes, no gym → Gymnases, …) so the user resumes where needed.
  const teams = useWizardTeams();
  const venues = useWizardVenues();
  const slots = useVenueSlots();
  const coaches = useWizardCoaches();
  const positioned = useRef(false);
  const ready = teams.isSuccess && venues.isSuccess && slots.isSuccess && coaches.isSuccess;
  useEffect(() => {
    if (positioned.current || !guided || !ready) {
      return;
    }
    positioned.current = true;
    const venueList = venues.data ?? [];
    const slotList = slots.data ?? [];
    const withSlot = new Set(slotList.map((s) => s.venueId));
    let gap: WizardStepId | null = null;
    if (0 === (teams.data ?? []).length) {
      gap = "teams";
    } else if (0 === venueList.length || venueList.some((v) => !withSlot.has(v.id))) {
      gap = "venues";
    } else if (0 === (coaches.data ?? []).length) {
      gap = "coaches";
    }
    // Only pull back to a real gap; if everything is filled, leave the user
    // wherever they were (e.g. already on the generation step).
    if (null !== gap) {
      jumpTo(gap);
    }
  }, [guided, ready, teams.data, venues.data, slots.data, coaches.data, jumpTo]);

  return (
    <div className="flex flex-col gap-6 md:flex-row">
      {/* Left step navigation — collapsible (W8/N4) so any step (incl. génération) can go full-width */}
      {navCollapsed ? null : (
        <nav className="shrink-0 md:w-52">
          <ol className="flex flex-col gap-1">
            {WIZARD_STEPS.map((step, i) => {
              const locked = guided && i > maxIndex;
              return (
                <li key={step.id}>
                  <button
                    type="button"
                    disabled={locked}
                    onClick={() => setStep(step.id)}
                    aria-current={step.id === stepId ? "step" : undefined}
                    className={cn(
                      "flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm transition",
                      step.id === stepId ? "bg-muted font-medium text-foreground" : "text-muted-foreground hover:bg-muted/60",
                      locked ? "cursor-not-allowed opacity-40 hover:bg-transparent" : "",
                    )}
                  >
                    <span className="flex size-5 shrink-0 items-center justify-center rounded-full border border-border text-xs">{i + 1}</span>
                    <span className="flex-1">{step.label}</span>
                    {locked ? <Lock className="size-3" /> : null}
                  </button>
                </li>
              );
            })}
          </ol>
        </nav>
      )}

      {/* Current step */}
      <div className="min-w-0 flex-1">
        {/* Sticky step title + collapse toggle (W7 title, W8/N4 collapse) */}
        <div className="sticky top-0 z-20 mb-4 flex items-center justify-between gap-2 border-b border-border bg-background py-3">
          <h2 className="text-lg font-semibold">
            <span className="text-muted-foreground">
              Étape {index + 1}/{WIZARD_STEPS.length} ·{" "}
            </span>
            {currentStep?.label}
          </h2>
          <Button
            variant="ghost"
            size="sm"
            onClick={() => setNavCollapsed((c) => !c)}
            aria-label={navCollapsed ? "Afficher les étapes" : "Masquer les étapes"}
          >
            {navCollapsed ? <PanelLeftOpen className="size-4" /> : <PanelLeftClose className="size-4" />}
            {navCollapsed ? "Étapes" : "Plein écran"}
          </Button>
        </div>

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

        {/* Sticky Prev/Next footer (W7) — stays visible on long steps. On Récap the
            forward action is the gated "Continuer vers la génération" CTA inside the
            step, so the generic "Suivant" is hidden there to avoid a redundant button. */}
        <div className="sticky bottom-0 z-20 mt-6 flex justify-between border-t border-border bg-background py-4">
          <Button variant="outline" disabled={0 === index} onClick={prev}>
            Précédent
          </Button>
          {isLast || "recap" === stepId ? null : (
            <Button disabled={blocked} onClick={next}>
              Suivant
            </Button>
          )}
        </div>
      </div>
    </div>
  );
}
