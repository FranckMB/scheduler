import { useQueryClient } from "@tanstack/react-query";
import { CalendarX2 } from "lucide-react";
import { useEffect, useMemo, useRef, useState } from "react";

import { useMe } from "@/features/auth/queries";
import { Card, CardDescription, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { FullPageSpinner } from "@/shared/components/ui/spinner";

import { DiagnosticsPanel } from "./DiagnosticsPanel";
import { availableResources, buildGrid, type Lookups } from "./lib/grid";
import { PlanningToolbar } from "./PlanningToolbar";
import { useCategories, useCoachPlayers, useCoaches, useDiagnostics, useGenerate, useLockSlot, useMoveSlot, useSchedules, useSlots, useTeamCoaches, useTeams, useVenues } from "./queries";
import { ResourceFilter } from "./ResourceFilter";
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
  const { data: me } = useMe();
  const baselineScheduleId = me?.baselineScheduleId ?? null;
  const { viewMode, selectedScheduleId, selectedSlotId, resourceFilter, setViewMode, setSelectedScheduleId, setSelectedSlotId, toggleResource, clearResourceFilter } =
    usePlanningStore();
  const [highlightSlotIds, setHighlightSlotIds] = useState<Set<string>>(new Set());

  // Keep a valid selection: default to the season base plan, else the latest completed.
  const validScheduleId = schedules.some((s) => s.id === selectedScheduleId) ? selectedScheduleId : null;
  useEffect(() => {
    if (null === validScheduleId && schedules.length > 0) {
      const base = schedules.find((s) => s.id === baselineScheduleId);
      setSelectedScheduleId(base ? base.id : pickDefaultSchedule(schedules));
    }
  }, [validScheduleId, schedules, baselineScheduleId, setSelectedScheduleId]);

  const { data: slots = [] } = useSlots(validScheduleId);
  const { data: diagnostics = [] } = useDiagnostics(validScheduleId);
  const { data: teams = [] } = useTeams();
  const { data: venues = [] } = useVenues();
  const { data: coaches = [] } = useCoaches();
  const { data: categories = [] } = useCategories();
  const { data: teamCoaches = [] } = useTeamCoaches();
  const { data: coachPlayers = [] } = useCoachPlayers();

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

  const lookups: Lookups = useMemo(() => {
    // teamId → main coachId (the engine leaves slot.coachId empty).
    const teamCoach = new Map<string, string>();
    for (const link of teamCoaches) {
      if ("MAIN" === link.role && !teamCoach.has(link.teamId)) {
        teamCoach.set(link.teamId, link.coachId);
      }
    }
    // teamId → coachIds that are players of the team (coach view shows these too).
    const teamPlayerCoaches = new Map<string, string[]>();
    for (const link of coachPlayers) {
      if (link.isActive) {
        teamPlayerCoaches.set(link.teamId, [...(teamPlayerCoaches.get(link.teamId) ?? []), link.coachId]);
      }
    }
    return {
      teams: new Map(teams.map((t) => [t.id, t])),
      venues: new Map(venues.map((v) => [v.id, v])),
      coaches: new Map(coaches.map((c) => [c.id, c])),
      teamCoach,
      teamPlayerCoaches,
    };
  }, [teams, venues, coaches, teamCoaches, coachPlayers]);

  const resources = useMemo(() => availableResources(slots, viewMode, lookups), [slots, viewMode, lookups]);
  const model = useMemo(() => buildGrid(slots, viewMode, lookups, new Set(resourceFilter)), [slots, viewMode, lookups, resourceFilter]);

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

  if (schedulesLoading) {
    return <FullPageSpinner />;
  }

  return (
    <div>
      <div className="mb-4 flex items-center gap-3">
        {me?.club?.logoUrl ? <img src={me.club.logoUrl} alt="" className="size-8 shrink-0 rounded object-contain" /> : null}
        <h1 className="text-2xl font-semibold">Planning</h1>
      </div>

      {0 === schedules.length ? (
        <EmptyState title="Aucun planning" description="Passez par l'assistant pour saisir vos données et générer un premier planning." />
      ) : (
        <>
          <div className="mb-4 flex flex-wrap items-center gap-2">
            <PlanningToolbar
              schedules={schedules}
              selectedScheduleId={validScheduleId}
              onSelectSchedule={setSelectedScheduleId}
              viewMode={viewMode}
              onViewMode={setViewMode}
              isGenerating={isGenerating}
              onRegenerate={() => validScheduleId && generateMutation.mutate(validScheduleId)}
              baselineScheduleId={baselineScheduleId}
            />
            <ResourceFilter viewMode={viewMode} resources={resources} selected={resourceFilter} onToggle={toggleResource} onClear={clearResourceFilter} />
          </div>

          {0 === slots.length ? (
            isGenerating ? (
              <EmptyState title="Génération en cours…" description="Le planning s'affichera dès qu'il est prêt (1 à 3 min)." />
            ) : (
              <EmptyState title="Planning vide" description="Ce planning ne contient aucun créneau placé pour le moment." />
            )
          ) : (
            <div className="lg:grid lg:h-[calc(100vh-16rem)] lg:grid-cols-[minmax(0,1fr)_20rem] lg:gap-4">
              <div className="relative min-w-0 lg:h-full">
                {isGenerating ? (
                  <div className="absolute inset-0 z-30 flex items-center justify-center rounded-lg bg-background/60 text-sm text-muted-foreground backdrop-blur-sm">
                    Génération en cours…
                  </div>
                ) : null}
                <WeekGrid model={model} selectedSlotId={selectedSlotId} onSelectSlot={setSelectedSlotId} highlightSlotIds={highlightSlotIds} />
              </div>
              <div className="mt-4 flex min-h-0 flex-col gap-4 lg:mt-0 lg:h-full">
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
                <div className="min-h-0 flex-1">
                  <DiagnosticsPanel diagnostics={diagnostics} slots={slots} lookups={lookups} onHighlight={setHighlightSlotIds} />
                </div>
              </div>
            </div>
          )}
        </>
      )}
    </div>
  );
}
