import { useQueryClient } from "@tanstack/react-query";
import { CalendarX2 } from "lucide-react";
import { useEffect, useMemo, useRef, useState } from "react";

import { Card, CardDescription, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { FullPageSpinner } from "@/shared/components/ui/spinner";

import type { Diagnostic } from "./api";
import { DiagnosticsPanel } from "./DiagnosticsPanel";
import { buildGrid, type Lookups } from "./lib/grid";
import { PlanningToolbar } from "./PlanningToolbar";
import { useCategories, useCoaches, useDiagnostics, useGenerate, useLockSlot, useMoveSlot, useSchedules, useSlots, useTeams, useVenues } from "./queries";
import { SlotDetail } from "./SlotDetail";
import { usePlanningStore } from "./store";
import { WeekGrid } from "./WeekGrid";

const IN_FLIGHT = ["PENDING", "GENERATING"];

/** Latest COMPLETED schedule, else the most recent one, else null. */
function pickDefaultSchedule(schedules: { id: string; status: string; createdAt: string }[]): string | null {
  if (0 === schedules.length) {
    return null;
  }
  const byRecent = [...schedules].sort((a, b) => b.createdAt.localeCompare(a.createdAt));
  return (byRecent.find((s) => "COMPLETED" === s.status) ?? byRecent[0]).id;
}

function EmptyState({ title, description }: { title: string; description: string }) {
  return (
    <Card className="border-dashed">
      <CardHeader>
        <div className="flex items-center gap-2">
          <CalendarX2 className="size-5 text-muted-foreground" />
          <CardTitle>{title}</CardTitle>
        </div>
        <CardDescription>{description}</CardDescription>
      </CardHeader>
    </Card>
  );
}

export function PlanningPage() {
  const { data: schedules = [], isLoading: schedulesLoading } = useSchedules();
  const { viewMode, selectedScheduleId, selectedSlotId, setViewMode, setSelectedScheduleId, setSelectedSlotId } = usePlanningStore();
  const [selectedDiagnosticId, setSelectedDiagnosticId] = useState<string | null>(null);

  // Keep a valid selection: default to the latest completed schedule.
  const validScheduleId = schedules.some((s) => s.id === selectedScheduleId) ? selectedScheduleId : null;
  useEffect(() => {
    if (null === validScheduleId && schedules.length > 0) {
      setSelectedScheduleId(pickDefaultSchedule(schedules));
    }
  }, [validScheduleId, schedules, setSelectedScheduleId]);

  const { data: slots = [] } = useSlots(validScheduleId);
  const { data: diagnostics = [] } = useDiagnostics(validScheduleId);
  const { data: teams = [] } = useTeams();
  const { data: venues = [] } = useVenues();
  const { data: coaches = [] } = useCoaches();
  const { data: categories = [] } = useCategories();

  const queryClient = useQueryClient();
  const lockMutation = useLockSlot();
  const moveMutation = useMoveSlot();
  const generateMutation = useGenerate();

  const selectedSchedule = schedules.find((s) => s.id === validScheduleId) ?? null;
  const isGenerating = null !== selectedSchedule && IN_FLIGHT.includes(selectedSchedule.status);
  const busy = lockMutation.isPending || moveMutation.isPending;

  // When a running generation finishes, pull the fresh slots + diagnostics.
  const prevStatus = useRef<string | null>(null);
  useEffect(() => {
    const status = selectedSchedule?.status ?? null;
    if (null !== prevStatus.current && IN_FLIGHT.includes(prevStatus.current) && null !== status && !IN_FLIGHT.includes(status)) {
      void queryClient.invalidateQueries({ queryKey: ["slots", validScheduleId] });
      void queryClient.invalidateQueries({ queryKey: ["diagnostics", validScheduleId] });
    }
    prevStatus.current = status;
  }, [selectedSchedule?.status, validScheduleId, queryClient]);

  const selectedSlot = slots.find((s) => s.id === selectedSlotId) ?? null;

  const lookups: Lookups = useMemo(
    () => ({
      teams: new Map(teams.map((t) => [t.id, t])),
      venues: new Map(venues.map((v) => [v.id, v])),
      coaches: new Map(coaches.map((c) => [c.id, c])),
    }),
    [teams, venues, coaches],
  );

  const model = useMemo(() => buildGrid(slots, viewMode, lookups), [slots, viewMode, lookups]);

  const selectedCell = model.cells.find((c) => c.slotId === selectedSlotId) ?? null;
  const categoryLabel = useMemo(() => {
    if (null === selectedCell) {
      return "—";
    }
    const slot = slots.find((s) => s.id === selectedCell.slotId);
    const team = slot ? lookups.teams.get(slot.teamId) : undefined;
    const category = team ? categories.find((c) => c.id === team.sportCategoryId) : undefined;
    return category?.name ?? "—";
  }, [selectedCell, slots, lookups, categories]);

  const highlightSlotIds = useMemo(() => {
    const diagnostic = diagnostics.find((d) => d.id === selectedDiagnosticId);
    if (undefined === diagnostic) {
      return undefined;
    }
    const matches = (slotTeam: string, slotVenue: string, slotCoach: string | null): boolean =>
      (null !== diagnostic.teamId && diagnostic.teamId === slotTeam) ||
      (null !== diagnostic.venueId && diagnostic.venueId === slotVenue) ||
      (null !== diagnostic.coachId && diagnostic.coachId === slotCoach);
    return new Set(slots.filter((s) => matches(s.teamId, s.venueId, s.coachId)).map((s) => s.id));
  }, [diagnostics, selectedDiagnosticId, slots]);

  const onSelectDiagnostic = (diagnostic: Diagnostic) =>
    setSelectedDiagnosticId((current) => (current === diagnostic.id ? null : diagnostic.id));

  if (schedulesLoading) {
    return <FullPageSpinner />;
  }

  return (
    <div>
      <h1 className="mb-4 text-2xl font-semibold">Planning</h1>

      {0 === schedules.length ? (
        <EmptyState
          title="Aucun planning"
          description="La création des équipes, gymnases et la génération arriveront avec l'assistant (prochaine étape)."
        />
      ) : (
        <>
          <PlanningToolbar
            schedules={schedules}
            selectedScheduleId={validScheduleId}
            onSelectSchedule={setSelectedScheduleId}
            viewMode={viewMode}
            onViewMode={setViewMode}
            isGenerating={isGenerating}
            onRegenerate={() => validScheduleId && generateMutation.mutate(validScheduleId)}
          />

          {0 === slots.length ? (
            <EmptyState title="Planning vide" description="Ce planning ne contient aucun créneau placé pour le moment." />
          ) : (
            <div className="grid gap-4 lg:grid-cols-[1fr_20rem]">
              <div className="relative">
                {isGenerating ? (
                  <div className="absolute inset-0 z-30 flex items-center justify-center rounded-lg bg-background/60 text-sm text-muted-foreground backdrop-blur-sm">
                    Génération en cours…
                  </div>
                ) : null}
                <WeekGrid model={model} selectedSlotId={selectedSlotId} onSelectSlot={setSelectedSlotId} highlightSlotIds={highlightSlotIds} />
              </div>
              <div className="flex flex-col gap-4">
                {null !== selectedCell && null !== selectedSlot ? (
                  <SlotDetail
                    key={selectedSlot.id}
                    cell={selectedCell}
                    slot={selectedSlot}
                    venues={venues}
                    categoryLabel={categoryLabel}
                    busy={busy}
                    onClose={() => setSelectedSlotId(null)}
                    onToggleLock={() => lockMutation.mutate({ id: selectedSlot.id, lockLevel: selectedCell.locked ? "NONE" : "HARD" })}
                    onMove={(patch) => moveMutation.mutate({ id: selectedSlot.id, patch })}
                  />
                ) : null}
                <DiagnosticsPanel diagnostics={diagnostics} selectedId={selectedDiagnosticId} onSelect={onSelectDiagnostic} />
              </div>
            </div>
          )}
        </>
      )}
    </div>
  );
}
