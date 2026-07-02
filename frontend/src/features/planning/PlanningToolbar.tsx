import { RefreshCw, Star } from "lucide-react";

import { Button } from "@/shared/components/ui/button";
import { cn } from "@/shared/lib/utils";

import type { Schedule } from "./api";
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
  isGenerating: boolean;
  baselineScheduleId: string | null;
}

export function PlanningToolbar({
  schedules,
  selectedScheduleId,
  onSelectSchedule,
  viewMode,
  onViewMode,
  onRegenerate,
  isGenerating,
  baselineScheduleId,
}: PlanningToolbarProps) {
  const selected = schedules.find((s) => s.id === selectedScheduleId) ?? null;
  const isBaseline = null !== selected && selected.id === baselineScheduleId;

  return (
    <>
      <select
        aria-label="Planning"
        value={selectedScheduleId ?? ""}
        onChange={(event) => onSelectSchedule(event.target.value)}
        className="h-8 rounded-md border border-input bg-background px-3 text-sm"
      >
        {schedules.map((schedule) => (
          <option key={schedule.id} value={schedule.id}>
            {schedule.name}
            {schedule.id === baselineScheduleId ? " ★" : ""}
          </option>
        ))}
      </select>
      <Button size="sm" variant="default" className="h-8" disabled={isGenerating || null === selectedScheduleId} onClick={onRegenerate}>
        <RefreshCw className={cn("size-4", isGenerating ? "animate-spin" : "")} />
        {isGenerating ? "Génération…" : "Régénérer"}
      </Button>
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
          <span className="rounded-full bg-muted px-2 py-0.5">{selected.status}</span>
          {null !== selected.score ? <span>score {selected.score}</span> : null}
          {isBaseline ? (
            <span className="flex items-center gap-1 rounded-full bg-accent px-2 py-0.5 font-medium text-accent-foreground">
              <Star className="size-3" />
              Base
            </span>
          ) : (
            <span className="rounded-full border border-border px-2 py-0.5">Secondaire</span>
          )}
        </span>
      ) : null}
    </>
  );
}
