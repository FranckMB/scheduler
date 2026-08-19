import { IN_FLIGHT_STATUSES } from "@/features/planning/lib/scheduleStatus";
import { useQueryClient } from "@tanstack/react-query";
import { AlertTriangle, Rocket } from "lucide-react";
import { useEffect, useRef, useState } from "react";

import { anchorIsWritable, useCalendarEntry, usePeriodAnchor } from "@/features/cockpit/queries";
import { isSeasonPlanType } from "@/features/planning/lib/versions";
import { GenerationWaiting } from "@/features/planning/GenerationWaiting";
import { PlanningPage } from "@/features/planning/PlanningPage";
import { useDiagnostics, useSchedules } from "@/features/planning/queries";
import { usePlanningStore } from "@/features/planning/store";
import { Button } from "@/shared/components/ui/button";
import { useCredits } from "@/shared/credits/useCredits";
import { scheduleIdToReuse } from "../lib/retryTarget";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { errorMessage } from "@/shared/lib/errorMessage";

import { useStepValidation } from "../lib/useStepValidation";
import { useLaunchGeneration, useScheduleStatus } from "../queries";
import { useWizardStore } from "../store";
import { BlockerList } from "./BlockerList";

// D-31 : foyer unique — importé du module qui porte le type.
const IN_FLIGHT = IN_FLIGHT_STATUSES;

// Hard client-side guard: if the backend/engine never answers, stop polling forever and
// surface a retry. Must exceed the WORST-CASE wall-clock, not just one solve: the message
// can wait in the Redis queue / on ClubGenerationLock behind another club's ~600 s solve,
// THEN run its own (adaptive phase-1 up to 600 s + chaining 10 s + import). FRT-16: a 5-min
// timeout falsely failed a legitimately-long generation; 20 min covers a queued run too.
const TIMEOUT_MS = 20 * 60 * 1000;

