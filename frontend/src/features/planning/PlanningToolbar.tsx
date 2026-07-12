import { CheckCircle2, History, Lock, LockOpen, RefreshCw, Trash2 } from "lucide-react";
import { type ReactNode, useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { DeleteConfirm } from "@/shared/components/ui/delete-confirm";
import { cn } from "@/shared/lib/utils";

import { STATUS_LABELS, type Schedule } from "./api";
import { liveContextScheduleId, overlayVersionLabels, versionLabels, visibleOverlayVersions, visibleSeasonPlans } from "./lib/versions";
import type { ViewMode } from "./store";

const VIEWS: { key: ViewMode; label: string }[] = [
  { key: "gymnase", label: "Par gymnase" },
  { key: "coach", label: "Par coach" },
  { key: "equipe", label: "Par équipe" },
];

interface PlanningToolbarProps {
  schedules: Schedule[];
  selectedScheduleId: string | null;
  onSelectSchedule: (id: string) => void;
  viewMode: ViewMode;
  onViewMode: (mode: ViewMode) => void;
  onRegenerate: () => void;
  onValidate: () => void;
  onReopen: () => void;
  onDelete: () => void;
  onRegenerateFrom: () => void;
  isGenerating: boolean;
  actionBusy: boolean;
  baselineScheduleId: string | null;
  /** Export + resource filter, rendered right-aligned on the actions row (owned by the page). */
  rightSlot?: ReactNode;
  /** Wizard-embedded (generation step) vs standalone /planning consultation. The
   *  standalone view hides the version selector, the status badge and the score
   *  — version management lives in the wizard, /planning is for consulting. */
  embedded?: boolean;
}

/**
 * planning-versions: the selector lists the WORK VERSIONS of the season plan
 * ("V3 — 10 juil. 14:32", newest last), never named schedules — the plan's
 * NAME lives in the page header (the displayed Schedule's name). Versions are
 * not renamable; a version can be deleted (workspace) behind a DeleteConfirm.
 *
 * The season's MAIN plan (baseline) is never chosen here: the first validated
 * plan is the baseline, as a fact — there is no "set as main" action. Two rows:
 * (1) version + state + view mode, (2) generation actions + export/filter.
 */
export function PlanningToolbar({
  schedules,
  selectedScheduleId,
  onSelectSchedule,
  viewMode,
  onViewMode,
  onRegenerate,
  onValidate,
  onReopen,
  onDelete,
  onRegenerateFrom,
  isGenerating,
  actionBusy,
  baselineScheduleId,
  rightSlot,
  embedded = false,
}: PlanningToolbarProps) {
  const selected = schedules.find((s) => s.id === selectedScheduleId) ?? null;
  const isBaseline = null !== selected && selected.id === baselineScheduleId;
  const isValidated = null !== selected && "VALIDATED" === selected.status;
  const isCompleted = null !== selected && "COMPLETED" === selected.status;
  const isOverlay = null !== selected && null !== selected.calendarEntryId;
  // ★ = the version whose structure is the currently LOADED context. Season plans:
  // the server pointer (seasonLiveContextId, with a latest-visible fallback for a
  // NULL/stale pointer). Overlays: the latest version of the period (derived).
  // Exactly ONE ★: in overlay context only the period's overlay is starred (never
  // a season plan too), else only the season live-context plan.
  const seasonLiveId = liveContextScheduleId(schedules, null);
  const overlayLiveId = isOverlay && null !== selected?.calendarEntryId ? liveContextScheduleId(schedules, selected.calendarEntryId) : null;
  const isStarred = (schedule: Schedule): boolean =>
    isOverlay ? null !== schedule.calendarEntryId && schedule.id === overlayLiveId : null === schedule.calendarEntryId && schedule.id === seasonLiveId;
  const isInFlight = null !== selected && ("PENDING" === selected.status || "GENERATING" === selected.status);
  const [confirmDelete, setConfirmDelete] = useState(false);

  const labels = versionLabels(schedules);
  // When an overlay is selected, its period's versions get their own V{n} labels.
  const overlayLabels = isOverlay && null !== selected?.calendarEntryId ? overlayVersionLabels(schedules, selected.calendarEntryId) : null;
  const labelOf = (schedule: Schedule): string => overlayLabels?.get(schedule.id) ?? labels.get(schedule.id) ?? schedule.name;
  // Deletable = a plain work version: never the baseline (anchors the season),
  // never VALIDATED (read-only), never mid-solve, never an overlay.
  const canDelete = null !== selected && !isBaseline && !isValidated && !isInFlight && !isOverlay;
  // "Load this version" (restore its structure + regenerate) is offered only on a
  // finished, non-locked COMPLETED version (a VALIDATED one is read-only — reopen
  // first; the backend refuses it anyway) that actually carries a structure photo:
  // a pre-D2 plan has a solver payload but no photo, so the restore would 409 —
  // don't offer an action that cannot succeed.
  const canRegenerateFrom = null !== selected && isCompleted && !isOverlay && true === selected.hasStructurePhoto;
  // Reloading the version that IS the live context (★) is a no-op — its structure
  // is already the current one (that would just be "Régénérer"). Keep the button
  // visible but greyed with a reason, so the state reads as deliberate. Uses the
  // fallback id so a NULL/stale pointer still disables it on the current version.
  const isLiveContext = null !== selected && null === selected.calendarEntryId && selected.id === seasonLiveId;

  return (
    <div className="flex w-full flex-col gap-2">
      {/* Row 1 — which version, its state, and how to view it. Standalone /planning
          (consultation) hides the version selector, status badge and score:
          version management lives in the wizard's generation step (embedded). */}
      <div className="flex flex-wrap items-center gap-2">
        {embedded ? (
          <select
            aria-label="Version du planning"
            value={selectedScheduleId ?? ""}
            onChange={(event) => onSelectSchedule(event.target.value)}
            className="h-8 rounded-md border border-input bg-background px-3 text-sm"
          >
            {/* Season versions, plus — when an overlay is selected — that period's own
                overlay versions (V1, V2…). The ★ marks the LOADED context (the version
                whose structure is live), NOT the one being viewed: it stays put when you
                consult an older version, and "Charger cette version" moves it. No
                "principal" here: the main plan is a fact carried by the title badge. */}
            {[...visibleSeasonPlans(schedules), ...(isOverlay && null !== selected?.calendarEntryId ? visibleOverlayVersions(schedules, selected.calendarEntryId) : [])].map((schedule) => (
              <option key={schedule.id} value={schedule.id}>
                {labelOf(schedule)}
                {isStarred(schedule) ? " ★" : ""}
                {"VALIDATED" === schedule.status ? " · validé" : ""}
                {null !== schedule.calendarEntryId ? " · période" : ""}
              </option>
            ))}
          </select>
        ) : null}
        {embedded && canDelete ? (
          <Button size="sm" variant="ghost" className="h-8 px-2 text-destructive" disabled={actionBusy} onClick={() => setConfirmDelete(true)} aria-label="Supprimer cette version" title="Supprimer cette version">
            <Trash2 className="size-4" />
          </Button>
        ) : null}
        {embedded && selected ? (
          <span className="flex items-center gap-2 text-xs text-muted-foreground">
            <span className="flex items-center gap-1 rounded-full bg-muted px-2 py-0.5">
              {isValidated ? <Lock className="size-3" /> : null}
              {STATUS_LABELS[selected.status]}
            </span>
            {null !== selected.score ? <span>score {selected.score}</span> : null}
            {null !== selected.calendarEntryId ? (
              <span className="rounded-full border border-accent/50 px-2 py-0.5 font-medium text-accent">Période</span>
            ) : null}
          </span>
        ) : null}
        {isCompleted ? (
          <Button size="sm" variant="outline" className="h-8" disabled={actionBusy} onClick={onValidate}>
            <CheckCircle2 className="size-4" />
            Valider
          </Button>
        ) : null}
        {isValidated ? (
          <Button size="sm" variant="outline" className="h-8" disabled={actionBusy} onClick={onReopen}>
            <LockOpen className="size-4" />
            Rouvrir
          </Button>
        ) : null}
        <div className="ml-auto flex items-center gap-1 rounded-md border border-border p-0.5">
          {VIEWS.map((view) => (
            <Button
              key={view.key}
              size="sm"
              variant={view.key === viewMode ? "default" : "ghost"}
              className={cn("h-7", view.key === viewMode ? "" : "text-muted-foreground")}
              onClick={() => onViewMode(view.key)}
            >
              {view.label}
            </Button>
          ))}
        </div>
      </div>

      {/* Row 2 — generation actions, with export + filter right-aligned. */}
      <div className="flex flex-wrap items-center gap-2">
        {isValidated ? null : (
          // Disabled during a "Charger" restore too (actionBusy) — but the busy
          // LABEL/spinner keys only on isGenerating, so a restore (no solve) does
          // not show a misleading "Génération…".
          <Button size="sm" variant="default" className="h-8" disabled={isGenerating || actionBusy || null === selectedScheduleId} onClick={onRegenerate}>
            <RefreshCw className={cn("size-4", isGenerating ? "animate-spin" : "")} />
            {isGenerating ? "Génération…" : "Régénérer"}
          </Button>
        )}
        {canRegenerateFrom ? (
          <Button
            size="sm"
            variant="ghost"
            className="h-8"
            disabled={actionBusy || isGenerating || isLiveContext}
            onClick={onRegenerateFrom}
            title={isLiveContext ? "Déjà le contexte courant — rien à recharger (utilisez « Régénérer »)" : "Recharge la structure de cette version (sans régénérer) et affiche son planning"}
          >
            <History className="size-4" />
            Charger cette version
          </Button>
        ) : null}
        {rightSlot ? <div className="ml-auto flex items-center gap-2">{rightSlot}</div> : null}
      </div>

      <DeleteConfirm
        open={confirmDelete}
        entityName={selected ? labelOf(selected) : ""}
        impacts={[]}
        onConfirm={() => {
          onDelete();
          setConfirmDelete(false);
        }}
        onCancel={() => setConfirmDelete(false)}
      />
    </div>
  );
}
