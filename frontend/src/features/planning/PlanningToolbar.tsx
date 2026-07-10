import { CheckCircle2, History, Lock, LockOpen, RefreshCw, Star, Trash2 } from "lucide-react";
import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { DeleteConfirm } from "@/shared/components/ui/delete-confirm";
import { cn } from "@/shared/lib/utils";

import { STATUS_LABELS, type Schedule } from "./api";
import { versionLabels, visibleSeasonPlans } from "./lib/versions";
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
  onSetBaseline: () => void;
  onDelete: () => void;
  onRegenerateFrom: () => void;
  isGenerating: boolean;
  actionBusy: boolean;
  baselineScheduleId: string | null;
}

/**
 * planning-versions: the selector lists the WORK VERSIONS of the season plan
 * ("V3 — 10 juil. 14:32", newest last), never named schedules — the plan's
 * NAME lives in the page header (Season.planningName). Versions are not
 * renamable; a version can be deleted (workspace) behind a DeleteConfirm.
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
  onSetBaseline,
  onDelete,
  onRegenerateFrom,
  isGenerating,
  actionBusy,
  baselineScheduleId,
}: PlanningToolbarProps) {
  const selected = schedules.find((s) => s.id === selectedScheduleId) ?? null;
  const isBaseline = null !== selected && selected.id === baselineScheduleId;
  const isValidated = null !== selected && "VALIDATED" === selected.status;
  const isCompleted = null !== selected && "COMPLETED" === selected.status;
  const isFinished = isValidated || isCompleted;
  const isOverlay = null !== selected && null !== selected.calendarEntryId;
  const isInFlight = null !== selected && ("PENDING" === selected.status || "GENERATING" === selected.status);
  const [confirmDelete, setConfirmDelete] = useState(false);

  const labels = versionLabels(schedules);
  const labelOf = (schedule: Schedule): string => labels.get(schedule.id) ?? schedule.name;
  // Deletable = a plain work version: never the baseline (anchors the season),
  // never VALIDATED (read-only), never mid-solve, never an overlay.
  const canDelete = null !== selected && !isBaseline && !isValidated && !isInFlight && !isOverlay;
  // "Regenerate under this version's conditions" needs its D2 structure photo.
  const canRegenerateFrom = null !== selected && isFinished && !isOverlay && "number" === typeof selected.generatedTeamCount;

  return (
    <>
      <select
        aria-label="Version du planning"
        value={selectedScheduleId ?? ""}
        onChange={(event) => onSelectSchedule(event.target.value)}
        className="h-8 rounded-md border border-input bg-background px-3 text-sm"
      >
        {/* Visible season versions only; a selected overlay (from the cockpit) is added so it stays visible. */}
        {[...visibleSeasonPlans(schedules), ...schedules.filter((s) => null !== s.calendarEntryId && s.id === selectedScheduleId)].map((schedule) => (
          <option key={schedule.id} value={schedule.id}>
            {labelOf(schedule)}
            {schedule.id === baselineScheduleId ? " ★" : ""}
            {"VALIDATED" === schedule.status ? " · validé" : ""}
            {null !== schedule.calendarEntryId ? " · période" : ""}
          </option>
        ))}
      </select>
      {canDelete ? (
        <Button size="sm" variant="ghost" className="h-8 px-2 text-destructive" disabled={actionBusy} onClick={() => setConfirmDelete(true)} aria-label="Supprimer cette version" title="Supprimer cette version">
          <Trash2 className="size-4" />
        </Button>
      ) : null}
      {isValidated ? null : (
        <Button
          size="sm"
          variant="default"
          className="h-8"
          disabled={isGenerating || null === selectedScheduleId}
          onClick={onRegenerate}
        >
          <RefreshCw className={cn("size-4", isGenerating ? "animate-spin" : "")} />
          {isGenerating ? "Génération…" : "Régénérer"}
        </Button>
      )}

      {canRegenerateFrom ? (
        <Button size="sm" variant="ghost" className="h-8" disabled={actionBusy || isGenerating} onClick={onRegenerateFrom} title="Relancer une génération avec la structure de cette version">
          <History className="size-4" />
          Régénérer aux conditions
        </Button>
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
      {isFinished && !isBaseline && !isOverlay ? (
        <Button size="sm" variant="ghost" className="h-8" disabled={actionBusy} onClick={onSetBaseline}>
          <Star className="size-4" />
          Définir principal
        </Button>
      ) : null}

      <div className="flex items-center gap-1 rounded-md border border-border p-0.5">
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
      {selected ? (
        <span className="flex items-center gap-2 text-xs text-muted-foreground">
          <span className="flex items-center gap-1 rounded-full bg-muted px-2 py-0.5">
            {isValidated ? <Lock className="size-3" /> : null}
            {STATUS_LABELS[selected.status]}
          </span>
          {null !== selected.score ? <span>score {selected.score}</span> : null}
          {null !== selected.calendarEntryId ? (
            <span className="rounded-full border border-accent/50 px-2 py-0.5 font-medium text-accent">Période</span>
          ) : isBaseline ? (
            <span className="flex items-center gap-1 rounded-full bg-accent px-2 py-0.5 font-medium text-accent-foreground">
              <Star className="size-3" />
              Planning principal
            </span>
          ) : null}
        </span>
      ) : null}

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
    </>
  );
}
