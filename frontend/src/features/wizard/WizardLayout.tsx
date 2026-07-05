import { HTTPError } from "ky";
import { AlertTriangle, CalendarClock, ChevronsDown, ChevronsUp, Lock, PanelLeftClose, PanelLeftOpen, X } from "lucide-react";
import { type ReactNode, useEffect, useMemo, useRef, useState } from "react";
import { useNavigate } from "react-router-dom";

import { useMe } from "@/features/auth/queries";
import { useCalendarEntry } from "@/features/cockpit/queries";
import { Button } from "@/shared/components/ui/button";
import { cn } from "@/shared/lib/utils";

import { toast } from "@/shared/stores/toastStore";

import { WizardFooterContext } from "./lib/footerSlot";
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

/** Floating top/bottom page-jump arrows, shown on every step whenever the page
 *  actually scrolls (was TeamsStep-only before). Pinned to the right edge at
 *  vertical center — NOT bottom-right, to avoid overlapping the sticky footer's
 *  Suivant button. `suppressed` hides them (e.g. during Teams drag-reorder). */
function ScrollJumpButtons({ suppressed }: { suppressed: boolean }) {
  const [scrollable, setScrollable] = useState(false);

  useEffect(() => {
    const check = () => setScrollable(document.documentElement.scrollHeight > window.innerHeight + 48);
    check();
    const observer = new ResizeObserver(check);
    observer.observe(document.body);
    window.addEventListener("resize", check);
    return () => {
      observer.disconnect();
      window.removeEventListener("resize", check);
    };
  }, []);

  if (suppressed || !scrollable) {
    return null;
  }
  return (
    <div className="fixed right-4 top-1/2 z-40 flex -translate-y-1/2 flex-col gap-1">
      <Button size="icon" variant="outline" aria-label="Haut de page" onClick={() => window.scrollTo({ top: 0, behavior: "smooth" })}>
        <ChevronsUp className="size-4" />
      </Button>
      <Button size="icon" variant="outline" aria-label="Bas de page" onClick={() => window.scrollTo({ top: document.body.scrollHeight, behavior: "smooth" })}>
        <ChevronsDown className="size-4" />
      </Button>
    </div>
  );
}

export function WizardPage() {
  const { stepId, maxIndex, mode, calendarEntryId, setStep, jumpTo, next, prev, exitPeriodMode } = useWizardStore();
  const { data: me } = useMe();
  const navigate = useNavigate();
  const periodMode = "period" === mode;
  const { data: periodEntry, error: periodEntryError } = useCalendarEntry(periodMode ? calendarEntryId : null);
  const validation = useStepValidation(stepId);

  // The period mode is persisted (localStorage). If its entry was deleted in the
  // meantime, exit cleanly instead of leaving a dead wizard (404 + disabled CTA).
  // The query is meta.silent404 — this toast is the only, explicit, feedback.
  useEffect(() => {
    if (periodMode && periodEntryError instanceof HTTPError && 404 === periodEntryError.response.status) {
      toast.error("Cette période n'existe plus — retour à l'accueil.");
      exitPeriodMode();
      navigate("/");
    }
  }, [periodMode, periodEntryError, exitPeriodMode, navigate]);
  const index = WIZARD_STEPS.findIndex((s) => s.id === stepId);
  const currentStep = WIZARD_STEPS[index];
  const blocked = validation.errors.length > 0;
  const isLast = index === WIZARD_STEPS.length - 1;
  const [navCollapsed, setNavCollapsed] = useState(false);
  const [footerExtra, setFooterExtra] = useState<ReactNode>(null);
  const [suppressScrollJump, setSuppressScrollJump] = useState(false);
  const footerCtx = useMemo(() => ({ setFooterExtra, setSuppressScrollJump }), []);
  // First-time club (not yet onboarded) → guided: forward steps stay locked
  // until reached via "Suivant". Existing clubs edit freely. Period mode is never
  // guided (structure is inherited read-only; nav is open).
  const guided = !periodMode && me?.club?.onboardingCompleted === false;

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

  const quitPeriod = () => {
    exitPeriodMode();
    navigate("/");
  };

  return (
    <WizardFooterContext.Provider value={footerCtx}>
      {periodMode ? (
        <div className="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-accent/40 bg-accent/10 px-4 py-2 text-sm">
          <span className="flex items-center gap-2">
            <CalendarClock className="size-4 text-accent" />
            <span className="font-medium">Mode période — {periodEntry?.title ?? "…"}</span>
            {periodEntry ? (
              <span className="text-muted-foreground">
                du {periodEntry.startDate} au {periodEntry.endDate}
              </span>
            ) : null}
          </span>
          <Button variant="ghost" size="sm" onClick={quitPeriod}>
            <X className="size-4" />
            Quitter
          </Button>
        </div>
      ) : null}
      <div className="flex flex-col gap-6 md:flex-row">
      {/* Left step navigation — collapsible (W8/N4) so any step (incl. génération) can go full-width */}
      {navCollapsed ? null : (
        <nav className="shrink-0 md:w-44">
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

      {/* Current step — fills the viewport height so the sticky footer sits at
          the real bottom (no floating gap on short steps) yet stays pinned on scroll. */}
      <div className="flex min-h-[calc(100vh-5.5rem)] min-w-0 flex-1 flex-col">
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

        {/* Récap renders its own grouped blocker panel, so skip the generic alerts there. */}
        {"recap" === stepId
          ? null
          : validation.errors.map((error) => (
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

        {/* Prev/Next footer (W7). Sticky on the data-entry steps; NOT sticky on
            Génération — there the embedded planning stack is taller than the
            viewport on short screens and a pinned bar would overlay the grid,
            so the footer sits in the flow below it instead. On Récap "Suivant"
            becomes the gated "Continuer vers la génération". A step can inject
            an action (footerExtra), e.g. "Trier" on the Teams step. */}
        <div
          className={cn(
            "z-20 mt-auto flex items-center justify-between gap-2 border-t border-border bg-background pt-4 pb-4",
            "generate" === stepId ? "" : "sticky bottom-0",
          )}
        >
          <Button variant="outline" disabled={0 === index} onClick={prev}>
            Précédent
          </Button>
          <div className="flex items-center gap-2">
            {footerExtra}
            {isLast ? null : (
              <Button disabled={blocked} onClick={next}>
                {"recap" === stepId ? "Continuer vers la génération" : "Suivant"}
              </Button>
            )}
          </div>
        </div>
      </div>
      </div>
      <ScrollJumpButtons suppressed={suppressScrollJump} />
    </WizardFooterContext.Provider>
  );
}