export function GenerateStep() {
  const queryClient = useQueryClient();
  // §4bis pts 2/4 — le coût au point d'action : le solde s'affiche sur le bouton
  // de sortie, désactivé à 0 (Découverte bridée ; null = payant/bêta/démo). Si une
  // requête part quand même, le 403 serveur s'affiche via `launchReason`.
  const credits = useCredits();
  const creditSuffix = null !== credits ? ` (${credits.remaining})` : "";
  const creditsBlocked = null !== credits && !credits.canGenerate;
  const { mode, calendarEntryId } = useWizardStore();
  const periodMode = "period" === mode;
  const { data: periodEntry } = useCalendarEntry(periodMode ? calendarEntryId : null);
  // ADR-0002 C4 : une version se crée SOUS le plan de sa période — résolu depuis l'entrée.
  const periodAnchor = usePeriodAnchor(periodMode ? calendarEntryId : null);
  const periodPlanId = periodAnchor.planId;
  const periodAnchorReady = anchorIsWritable(periodAnchor);
  const setSelectedScheduleId = usePlanningStore((s) => s.setSelectedScheduleId);

  const { data: schedules = [], isLoading: schedulesLoading } = useSchedules();
  // Same blockers as the Récap gate — a user already sitting on this step when a
  // blocker appears must not be able to launch anyway. `pending` keeps the gate
  // closed while the verdict loads (fail-closed).
  const recapValidation = useStepValidation("recap");
  const blockers = recapValidation.errors;
  const gateClosed = blockers.length > 0 || true === recapValidation.pending;
  const launch = useLaunchGeneration();
  const [scheduleId, setScheduleId] = useState<string | null>(null);
  const [timedOut, setTimedOut] = useState(false);
  // Le motif exact rendu par le serveur au dernier essai (null = message générique).
  const [launchReason, setLaunchReason] = useState<string | null>(null);

  const { data: sched } = useScheduleStatus(scheduleId);
  const status = sched?.status ?? null;

  // Season mode keys completion off ANY completed schedule (first launch or
  // regeneration). Period mode keys strictly off THIS overlay's status.
  const overlayDone = periodMode && "COMPLETED" === status;
  // planning-versions: validating archives the COMPLETED siblings — a season
  // A finished version is a finished version: choosing one no longer rewrites its
  // status, so COMPLETED alone answers "has this club generated?".
  const hasCompleted = periodMode ? overlayDone : schedules.some((s) => isSeasonPlanType(s.planType) && "COMPLETED" === s.status);
  const anyInFlight = schedules.some((s) => IN_FLIGHT.includes(s.status));
  const showPlanning = periodMode ? overlayDone : hasCompleted || (anyInFlight && null === scheduleId);

  // Leaving mid-generation loses the local scheduleId; on return, re-adopt the
  // period's in-flight overlay instead of offering a concurrent second launch.
  // ADR-0002 lot D-b : la version en vol d'une période se dérive de SON PLAN
  // (schedulePlanId), plus d'un pointeur sur l'entrée. Rien à reprendre → « Générer »
  // crée une version neuve (modèle versions : on n'écrase jamais une version, a
  // fortiori une version validée qui est en lecture seule).
  const overlaySchedule = periodMode ? (schedules.find((s) => s.schedulePlanId === periodPlanId && IN_FLIGHT.includes(s.status)) ?? null) : null;
  useEffect(() => {
    if (periodMode && null === scheduleId && overlaySchedule) {
      // eslint-disable-next-line react-hooks/set-state-in-effect -- one-shot resume of an in-flight overlay
      setScheduleId(overlaySchedule.id);
    }
  }, [periodMode, scheduleId, overlaySchedule]);

  // §2bis warning: the FIRST overlay freezes the socle (editing the baseline
  // afterwards destroys the overlays after an explicit confirm). Gated on the
  // loaded list — an empty in-flight default would flash it for a non-first overlay.
  const isFirstOverlay = periodMode && !schedulesLoading && !schedules.some((s) => !isSeasonPlanType(s.planType));

  useEffect(() => {
    if (null === scheduleId) {
      return;
    }
    const t = setTimeout(() => setTimedOut(true), TIMEOUT_MS);
    return () => clearTimeout(t);
  }, [scheduleId]);

  const settled = useRef(false);
  useEffect(() => {
    if (!hasCompleted || settled.current) {
      return;
    }
    settled.current = true;
    // Point the embedded planning at THIS run's schedule (not a stale overlay a
    // "Voir le plan" click may have left selected in the planning store).
    if (null !== scheduleId) {
      setSelectedScheduleId(scheduleId);
    }
    if (periodMode) {
      void queryClient.invalidateQueries({ queryKey: ["calendar-entries"] });
      void queryClient.invalidateQueries({ queryKey: ["schedules"] });
    } else {
      // Guarded by settled.current → runs exactly once, no cascade.
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setScheduleId(null);
      void queryClient.invalidateQueries({ queryKey: ["me"] });
    }
  }, [hasCompleted, periodMode, scheduleId, setSelectedScheduleId, queryClient]);

  const launching = launch.isPending;
  const failed = !launching && !showPlanning && (launch.isError || "FAILED" === status || timedOut);
  // Un solve FAILED a des DIAGNOSTICS en base (le moteur explique : contraintes
  // impossibles, capacité, verrous) — les afficher au lieu du générique
  // « une erreur est survenue » (retour fondateur 2026-08-05 : « en prod ça ne
  // passera pas »). Chargés seulement dans ce cas ; lancement/timeout gardent
  // leur motif propre.
  const failedDiagnostics = useDiagnostics("FAILED" === status ? scheduleId : null);
  const failureExplanations = (failedDiagnostics.data ?? []).filter((d) => "ERROR" === d.severity);
  const failureSuggestions = failureExplanations.flatMap((d) => (Array.isArray(d.suggestions) ? d.suggestions.filter((x): x is string => "string" === typeof x) : []));
  const waiting = !showPlanning && (launching || (null !== scheduleId && "FAILED" !== status && !timedOut));

  const start = async () => {
    // Garde anti-course : en mode période, ne jamais lancer sans le plan résolu — sinon le POST
    // omettrait schedulePlanId et créerait une version de SAISON au lieu de l'overlay. Le bouton
    // est déjà désactivé tant que l'ancre n'est pas prête ; ceci ferme le chemin par sécurité.
    if (periodMode && (null === periodPlanId || !periodAnchorReady)) {
      return;
    }
    // AUD-FRT-09 — la règle vit dans `retryTarget.ts` : montée ici, elle n'était pas
    // falsifiable (voir son en-tête).
    const reuseId = scheduleIdToReuse({ periodMode, status, scheduleId });

    setTimedOut(false);
    setLaunchReason(null);
    launch.reset();
    setScheduleId(null);
    try {
      const id = await launch.mutateAsync(
        periodMode
          ? {
              schedulePlanId: periodPlanId ?? undefined,
              // Reprendre la version en vol (anti-double-lancement) ; sinon créer une
              // version neuve sous le plan (lot D-b — plus de pointeur overlay sur l'entrée).
              existingScheduleId: overlaySchedule?.id ?? undefined,
            }
          : { existingScheduleId: reuseId },
      );
      setScheduleId(id);
    } catch (error) {
      // launch.isError drives the failed state below ; on en garde le MOTIF. Le garde
      // d'épinglage orphelin (#8) refuse en 422 en nommant le gymnase et le jour —
      // exactement ce qu'il faut lire pour agir. Le remplacer par « une erreur est
      // survenue » rendait muet un garde écrit pour parler (revue #8, round 4).
      setLaunchReason(await errorMessage(error));
    }
  };

  if (showPlanning) {
    return <PlanningPage embedded />;
  }

  return (
    <div>
      <p className="mb-4 text-sm text-muted-foreground">
        {periodMode
          ? "Générez le planning de cette période. Il s'applique par-dessus le planning principal sur la fenêtre, sans le modifier."
          : "Le système place vos équipes dans les créneaux selon vos règles. Lancez, puis laissez tourner."}
      </p>

      {failed ? (
        <div className="flex flex-col items-center gap-4 py-12 text-center">
          <AlertTriangle className="size-14 text-destructive" />
          <div className="space-y-2">
            <p className="text-lg font-medium">La génération n'a pas abouti.</p>
            {failureExplanations.length > 0 ? (
              <div className="max-w-xl space-y-2 text-left">
                {failureExplanations.map((d) => (
                  <p key={d.id} className="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-foreground">
                    {d.message}
                  </p>
                ))}
                {failureSuggestions.length > 0 ? (
                  <ul className="list-disc space-y-0.5 pl-5 text-sm text-muted-foreground">
                    {[...new Set(failureSuggestions)].map((sugg) => (
                      <li key={sugg}>{sugg}</li>
                    ))}
                  </ul>
                ) : null}
              </div>
            ) : (
              <p className="max-w-md text-sm text-muted-foreground">
                {timedOut
                  // AUD-UXC-11 (résidu soldé le 2026-08-19) — cet écran vouvoie partout
                  // (« Générez », « vos équipes », « votre planning »). La passe précédente avait
                  // vouvoyé CETTE branche mais laissé sa voisine juste en dessous au tutoiement :
                  // le lecteur changeait d'interlocuteur selon le type d'échec. C'était le dernier
                  // tutoiement visible du produit (hors console fondateur).
                  ? "Le service met trop de temps à répondre. Vérifiez que le moteur tourne, puis réessayez."
                  : (launchReason ?? ("FAILED" === status && failedDiagnostics.isLoading ? "Lecture du motif de l'échec…" : (launchReason ?? "Une erreur est survenue (données ou moteur indisponible). Vous pouvez réessayer.")))}
              </p>
            )}
          </div>
          <Button size="lg" onClick={start} disabled={creditsBlocked}>
            <Rocket className="size-4" />
            Réessayer{creditSuffix}
          </Button>
        </div>
      ) : waiting ? (
        <GenerationWaiting />
      ) : (
        <div className="flex flex-col items-center gap-4 py-12 text-center">
          <Rocket className="size-12 text-accent" />
          <p className="max-w-sm text-sm text-muted-foreground">
            {/* AUD-UXC-11 — « le plan » était le seul écart : les deux autres phrases de
                l'écran disent « le planning » (:173, :236). Un mot par concept, sinon le
                gestionnaire se demande si « plan » et « planning » désignent deux choses. */}
            {periodMode ? "Tout est prêt. Générez le planning de la période." : "Tout est prêt. Lancez la génération de votre planning."}
          </p>
          {isFirstOverlay ? (
            <p className="max-w-sm rounded-md border border-accent/40 bg-accent/10 px-3 py-2 text-xs text-muted-foreground">
              Premier planning secondaire : il s'appuie sur votre planning principal, qui devient la référence — le modifier ensuite supprimera les plannings secondaires (après confirmation).
            </p>
          ) : null}
          <BlockerList blockers={blockers} className="max-w-md text-left" />
          {/* Period mode: wait for the entry to load so an existing overlay is
              regenerated (not duplicated → backend 422). Also gated by blockers. */}
          {/* P4-20 (option A) : une ancre en échec laissait ce bouton grisé POUR TOUJOURS,
              sans un mot — le gestionnaire cliquait dans le vide sans jamais savoir
              pourquoi. On dit l'échec et on offre de réessayer ; Générer ne redevient
              cliquable que lorsque l'ancre est réellement là. */}
          {"failed" === periodAnchor.state ? (
            <LoadErrorHint onRetry={periodAnchor.retry} className="justify-center">
              Impossible de charger la période — la génération est bloquée.
            </LoadErrorHint>
          ) : null}
          <Button size="lg" onClick={start} disabled={creditsBlocked || gateClosed || (periodMode && (!periodEntry || !periodAnchorReady))}>
            <Rocket className="size-4" />
            {periodMode ? "Générer le planning de période" : "Lancer la génération"}{creditSuffix}
          </Button>
        </div>
      )}
    </div>
  );
}
