import { HTTPError } from "ky";
import { AlertTriangle, CalendarClock, ChevronsDown, ChevronsUp, Lock, PanelLeftClose, PanelLeftOpen, X } from "lucide-react";
import { type ReactNode, useEffect, useMemo, useRef, useState } from "react";
import { useBlocker, useNavigate } from "react-router-dom";

import { useQueryClient } from "@tanstack/react-query";

import { useMe } from "@/features/auth/queries";
import { useCalendarEntry, useDeleteEntry, usePeriodAnchor } from "@/features/cockpit/queries";
import { frDateNumeric } from "@/features/cockpit/lib/date";
import { DeletePlanningButton } from "@/features/cockpit/DeletePlanningButton";
import { listSchedules } from "@/features/planning/api";
import { useSchedules } from "@/features/planning/queries";
import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
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
    <div className="fixed right-4 top-1/2 z-40 hidden -translate-y-1/2 flex-col gap-1 sm:flex">
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
  // The generation step is gated by the SAME blockers as the Récap "Continuer"
  // button — otherwise the left nav lets an onboarded club (nav never locked)
  // jump straight to génération and bypass the gate. Lock it here too, and keep
  // it locked while the verdict is still loading (fail-closed).
  const recapValidation = useStepValidation("recap");
  const generateBlocked = recapValidation.errors.length > 0 || true === recapValidation.pending;

  // « On part » — lu par le prédicat du blocker au moment de la navigation (les
  // valeurs de render y sont STALE : react-router enregistre le prédicat en
  // useEffect, donc une nav déclenchée dans le même cycle voit l'ancien état).
  const leavingRef = useRef(false);

  // The period mode is persisted (localStorage). If its entry was deleted in the
  // meantime, exit cleanly instead of leaving a dead wizard (404 + disabled CTA).
  // The query is meta.silent404 — this toast is the only, explicit, feedback.
  useEffect(() => {
    if (periodMode && periodEntryError instanceof HTTPError && 404 === periodEntryError.response.status) {
      toast.error("Cette période n'existe plus — retour à l'accueil.");
      leavingRef.current = true;
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
  // Onboarding (the club has never generated) → guided: forward steps stay locked
  // until reached via "Suivant". Existing clubs edit freely. Period mode is never
  // guided (structure is inherited read-only; nav is open).
  const guided = !periodMode && !me?.seasonPlan?.hasFinishedVersion;

  // On first entry of a guided wizard, land on the first incomplete step (no
  // team → Équipes, …); when everything is filled, land on Récap — the last
  // stop before generating the main plan.
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
    if (null !== gap) {
      jumpTo(gap); // pull back to the first incomplete step
    } else if (WIZARD_STEPS.findIndex((s) => s.id === stepId) < WIZARD_STEPS.findIndex((s) => "recap" === s.id)) {
      // Everything filled and the user is before Récap → land on Récap. Do NOT
      // pull a user already ON/AFTER Récap back (e.g. mid first-generation on the
      // génération step — a remount must not yank them off the progress view).
      jumpTo("recap");
    }
  }, [guided, ready, stepId, teams.data, venues.data, slots.data, coaches.data, jumpTo]);

  // ── Abandon d'un ajustement de période jamais généré (retour fondateur 2026-07-18) ──
  // « Adapter » crée la période AVANT le wizard (ADR-0002 : le plan naît du geste) ;
  // repartir sans rien générer laissait une entrée orpheline sur tout le créneau des
  // vacances. Quitter (bouton ou navigation SPA) propose de retirer la période dès
  // qu'aucune version n'est CONNUE — donnée en vol/en échec incluse : dégrader en
  // sortie silencieuse referait exactement l'orphelin (revue #260 round 2). Le
  // dialogue est CONDITIONNEL (« si aucun planning n'a été généré… ») ; la décision
  // destructive, elle, se prend sur une lecture serveur FRAÎCHE (confirmAbandon) —
  // fetch muet ou plan irrésolu = jamais de suppression.
  const periodAnchor = usePeriodAnchor(periodMode ? calendarEntryId : null);
  const periodPlanId = periodAnchor.planId;
  const wizardSchedules = useSchedules(periodMode);
  const periodHasKnownVersion =
    null !== periodPlanId
    && undefined !== wizardSchedules.data
    && wizardSchedules.data.some((s) => s.schedulePlanId === periodPlanId);
  const periodMaybeEmpty = periodMode && !periodHasKnownVersion;
  const deleteEntry = useDeleteEntry();
  const queryClient = useQueryClient();
  const [quitAsked, setQuitAsked] = useState(false);
  // Latest-value : le prédicat est enregistré en useEffect par react-router — au
  // moment d'une navigation il closure sur le render PRÉCÉDENT. Les refs portent
  // l'état courant (armé ? en train de partir ?) sans dépendre du cycle de render :
  // sans elles, le navigate() post-confirmation est re-bloqué et le dialogue
  // se ré-ouvre après coup (revue #260 round 1).
  const guardArmedRef = useRef(false);
  useEffect(() => {
    // Post-commit, comme l'enregistrement du prédicat par react-router : la ref est
    // à jour avant toute navigation utilisateur.
    guardArmedRef.current = periodMaybeEmpty;
  });
  const abandoningRef = useRef(false);
  const blocker = useBlocker(({ nextLocation }) => guardArmedRef.current && !leavingRef.current && "/wizard" !== nextLocation.pathname);
  const abandonOpen = quitAsked || "blocked" === blocker.state;

  const finishQuit = () => {
    leavingRef.current = true;
    exitPeriodMode();
    navigate("/");
  };
  const quitPeriod = () => {
    if (periodMaybeEmpty) {
      setQuitAsked(true);
      return;
    }
    finishQuit();
  };
  const confirmAbandon = async () => {
    if (abandoningRef.current) {
      return;
    }
    abandoningRef.current = true;
    const entryId = calendarEntryId;
    const planId = periodPlanId;
    // Re-vérification FRAÎCHE avant le geste destructif : le cache ["schedules"]
    // peut être en retard d'une génération lancée à l'instant (l'invalidation ne
    // part qu'au onSuccess du launch) — supprimer sur cette foi détruirait la
    // version en vol via la cascade serveur. Fetch muet → on ne supprime PAS.
    // Trois issues, décidées sur la lecture FRAÎCHE : vide prouvé → suppression ;
    // version trouvée → conservation annoncée ; indécidable (fetch muet, plan
    // irrésolu) → conservation SANS affirmer qu'une génération existe.
    let verdict: "empty" | "has-version" | "unknown" = "unknown";
    try {
      const fresh = await queryClient.fetchQuery({ queryKey: ["schedules"], queryFn: listSchedules, staleTime: 0 });
      if (null !== planId) {
        verdict = fresh.some((s) => s.schedulePlanId === planId) ? "has-version" : "empty";
      }
    } catch {
      // Fetch muet → verdict reste "unknown" : on ne supprime jamais sur donnée inconnue.
    }
    // Sortir du mode période AVANT le delete : ça désactive useCalendarEntry
    // (sinon son 404 post-suppression déclenche le toast « n'existe plus »).
    leavingRef.current = true;
    exitPeriodMode();
    setQuitAsked(false);
    if ("empty" === verdict && null !== entryId) {
      // .then/.catch sur la promesse (pas un callback mutate()) : ils survivent au
      // démontage du wizard — le toast de succès arrive, et un échec est toasté
      // par le filet global MutationCache.onError (useDeleteEntry n'a pas d'onError).
      deleteEntry
        .mutateAsync(entryId)
        .then(() => toast.success("Période retirée du calendrier"))
        .catch(() => { /* toasté par le filet global (queryClient.ts) */ });
    } else if ("has-version" === verdict) {
      toast.success("Une génération existe pour cette période — elle est conservée.");
    } else {
      toast.info("Période conservée — son contenu n'a pas pu être vérifié.");
    }
    if ("blocked" === blocker.state) {
      blocker.proceed();
    } else {
      navigate("/");
    }
    abandoningRef.current = false;
  };
  const keepPeriod = () => {
    setQuitAsked(false);
    if ("blocked" === blocker.state) {
      blocker.reset();
    }
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
                du {frDateNumeric(periodEntry.startDate)} au {frDateNumeric(periodEntry.endDate)}
              </span>
            ) : null}
          </span>
          <span className="flex items-center gap-1">
            {/* Supprimer ce planning secondaire (cascade plan + versions) → retour cockpit.
                On ARME leavingRef comme finishQuit, sinon le useBlocker d'abandon
                intercepte le navigate et ré-ouvre « Abandonner ? » sur une entrée déjà
                supprimée (revue B2 F1). Masqué sur l'étape GÉNÉRATION : une génération
                lancée à l'instant n'est pas encore dans le cache — supprimer là
                détruirait la version en vol (revue B2 F4 ; l'abandon relit le serveur,
                pas ce bouton). */}
            {null !== calendarEntryId && "generate" !== stepId ? (
              <DeletePlanningButton calendarEntryId={calendarEntryId} title={periodEntry?.title ?? "ce planning"} onDeleted={() => { leavingRef.current = true; exitPeriodMode(); navigate("/"); }} />
            ) : null}
            <Button variant="ghost" size="sm" onClick={quitPeriod}>
              <X className="size-4" />
              Quitter
            </Button>
          </span>
        </div>
      ) : null}
      {/* Texte CONDITIONNEL : la vérité se lit au serveur À LA CONFIRMATION (une
          génération peut aboutir pendant que le dialogue est ouvert) — affirmer
          « aucun planning n'a été généré » ici pourrait contredire l'action. */}
      <ConfirmDialog
        open={abandonOpen}
        title="Abandonner l'ajustement ?"
        description="Si aucun planning n'a été généré pour cette période, elle sera retirée du calendrier (recréable via « Adapter »). Si une génération existe, la période sera conservée."
        confirmLabel="Retirer la période"
        cancelLabel="Rester sur l'ajustement"
        onConfirm={() => void confirmAbandon()}
        onCancel={keepPeriod}
      />
      <div className="flex flex-col gap-6 md:flex-row">
      {/* Left step navigation — collapsible (W8/N4) so any step (incl. génération) can go full-width */}
      {navCollapsed ? null : (
        <nav className="shrink-0 md:w-44">
          <ol className="flex flex-col gap-1">
            {WIZARD_STEPS.map((step, i) => {
              const locked = (guided && i > maxIndex) || ("generate" === step.id && generateBlocked);
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
          <p key={warning} className="mt-3 flex items-center gap-2 text-sm text-warning">
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
