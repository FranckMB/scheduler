import { useQueryClient } from "@tanstack/react-query";
import { AlertTriangle, Rocket } from "lucide-react";
import { useEffect, useRef, useState } from "react";

import { useMe } from "@/features/auth/queries";
import { useCalendarEntry } from "@/features/cockpit/queries";
import type { ScheduleStatus } from "@/features/planning/api";
import { GenerationWaiting } from "@/features/planning/GenerationWaiting";
import { PlanningPage } from "@/features/planning/PlanningPage";
import { useSchedules } from "@/features/planning/queries";
import { usePlanningStore } from "@/features/planning/store";
import { Button } from "@/shared/components/ui/button";

import { useLaunchGeneration, useScheduleStatus } from "../queries";
import { useWizardStore } from "../store";

const IN_FLIGHT: ScheduleStatus[] = ["PENDING", "GENERATING"];

// Hard client-side guard: if the backend/engine never answers, stop waiting
// instead of polling forever and surface a retry.
const TIMEOUT_MS = 5 * 60 * 1000;

export function GenerateStep() {
  const queryClient = useQueryClient();
  const { data: me } = useMe();
  const { reservations, clearReservations, mode, calendarEntryId } = useWizardStore();
  const periodMode = "period" === mode;
  const { data: periodEntry } = useCalendarEntry(periodMode ? calendarEntryId : null);
  const setSelectedScheduleId = usePlanningStore((s) => s.setSelectedScheduleId);

  const { data: schedules = [] } = useSchedules();
  const launch = useLaunchGeneration();
  const [scheduleId, setScheduleId] = useState<string | null>(null);
  const [timedOut, setTimedOut] = useState(false);

  const { data: sched } = useScheduleStatus(scheduleId);
  const status = sched?.status ?? null;

  // Season mode keys completion off ANY completed schedule (first launch or
  // regeneration). Period mode keys strictly off THIS overlay's status.
  const overlayDone = periodMode && "COMPLETED" === status;
  const hasCompleted = periodMode ? overlayDone : schedules.some((s) => "COMPLETED" === s.status);
  const anyInFlight = schedules.some((s) => IN_FLIGHT.includes(s.status));
  const showPlanning = periodMode ? overlayDone : hasCompleted || (anyInFlight && null === scheduleId);

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
    clearReservations();
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
  }, [hasCompleted, periodMode, scheduleId, clearReservations, setSelectedScheduleId, queryClient]);

  const launching = launch.isPending;
  const failed = !launching && !showPlanning && (launch.isError || "FAILED" === status || timedOut);
  const waiting = !showPlanning && (launching || (null !== scheduleId && "FAILED" !== status && !timedOut));

  const initial = (me?.club?.name ?? "C").trim().charAt(0).toUpperCase();

  const start = async () => {
    setTimedOut(false);
    launch.reset();
    setScheduleId(null);
    try {
      const id = await launch.mutateAsync(
        periodMode
          ? {
              name: periodEntry?.title ?? "Plan de période",
              reservations,
              calendarEntryId: calendarEntryId ?? undefined,
              existingScheduleId: periodEntry?.overlayScheduleId ?? undefined,
            }
          : { name: `Planning ${new Date().toLocaleDateString("fr-FR")}`, reservations },
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
          ? "Génère le plan de cette période (overlay). Il surcharge le planning principal sur la fenêtre, sans toucher au socle."
          : "Le solveur place vos équipes dans les créneaux selon vos règles. Lancez, puis laissez tourner."}
      </p>

      {failed ? (
        <div className="flex flex-col items-center gap-4 py-12 text-center">
          <AlertTriangle className="size-14 text-destructive" />
          <div className="space-y-1">
            <p className="text-lg font-medium">La génération n'a pas abouti.</p>
            <p className="max-w-md text-sm text-muted-foreground">
              {timedOut
                ? "Le service met trop de temps à répondre. Vérifiez que le moteur tourne, puis réessayez."
                : "Une erreur est survenue (données ou moteur indisponible). Vous pouvez réessayer."}
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
            {periodMode ? "Tout est prêt. Génère le plan de la période." : "Tout est prêt. Lancez la génération de votre planning."}
          </p>
          {/* Period mode: wait for the entry to load so an existing overlay is
              regenerated (not duplicated → backend 422). */}
          <Button size="lg" onClick={start} disabled={periodMode && !periodEntry}>
            <Rocket className="size-4" />
            {periodMode ? "Générer le plan de période" : "Lancer la génération"}
          </Button>
        </div>
      )}
    </div>
  );
}
