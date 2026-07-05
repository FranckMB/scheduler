import { CheckCircle2, Lock, LockOpen, Pencil, RefreshCw, Star } from "lucide-react";
import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { cn } from "@/shared/lib/utils";

import { STATUS_LABELS, type Schedule } from "./api";
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
  onRename: (name: string) => void;
  isGenerating: boolean;
  actionBusy: boolean;
  baselineScheduleId: string | null;
}

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
  onRename,
  isGenerating,
  actionBusy,
  baselineScheduleId,
}: PlanningToolbarProps) {
  const selected = schedules.find((s) => s.id === selectedScheduleId) ?? null;
  const isBaseline = null !== selected && selected.id === baselineScheduleId;
  const isValidated = null !== selected && "VALIDATED" === selected.status;
  const isCompleted = null !== selected && "COMPLETED" === selected.status;
  const isFinished = isValidated || isCompleted;
  const [editingName, setEditingName] = useState<string | null>(null);

  return (
    <>
      {null !== editingName ? (
        <input
          autoFocus
          aria-label="Nom du planning"
          value={editingName}
          onChange={(event) => setEditingName(event.target.value)}
          onKeyDown={(event) => {
            if ("Enter" === event.key) {
              const value = editingName.trim();
              if ("" !== value) {
                onRename(value);
              }
              setEditingName(null);
            } else if ("Escape" === event.key) {
              setEditingName(null);
            }
          }}
          onBlur={() => setEditingName(null)}
          className="h-8 rounded-md border border-input bg-background px-3 text-sm"
        />
      ) : (
        <>
          <select
            aria-label="Planning"
            value={selectedScheduleId ?? ""}
            onChange={(event) => onSelectSchedule(event.target.value)}
            className="h-8 rounded-md border border-input bg-background px-3 text-sm"
          >
            {/* Season plans only; a selected overlay (from the cockpit) is added so it stays visible. */}
            {schedules
              .filter((schedule) => null === schedule.calendarEntryId || schedule.id === selectedScheduleId)
              .map((schedule) => (
                <option key={schedule.id} value={schedule.id}>
                  {schedule.name}
                  {schedule.id === baselineScheduleId ? " ★" : ""}
                  {null !== schedule.calendarEntryId ? " · période" : ""}
                </option>
              ))}
          </select>
          {null !== selected && !isValidated ? (
            <Button size="sm" variant="ghost" className="h-8 px-2" onClick={() => setEditingName(selected.name)} aria-label="Renommer le planning" title="Renommer">
              <Pencil className="size-4" />
            </Button>
          ) : null}
        </>
      )}
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
      {isFinished && !isBaseline ? (
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
          ) : (
            <span className="rounded-full border border-border px-2 py-0.5">Secondaire</span>
          )}
        </span>
      ) : null}
    </>
  );
}
