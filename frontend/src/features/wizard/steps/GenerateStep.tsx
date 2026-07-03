import { useQueryClient } from "@tanstack/react-query";
import { AlertTriangle, Rocket } from "lucide-react";
import { useEffect, useRef, useState } from "react";

import { useMe } from "@/features/auth/queries";
import type { ScheduleStatus } from "@/features/planning/api";
import { GenerationWaiting } from "@/features/planning/GenerationWaiting";
import { PlanningPage } from "@/features/planning/PlanningPage";
import { useSchedules } from "@/features/planning/queries";

const IN_FLIGHT: ScheduleStatus[] = ["PENDING", "GENERATING"];
import { Button } from "@/shared/components/ui/button";

import { useLaunchGeneration, useScheduleStatus } from "../queries";
import { useWizardStore } from "../store";

// Hard client-side guard: if the backend/engine never answers, stop waiting
// instead of polling forever and surface a retry.
const TIMEOUT_MS = 5 * 60 * 1000;

export function GenerateStep() {
  const queryClient = useQueryClient();
  const { data: me } = useMe();
  const { reservations, clearReservations } = useWizardStore();
  const { data: schedules = [] } = useSchedules();
  const launch = useLaunchGeneration();
  const [scheduleId, setScheduleId] = useState<string | null>(null);
  const [timedOut, setTimedOut] = useState(false);

  const { data: sched } = useScheduleStatus(scheduleId);
  const status = sched?.status ?? null;
  const hasCompleted = schedules.some((s) => "COMPLETED" === s.status);
  const anyInFlight = schedules.some((s) => IN_FLIGHT.includes(s.status));
  // Show the embedded planning once a plan exists, and keep showing it while a
  // regeneration runs (schedule leaves COMPLETED → still an in-flight schedule,
  // not a first launch). The fancy waiting screen stays for the very first run
  // (identified by a local scheduleId, cleared once the plan completes).
  const showPlanning = hasCompleted || (anyInFlight && null === scheduleId);

  // Stop waiting after the timeout even if the schedule is stuck GENERATING.
  useEffect(() => {
    if (null === scheduleId) {
      return;
    }
    const t = setTimeout(() => setTimedOut(true), TIMEOUT_MS);
    return () => clearTimeout(t);
  }, [scheduleId]);

  // Once a plan exists: drop the reservations (now applied) and refresh /me so
  // the wizard nav unlocks (onboarding is completed at queue time server-side).
  const settled = useRef(false);
  useEffect(() => {
    if (hasCompleted && !settled.current) {
      settled.current = true;
      clearReservations();
      // Drop the first-run local id so later regenerations (via the planning
      // toolbar) are recognised as regenerations, not a fresh launch.
      setScheduleId(null);
      void queryClient.invalidateQueries({ queryKey: ["me"] });
    }
  }, [hasCompleted, clearReservations, queryClient]);

  const launching = launch.isPending;
  const failed = !launching && !showPlanning && (launch.isError || "FAILED" === status || timedOut);
  const waiting = !showPlanning && (launching || (null !== scheduleId && "FAILED" !== status && !timedOut));

  const initial = (me?.club?.name ?? "C").trim().charAt(0).toUpperCase();

  const start = async () => {
    setTimedOut(false);
    launch.reset();
    setScheduleId(null);
    try {
      const id = await launch.mutateAsync({ name: `Planning ${new Date().toLocaleDateString("fr-FR")}`, reservations });
      setScheduleId(id);
    } catch {
      // launch.isError drives the failed state below.
    }
  };

  // Plan ready (or regenerating) → show the planning inline (same screen).
  if (showPlanning) {
    return <PlanningPage embedded />;
  }

  return (
    <div>
      <p className="mb-4 text-sm text-muted-foreground">Le solveur place vos équipes dans les créneaux selon vos règles. Lancez, puis laissez tourner.</p>

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
          <p className="max-w-sm text-sm text-muted-foreground">Tout est prêt. Lancez la génération de votre planning.</p>
          <Button size="lg" onClick={start}>
            <Rocket className="size-4" />
            Lancer la génération
          </Button>
        </div>
      )}
    </div>
  );
}
