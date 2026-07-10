import { useQueryClient } from "@tanstack/react-query";
import { AlertTriangle, CalendarX2, CheckCircle2 } from "lucide-react";
import { useEffect, useMemo, useRef, useState } from "react";

import { useMe } from "@/features/auth/queries";
import { Button } from "@/shared/components/ui/button";
import { Card, CardDescription, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { Modal } from "@/shared/components/ui/modal";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { FullPageSpinner } from "@/shared/components/ui/spinner";

import { OverlaysExistError } from "./api";
import { DiagnosticsPanel } from "./DiagnosticsPanel";
import { ExportMenu } from "./ExportMenu";
import { GenerationWaiting } from "./GenerationWaiting";
import { availableResources, buildGrid, type Lookups } from "./lib/grid";
import { PlanningToolbar } from "./PlanningToolbar";
import { useCategories, useCoachPlayers, useCoaches, useDiagnostics, useGenerate, useLockSlot, useMoveSlot, useRenameSchedule, useReopenSchedule, useSchedules, useSetBaseline, useSlots, useTeamCoaches, useTeams, useValidateSchedule, useVenues } from "./queries";
import { ResourceFilter } from "./ResourceFilter";
import { SlotDetail } from "./SlotDetail";
import { usePlanningStore } from "./store";
import { WeekGrid } from "./WeekGrid";

const IN_FLIGHT = ["PENDING", "GENERATING"];

/** Latest finished SEASON schedule (VALIDATED or COMPLETED), else the most recent one, else null.
 *  Period overlays (calendarEntryId set) are never auto-selected — they are reached explicitly
 *  from the cockpit "Voir le plan". */
type LandingSchedule = { id: string; status: string; createdAt: string; calendarEntryId: string | null };

export function pickDefaultSchedule(schedules: LandingSchedule[]): string | null {
  const seasonPlans = schedules.filter((s) => null === s.calendarEntryId);
  if (0 === seasonPlans.length) {
    return null;
  }
  const byRecent = [...seasonPlans].sort((a, b) => b.createdAt.localeCompare(a.createdAt));
  return (byRecent.find((s) => "VALIDATED" === s.status || "COMPLETED" === s.status) ?? byRecent[0]).id;
}

/** UX-02: which schedule the planning page opens on. Prefer the season baseline —
 *  but ONLY if it is a finished SEASON plan (never a period overlay, never mid-flight),
 *  else fall back to the latest finished season plan. A stale/overlay baseline must
 *  never land the user on an empty "★ · période". */
export function pickLandingScheduleId(schedules: LandingSchedule[], baselineScheduleId: string | null): string | null {
  const base = schedules.find((s) => s.id === baselineScheduleId && null === s.calendarEntryId);

  return base && !IN_FLIGHT.includes(base.status) ? base.id : pickDefaultSchedule(schedules);
}

function ValidateDialog({ hasAlerts, busy, onConfirm, onCancel }: { hasAlerts: boolean; busy: boolean; onConfirm: () => void; onCancel: () => void }) {
  return (
    <Modal
      label="Valider le planning"
      title={
        <span className="flex items-center gap-2">
          {hasAlerts ? <AlertTriangle aria-hidden="true" className="size-5 text-warning" /> : <CheckCircle2 aria-hidden="true" className="size-5 text-muted-foreground" />}
          Valider ce planning ?
        </span>
      }
      // Block Escape/overlay/X dismissal while the validation is in flight: dismissing
      // mid-request would hide the dialog but let the un-aborted mutation still lock the
      // planning read-only (the raw dialog had no escape at all during busy).
      onClose={() => {
        if (!busy) {
          onCancel();
        }
      }}
    >
      <p className="mt-2 text-sm text-muted-foreground">
        {hasAlerts
          ? "Ce planning présente des alertes du solveur (créneaux non placés, contraintes non satisfaites…). En le validant, vous assumez ces contre-indications sous votre responsabilité. Le planning passera en lecture seule."
          : "Le planning passera en lecture seule (« Validé »). Vous pourrez le rouvrir pour le modifier."}
      </p>
      <div className="mt-6 flex justify-end gap-2">
        <Button variant="outline" size="sm" onClick={onCancel} disabled={busy}>
          Annuler
        </Button>
        <Button size="sm" onClick={onConfirm} disabled={busy}>
          Valider
        </Button>
      </div>
    </Modal>
  );
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

/** `embedded` = rendered inside the wizard's Génération step, where the sticky
 *  wizard header + footer eat extra vertical space, so the grid must be shorter. */
export function PlanningPage({ embedded = false }: { embedded?: boolean } = {}) {
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
      setSelectedScheduleId(pickLandingScheduleId(schedules, baselineScheduleId));
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
  const validateMutation = useValidateSchedule();
  const reopenMutation = useReopenSchedule();
  const setBaselineMutation = useSetBaseline();
  const renameMutation = useRenameSchedule();
  const [validateOpen, setValidateOpen] = useState(false);
  // Reopening the baseline with period overlays → 409; confirm to delete them.
  const [reopenOverlayCount, setReopenOverlayCount] = useState<number | null>(null);

  const reopen = (confirmDeleteOverlays?: boolean) => {
    if (!validScheduleId) {
      return;
    }
    reopenMutation.mutate(
      { id: validScheduleId, confirmDeleteOverlays },
      {
        onSuccess: () => setReopenOverlayCount(null),
        // Generic failures are toasted by the hook (unmount-safe); only the
        // 409 escalation is UI state handled here.
        onError: (error) => {
          if (error instanceof OverlaysExistError) {
            setReopenOverlayCount(error.count);
          }
        },
      },
    );
  };

  const selectedSchedule = schedules.find((s) => s.id === validScheduleId) ?? null;
  const isGenerating = null !== selectedSchedule && IN_FLIGHT.includes(selectedSchedule.status);
  const isReadOnly = null !== selectedSchedule && "VALIDATED" === selectedSchedule.status;
  const actionBusy = validateMutation.isPending || reopenMutation.isPending || setBaselineMutation.isPending || renameMutation.isPending;
  const busy = lockMutation.isPending || moveMutation.isPending;
  const clubInitial = (me?.club?.name ?? "C").trim().charAt(0).toUpperCase();

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
        <h1 className="border-l-[3px] border-accent pl-3 text-2xl font-semibold">Planning</h1>
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
              actionBusy={actionBusy}
              onRegenerate={() => validScheduleId && generateMutation.mutate(validScheduleId)}
              onValidate={() => setValidateOpen(true)}
              onReopen={() => reopen()}
              onSetBaseline={() => validScheduleId && setBaselineMutation.mutate(validScheduleId)}
              onRename={(name) => validScheduleId && renameMutation.mutate({ id: validScheduleId, name, status: selectedSchedule?.status ?? "COMPLETED" })}
              baselineScheduleId={baselineScheduleId}
            />
            <div className="ml-auto flex items-center gap-2">
              {null !== validScheduleId && !isGenerating && slots.length > 0 ? <ExportMenu scheduleId={validScheduleId} venues={venues} /> : null}
              <ResourceFilter viewMode={viewMode} resources={resources} selected={resourceFilter} onToggle={toggleResource} onClear={clearResourceFilter} />
            </div>
          </div>

          {isGenerating ? (
            <GenerationWaiting initial={clubInitial} logoUrl={me?.club?.logoUrl ?? null} />
          ) : 0 === slots.length ? (
            <EmptyState title="Planning vide" description="Ce planning ne contient aucun créneau placé pour le moment." />
          ) : (
            // grid-rows-[minmax(0,1fr)] gives the single row a DEFINITE size (the
            // container height) — with the default `auto` row the children's h-full
            // cannot resolve, the WeekGrid lays out at full content height and
            // overflows the page instead of scrolling internally.
            //
            // The right column only exists when there is something to show: the
            // slot-detail panel (opened on click) or, for an editable planning,
            // the diagnostics. In read-only consultation with no slot selected the
            // grid takes the full width; closing the panel returns to full width.
            (() => {
              const showDetail = null !== selectedCell && null !== selectedSlot;
              const showAside = showDetail || !isReadOnly;
              const height = embedded ? "lg:h-[max(calc(100vh-24rem),26rem)]" : "lg:h-[calc(100vh-16rem)]";
              return (
                <div className={`${showAside ? "lg:grid lg:grid-cols-[minmax(0,1fr)_20rem] lg:grid-rows-[minmax(0,1fr)] lg:gap-4" : ""} ${height}`}>
                  <div className="relative min-w-0 lg:h-full">
                    <WeekGrid model={model} selectedSlotId={selectedSlotId} onSelectSlot={setSelectedSlotId} highlightSlotIds={highlightSlotIds} />
                  </div>
                  {showAside ? (
                    <div className="mt-4 flex min-h-0 flex-col gap-4 lg:mt-0 lg:h-full">
                      {null !== selectedCell && null !== selectedSlot ? (
                        <SlotDetail
                          key={selectedSlot.id}
                          cell={selectedCell}
                          slot={selectedSlot}
                          venues={venues}
                          categoryLabel={categoryLabel}
                          busy={busy}
                          readOnly={isReadOnly}
                          onClose={() => setSelectedSlotId(null)}
                          onToggleLock={() => lockMutation.mutate({ id: selectedSlot.id, lockLevel: selectedCell.locked ? "NONE" : "HARD" })}
                          onMove={(patch) => moveMutation.mutate({ id: selectedSlot.id, patch })}
                        />
                      ) : null}
                      {isReadOnly ? null : (
                        <div className="min-h-[12rem] flex-1">
                          <DiagnosticsPanel diagnostics={diagnostics} slots={slots} lookups={lookups} onHighlight={setHighlightSlotIds} />
                        </div>
                      )}
                    </div>
                  ) : null}
                </div>
              );
            })()
          )}
        </>
      )}

      {validateOpen ? (
        <ValidateDialog
          hasAlerts={diagnostics.length > 0}
          busy={validateMutation.isPending}
          onCancel={() => setValidateOpen(false)}
          onConfirm={() => {
            if (null !== validScheduleId) {
              validateMutation.mutate(validScheduleId, { onSuccess: () => setValidateOpen(false) });
            }
          }}
        />
      ) : null}

      <ConfirmDialog
        open={reopenOverlayCount !== null}
        destructive
        title="Rouvrir le planning principal ?"
        description={`Rouvrir ce planning principal supprimera ${reopenOverlayCount ?? 0} planning${(reopenOverlayCount ?? 0) > 1 ? "s" : ""} secondaire${(reopenOverlayCount ?? 0) > 1 ? "s" : ""} (à refaire ensuite).`}
        confirmLabel="Rouvrir et supprimer"
        onConfirm={() => reopen(true)}
        onCancel={() => setReopenOverlayCount(null)}
      />
    </div>
  );
}
