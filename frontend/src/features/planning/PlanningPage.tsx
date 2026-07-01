import { CalendarX2 } from "lucide-react";
import { useEffect, useMemo, useState } from "react";

import { Card, CardDescription, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { FullPageSpinner } from "@/shared/components/ui/spinner";

import type { Diagnostic } from "./api";
import { DiagnosticsPanel } from "./DiagnosticsPanel";
import { buildGrid, type Lookups } from "./lib/grid";
import { PlanningToolbar } from "./PlanningToolbar";
import { useCategories, useCoaches, useDiagnostics, useSchedules, useSlots, useTeams, useVenues } from "./queries";
import { SlotDetail } from "./SlotDetail";
import { usePlanningStore } from "./store";
import { WeekGrid } from "./WeekGrid";

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
          />

          {0 === slots.length ? (
            <EmptyState title="Planning vide" description="Ce planning ne contient aucun créneau placé pour le moment." />
          ) : (
            <div className="grid gap-4 lg:grid-cols-[1fr_20rem]">
              <WeekGrid model={model} selectedSlotId={selectedSlotId} onSelectSlot={setSelectedSlotId} highlightSlotIds={highlightSlotIds} />
              <div className="flex flex-col gap-4">
                {null !== selectedCell ? (
                  <SlotDetail cell={selectedCell} categoryLabel={categoryLabel} onClose={() => setSelectedSlotId(null)} />
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
