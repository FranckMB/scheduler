import { useQueryClient } from "@tanstack/react-query";
import { AlertTriangle, Rocket } from "lucide-react";
import { useEffect, useRef, useState } from "react";

import { useMe } from "@/features/auth/queries";
import { useCalendarEntry, usePeriodAnchor } from "@/features/cockpit/queries";
import type { ScheduleStatus } from "@/features/planning/api";
import { isSeasonPlanType } from "@/features/planning/lib/versions";
import { GenerationWaiting } from "@/features/planning/GenerationWaiting";
import { PlanningPage } from "@/features/planning/PlanningPage";
import { useSchedules } from "@/features/planning/queries";
import { usePlanningStore } from "@/features/planning/store";
import { Button } from "@/shared/components/ui/button";

import { useStepValidation } from "../lib/useStepValidation";
import { useLaunchGeneration, useScheduleStatus } from "../queries";
import { useWizardStore } from "../store";
import { BlockerList } from "./BlockerList";

const IN_FLIGHT: ScheduleStatus[] = ["PENDING", "GENERATING"];

// Hard client-side guard: if the backend/engine never answers, stop polling forever and
// surface a retry. Must exceed the WORST-CASE wall-clock, not just one solve: the message
// can wait in the Redis queue / on ClubGenerationLock behind another club's ~600 s solve,
// THEN run its own (adaptive phase-1 up to 600 s + chaining 10 s + import). FRT-16: a 5-min
// timeout falsely failed a legitimately-long generation; 20 min covers a queued run too.
const TIMEOUT_MS = 20 * 60 * 1000;

export function GenerateStep() {
  const queryClient = useQueryClient();
  const { data: me } = useMe();
  const { mode, calendarEntryId } = useWizardStore();
  const periodMode = "period" === mode;
  const { data: periodEntry } = useCalendarEntry(periodMode ? calendarEntryId : null);
  // ADR-0002 C4 : une version se crée SOUS le plan de sa période — résolu depuis l'entrée.
  const { planId: periodPlanId, ready: periodAnchorReady } = usePeriodAnchor(periodMode ? calendarEntryId : null);
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
  const waiting = !showPlanning && (launching || (null !== scheduleId && "FAILED" !== status && !timedOut));

  const initial = (me?.club?.name ?? "C").trim().charAt(0).toUpperCase();

  const start = async () => {
    // Garde anti-course : en mode période, ne jamais lancer sans le plan résolu — sinon le POST
    // omettrait schedulePlanId et créerait une version de SAISON au lieu de l'overlay. Le bouton
    // est déjà désactivé tant que l'ancre n'est pas prête ; ceci ferme le chemin par sécurité.
    if (periodMode && (null === periodPlanId || !periodAnchorReady)) {
      return;
    }
    setTimedOut(false);
    launch.reset();
    setScheduleId(null);
    try {
      const id = await launch.mutateAsync(
        periodMode
          ? {
              name: periodEntry?.title ?? "Plan de période",
              schedulePlanId: periodPlanId ?? undefined,
              // Reprendre la version en vol (anti-double-lancement) ; sinon créer une
              // version neuve sous le plan (lot D-b — plus de pointeur overlay sur l'entrée).
              existingScheduleId: overlaySchedule?.id ?? undefined,
            }
          : { name: `Planning ${new Date().toLocaleDateString("fr-FR")}` },
      );
      setScheduleId(id);
    } catch {
      // launch.isError drives the failed state below.
    }
  };

  if (showPlanning) {
    return <PlanningPage embedded />;
  }

  return (
    <div>
      <p className="mb-4 text-sm text-muted-foreground">
        {periodMode
          ? "Génère le planning de cette période. Il s'applique par-dessus le planning principal sur la fenêtre, sans le modifier."
          : "Le solveur place tes équipes dans les créneaux selon tes règles. Lance, puis laisse tourner."}
      </p>

      {failed ? (
        <div className="flex flex-col items-center gap-4 py-12 text-center">
          <AlertTriangle className="size-14 text-destructive" />
          <div className="space-y-1">
            <p className="text-lg font-medium">La génération n'a pas abouti.</p>
            <p className="max-w-md text-sm text-muted-foreground">
              {timedOut
                ? "Le service met trop de temps à répondre. Vérifie que le moteur tourne, puis réessaie."
                : "Une erreur est survenue (données ou moteur indisponible). Tu peux réessayer."}
            </p>
          </div>
          <Button size="lg" onClick={start}>
            <Rocket className="size-4" />
            Réessayer
          </Button>
        </div>
      ) : waiting ? (
        <GenerationWaiting initial={initial} logoUrl={me?.club?.logoUrl ?? null} />
      ) : (
        <div className="flex flex-col items-center gap-4 py-12 text-center">
          <Rocket className="size-12 text-accent" />
          <p className="max-w-sm text-sm text-muted-foreground">
            {periodMode ? "Tout est prêt. Génère le plan de la période." : "Tout est prêt. Lance la génération de ton planning."}
          </p>
          {isFirstOverlay ? (
            <p className="max-w-sm rounded-md border border-accent/40 bg-accent/10 px-3 py-2 text-xs text-muted-foreground">
              Premier planning secondaire : il s'appuie sur ton planning principal, qui devient la référence — le modifier ensuite supprimera les plannings secondaires (après confirmation).
            </p>
          ) : null}
          <BlockerList blockers={blockers} className="max-w-md text-left" />
          {/* Period mode: wait for the entry to load so an existing overlay is
              regenerated (not duplicated → backend 422). Also gated by blockers. */}
          <Button size="lg" onClick={start} disabled={gateClosed || (periodMode && (!periodEntry || !periodAnchorReady))}>
            <Rocket className="size-4" />
            {periodMode ? "Générer le planning de période" : "Lancer la génération"}
          </Button>
        </div>
      )}
    </div>
  );
}
