import { useQueryClient } from "@tanstack/react-query";
import { AlertTriangle, CalendarX2, CheckCircle2, Pencil, Star } from "lucide-react";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useNavigate } from "react-router-dom";

import { useMe, useRenamePlanning, useWorkingSeason } from "@/features/auth/queries";
import { useWizardStore } from "@/features/wizard/store";
// Same ["priority_tiers"] query key as the matches/wizard hooks — one cache entry.
import { usePriorityTiers } from "@/features/matches/queries";
import { Button } from "@/shared/components/ui/button";
import { Card, CardDescription, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { Modal } from "@/shared/components/ui/modal";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { FullPageSpinner } from "@/shared/components/ui/spinner";

import { OverlaysExistError } from "./api";
import { DiagnosticsPanel } from "./DiagnosticsPanel";
import { ExportMenu } from "./ExportMenu";
import { GenerationWaiting } from "./GenerationWaiting";
import { computeEmptySlots } from "./lib/emptySlots";
import { availableResourceGroups, buildGrid, type Lookups } from "./lib/grid";
import { PlanningToolbar } from "./PlanningToolbar";
import { useCategories, useCoachPlayers, useCoaches, useDeleteSchedule, useDiagnostics, useLockSlot, useMoveSlot, useRegenerate, useRegenerateFromVersion, useRegenerateOverlay, useReopenSchedule, useSchedules, useSlots, useTeamCoaches, useTeams, useTrainingSlots, useValidateSchedule, useVenues } from "./queries";
import { ResourceFilter } from "./ResourceFilter";
import { SlotDetail } from "./SlotDetail";

import { pickLandingScheduleId } from "./lib/pickLandingSchedule";
import { isSeasonPlanType, versionsDeletedByValidating } from "./lib/versions";
import { usePlanningStore } from "./store";
import { WeekGrid } from "./WeekGrid";

const IN_FLIGHT = ["PENDING", "GENERATING"];

function ValidateDialog({ hasAlerts, siblingCount, busy, onConfirm, onCancel }: { hasAlerts: boolean; siblingCount: number; busy: boolean; onConfirm: () => void; onCancel: () => void }) {
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
      {siblingCount > 0 ? (
        <p className="mt-3 text-sm font-medium text-foreground">
          Seule cette version sera conservée — {siblingCount > 1 ? `les ${siblingCount} autres versions seront définitivement supprimées` : "l'autre version sera définitivement supprimée"}.
        </p>
      ) : null}
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
  const chosenScheduleId = me?.seasonPlan?.chosenScheduleId ?? null;
  const { viewMode, selectedScheduleId, selectedSlotId, resourceFilter, setViewMode, setSelectedScheduleId, setSelectedSlotId, toggleResource, clearResourceFilter } =
    usePlanningStore();
  const [highlightSlotIds, setHighlightSlotIds] = useState<Set<string>>(new Set());
  // Source partagée avec le cockpit (radar/DayDialog) — une seule dérivation de
  // la saison de travail, plus de copie inline qui pourrait diverger.
  const workingSeason = useWorkingSeason();

  // Keep a valid selection: default to the season base plan, else the latest
  // completed. A selection archived concurrently (sibling validation in another
  // tab) is invalid too — the selector has no option for it.
  const validScheduleId = schedules.some((s) => s.id === selectedScheduleId) ? selectedScheduleId : null;
  useEffect(() => {
    if (null === validScheduleId && schedules.length > 0) {
      setSelectedScheduleId(pickLandingScheduleId(schedules));
    }
  }, [validScheduleId, schedules, chosenScheduleId, setSelectedScheduleId]);

  const { data: slots = [] } = useSlots(validScheduleId);
  const { data: diagnostics = [] } = useDiagnostics(validScheduleId);
  const { data: trainingSlots = [] } = useTrainingSlots();
  const { data: teams = [] } = useTeams();
  const { data: venues = [] } = useVenues();
  const { data: coaches = [] } = useCoaches();
  const { data: tiers = [] } = usePriorityTiers();
  const { data: categories = [] } = useCategories();
  const { data: teamCoaches = [] } = useTeamCoaches();
  const { data: coachPlayers = [] } = useCoachPlayers();

  const queryClient = useQueryClient();
  const navigate = useNavigate();
  const lockMutation = useLockSlot();
  const moveMutation = useMoveSlot();
  const regenerateMutation = useRegenerate();
  const regenerateOverlayMutation = useRegenerateOverlay();
  const validateMutation = useValidateSchedule();
  const reopenMutation = useReopenSchedule();
  const deleteMutation = useDeleteSchedule();
  const regenerateFromMutation = useRegenerateFromVersion();
  const [regenerateFromOpen, setRegenerateFromOpen] = useState(false);
  const renamePlanning = useRenamePlanning();
  const [editingPlanningName, setEditingPlanningName] = useState<string | null>(null);
  // Diagnostics panel collapsed by default (user request): the grid gets the
  // full width for verification, a compact bar re-opens the aside on demand.
  const [diagnosticsCollapsed, setDiagnosticsCollapsed] = useState(true);
  const [validateOpen, setValidateOpen] = useState(false);
  // Reopening the baseline with period overlays → 409; confirm to delete them.
  const [reopenOverlayCount, setReopenOverlayCount] = useState<number | null>(null);

  // Validating a non-baseline version with overlays → 409 escalation (same
  // destructive idiom as reopen): confirm, then re-POST with the flag.
  const [validateOverlayCount, setValidateOverlayCount] = useState<number | null>(null);
  const validate = (confirmDeleteOverlays?: boolean) => {
    if (!validScheduleId) {
      return;
    }
    validateMutation.mutate(
      { id: validScheduleId, confirmDeleteOverlays },
      {
        onSuccess: () => {
          setValidateOverlayCount(null);
          setValidateOpen(false);
          // Validated → land on the (now read-only) planning view.
          navigate("/planning");
        },
        onError: (error) => {
          if (error instanceof OverlaysExistError) {
            setValidateOpen(false);
            setValidateOverlayCount(error.count);
          }
        },
      },
    );
  };

  const reopen = (confirmDeleteOverlays?: boolean) => {
    if (!validScheduleId) {
      return;
    }
    reopenMutation.mutate(
      { id: validScheduleId, confirmDeleteOverlays },
      {
        onSuccess: () => {
          setReopenOverlayCount(null);
          // Reopened to rework the plan → back to the wizard's generation step.
          useWizardStore.getState().jumpTo("generate");
          navigate("/wizard");
        },
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
  // Read-only = its plan points at it: this version IS the calendar in force.
  const isReadOnly = true === selectedSchedule?.isChosen;
  const regenerateDisabled =
    null !== selectedSchedule
    && isSeasonPlanType(selectedSchedule.planType)
    && selectedSchedule.snapshotHash === me?.seasonPlan?.currentStructureHash;
  // regenerateFromMutation.isPending: "Charger cette version" no longer creates a
  // PENDING schedule (nothing sets isGenerating), so its own restore must disable
  // the action here — else a second click double-runs the destructive restore.
  const actionBusy = validateMutation.isPending || reopenMutation.isPending || deleteMutation.isPending || regenerateFromMutation.isPending;
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

  // Defined venue windows the solver left unfilled ("créneaux vides"). Injected
  // into the grid in the GYMNASE view only (they have no team/coach) so they
  // show as `vide` cells even without a click; also listed as warnings below.
  const emptySlots = useMemo(() => computeEmptySlots(trainingSlots, slots, validScheduleId ?? ""), [trainingSlots, slots, validScheduleId]);
  const gridSlots = useMemo(() => ("gymnase" === viewMode ? [...slots, ...emptySlots] : slots), [viewMode, slots, emptySlots]);

  // From gridSlots (incl. empty windows in gymnase view) so a venue that has ONLY
  // empty slots still appears in the ResourceFilter picker — otherwise focusVenue
  // could filter to a venue the picker cannot show/clear.
  const resourceGroups = useMemo(() => availableResourceGroups(gridSlots, viewMode, lookups, tiers), [gridSlots, viewMode, lookups, tiers]);
  const model = useMemo(() => buildGrid(gridSlots, viewMode, lookups, new Set(resourceFilter)), [gridSlots, viewMode, lookups, resourceFilter]);

  // Clicking the solver's "unused_slot" warning brings its venue column on screen
  // (venue view, filtered to that venue) so the concerned `vide` cell is visible.
  const focusVenue = useCallback(
    (venueId: string) => {
      setViewMode("gymnase");
      clearResourceFilter();
      toggleResource(venueId);
    },
    [setViewMode, clearResourceFilter, toggleResource],
  );

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

  const planningTitle = me?.seasonPlan?.name ?? "Planning";
  // Nom du fichier exporté = nom du PLANNING affiché : la période pour un overlay,
  // le nom du plan de saison sinon (retour fondateur 2026-07-18).
  const exportName = null !== selectedSchedule && !isSeasonPlanType(selectedSchedule.planType) ? selectedSchedule.name : (me?.seasonPlan?.name ?? null);
  const structureDiverged =
    null !== selectedSchedule && isSeasonPlanType(selectedSchedule.planType)
    && typeof selectedSchedule.generatedTeamCount === "number" && teams.length > 0
    && selectedSchedule.generatedTeamCount !== teams.length;

  return (
    <div>
      <div className="mb-4 flex items-center gap-3">
        {me?.club?.logoUrl ? <img src={me.club.logoUrl} alt="" className="size-8 shrink-0 rounded object-contain" /> : null}
        {null !== editingPlanningName ? (
          <input
            // eslint-disable-next-line jsx-a11y/no-autofocus -- inline rename field revealed on demand
            autoFocus
            aria-label="Nom du planning"
            value={editingPlanningName}
            onChange={(e) => setEditingPlanningName(e.target.value)}
            onKeyDown={(e) => {
              if ("Enter" === e.key) {
                if (me?.seasonPlan) {
                  renamePlanning.mutate({ planId: me.seasonPlan.id, name: editingPlanningName.trim() });
                }
                setEditingPlanningName(null);
              } else if ("Escape" === e.key) {
                setEditingPlanningName(null);
              }
            }}
            onBlur={() => setEditingPlanningName(null)}
            className="h-9 rounded-md border border-input bg-background px-3 text-xl font-semibold"
          />
        ) : (
          <>
            {/* ADR-0002 inv. 12: THE plan's name lives here, on the plan — not in the version selector. */}
            <h1 className="border-l-[3px] border-accent pl-3 text-2xl font-semibold">{planningTitle}</h1>
            {/* « principal » qualifie LE planning de la saison (le plan SEASON), par
                opposition aux plannings secondaires de période — pas la version choisie. */}
            {null !== selectedSchedule && isSeasonPlanType(selectedSchedule.planType) ? (
              <span className="flex items-center gap-1 rounded-full bg-accent px-2 py-0.5 text-xs font-medium text-accent-foreground">
                <Star className="size-3" />
                principal
              </span>
            ) : null}
            {workingSeason && !workingSeason.isReadonly ? (
              <Button size="sm" variant="ghost" className="h-8 px-2" aria-label="Renommer le planning" title="Renommer le planning" onClick={() => setEditingPlanningName(me?.seasonPlan?.name ?? "")}>
                <Pencil className="size-4" />
              </Button>
            ) : null}
          </>
        )}
      </div>

      {structureDiverged && "number" === typeof selectedSchedule?.generatedTeamCount ? (
        <p className="mb-4 rounded-md border border-warning/40 bg-warning/10 px-3 py-2 text-sm text-foreground">
          Cette version a été générée le {new Date(selectedSchedule.createdAt).toLocaleDateString("fr-FR")} avec {selectedSchedule.generatedTeamCount} équipe{selectedSchedule.generatedTeamCount > 1 ? "s" : ""} — la structure du club a changé depuis ({teams.length} aujourd'hui).
        </p>
      ) : null}


      {0 === schedules.length ? (
        <EmptyState title="Aucun planning" description="Passez par l'assistant pour saisir vos données et générer un premier planning." />
      ) : (
        <>
          <div className="mb-4">
            <PlanningToolbar
              schedules={schedules}
              selectedScheduleId={validScheduleId}
              onSelectSchedule={setSelectedScheduleId}
              viewMode={viewMode}
              onViewMode={setViewMode}
              isGenerating={isGenerating || regenerateMutation.isPending || regenerateOverlayMutation.isPending}
              actionBusy={actionBusy}
              disableRegenerate={regenerateDisabled}
              onRegenerate={() => {
                if (null === validScheduleId) {
                  return;
                }
                const select = { onSuccess: (created: { id: string }) => setSelectedScheduleId(created.id) };
                // An overlay "Régénérer" creates a NEW version UNDER its period's plan
                // (ADR-0002 C4); a season plan regenerates from the current structure.
                const overlayPlanId = !isSeasonPlanType(selectedSchedule?.planType) ? selectedSchedule?.schedulePlanId ?? null : null;
                if (null !== overlayPlanId) {
                  regenerateOverlayMutation.mutate(overlayPlanId, select);
                } else {
                  regenerateMutation.mutate(validScheduleId, select);
                }
              }}
              onValidate={() => setValidateOpen(true)}
              onReopen={() => reopen()}
              onDelete={() => validScheduleId && deleteMutation.mutate(validScheduleId)}
              onRegenerateFrom={() => setRegenerateFromOpen(true)}
              embedded={embedded}
              rightSlot={
                <>
                  {null !== validScheduleId && !isGenerating && slots.length > 0 ? <ExportMenu scheduleId={validScheduleId} venues={venues} exportName={exportName} /> : null}
                  <ResourceFilter viewMode={viewMode} groups={resourceGroups} selected={resourceFilter} onToggle={toggleResource} onClear={clearResourceFilter} />
                </>
              }
            />
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
              // The diagnostics aside only claims grid width when it has content
              // to show: a selected slot's detail, or the (expanded) diagnostics.
              const showDiagnostics = !isReadOnly && !diagnosticsCollapsed;
              const showAside = showDetail || showDiagnostics;
              const height = embedded ? "lg:h-[max(calc(100vh-24rem),26rem)]" : "lg:h-[calc(100vh-16rem)]";
              return (
                <div className={`${showAside ? "lg:grid lg:grid-cols-[minmax(0,1fr)_20rem] lg:grid-rows-[minmax(0,1fr)] lg:gap-4" : ""} ${height}`}>
                  {/* min-h-0 is essential: without it the flex-1 grid wrapper keeps
                      its content height and overflows past the container, spilling
                      under the sticky footer (revue #204 — grille coupée en 2). */}
                  <div className="relative flex min-h-0 min-w-0 flex-col gap-2 lg:h-full">
                    {/* Collapsed diagnostics → a compact bar re-opens the aside;
                        the grid keeps full width until then (user request). */}
                    {!isReadOnly && diagnosticsCollapsed ? (
                      <button
                        type="button"
                        onClick={() => setDiagnosticsCollapsed(false)}
                        className="flex shrink-0 items-center gap-2 self-start rounded-md border border-border px-2 py-1 text-sm hover:bg-muted"
                      >
                        <AlertTriangle className={`size-4 ${diagnostics.length > 0 ? "text-warning" : "text-muted-foreground"}`} />
                        Diagnostics du solveur
                        {diagnostics.length > 0 ? <span className="rounded-full bg-muted px-1.5 text-xs text-muted-foreground">{diagnostics.length}</span> : null}
                      </button>
                    ) : null}
                    <div className="relative min-h-0 min-w-0 flex-1">
                      <WeekGrid model={model} selectedSlotId={selectedSlotId} onSelectSlot={setSelectedSlotId} highlightSlotIds={highlightSlotIds} />
                    </div>
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
                      {showDiagnostics ? (
                        <div className="min-h-[12rem] flex-1">
                          <DiagnosticsPanel diagnostics={diagnostics} slots={slots} emptySlots={emptySlots} lookups={lookups} onHighlight={setHighlightSlotIds} onFocusVenue={focusVenue} onCollapse={() => setDiagnosticsCollapsed(true)} />
                        </div>
                      ) : null}
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
          siblingCount={null === selectedSchedule ? 0 : versionsDeletedByValidating(schedules, selectedSchedule).length}
          busy={validateMutation.isPending}
          onCancel={() => setValidateOpen(false)}
          onConfirm={() => validate()}
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

      <ConfirmDialog
        open={validateOverlayCount !== null}
        title="Valider cette version et remplacer le planning principal ?"
        description={`Cette version deviendra le planning principal ; ${validateOverlayCount ?? 0} planning${(validateOverlayCount ?? 0) > 1 ? "s" : ""} de période bâti${(validateOverlayCount ?? 0) > 1 ? "s" : ""} sur l'ancien principal ser${(validateOverlayCount ?? 0) > 1 ? "ont" : "a"} supprimé${(validateOverlayCount ?? 0) > 1 ? "s" : ""} (à refaire ensuite).`}
        confirmLabel="Valider et remplacer"
        destructive
        onConfirm={() => validate(true)}
        onCancel={() => setValidateOverlayCount(null)}
      />

      <ConfirmDialog
        open={regenerateFromOpen}
        title="Charger cette version ?"
        description={
          "number" === typeof selectedSchedule?.generatedTeamCount ? (
            <>
              La structure actuelle ({teams.length} équipe{teams.length > 1 ? "s" : ""}) sera remplacée par celle de cette version ({selectedSchedule.generatedTeamCount} équipe{selectedSchedule.generatedTeamCount > 1 ? "s" : ""}) et son planning s'affichera. Les données de structure actuelles seront écrasées ; vous pourrez ensuite « Régénérer » pour créer une nouvelle version.
            </>
          ) : null
        }
        confirmLabel="Charger"
        destructive
        onConfirm={() => {
          if (null !== validScheduleId) {
            regenerateFromMutation.mutate(validScheduleId, { onSuccess: (created) => setSelectedScheduleId(created.id) });
          }
          setRegenerateFromOpen(false);
        }}
        onCancel={() => setRegenerateFromOpen(false)}
      />
    </div>
  );
}
