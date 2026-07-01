import { cn } from "@/shared/lib/utils";

import type { GridResource } from "./lib/grid";
import type { ViewMode } from "./store";

const LABELS: Record<ViewMode, string> = {
  gymnase: "Gymnases",
  coach: "Coachs",
  equipe: "Équipes",
};

interface ResourceFilterProps {
  viewMode: ViewMode;
  resources: GridResource[];
  selected: string[];
  onToggle: (id: string) => void;
  onClear: () => void;
}

export function ResourceFilter({ viewMode, resources, selected, onToggle, onClear }: ResourceFilterProps) {
  if (0 === resources.length) {
    return null;
  }
  const allActive = 0 === selected.length;

  return (
    <div className="mb-3 flex flex-wrap items-center gap-1.5">
      <span className="mr-1 text-xs font-medium text-muted-foreground">{LABELS[viewMode]} :</span>
      <button
        type="button"
        onClick={onClear}
        className={cn(
          "rounded-full border px-2.5 py-0.5 text-xs transition",
          allActive ? "border-accent bg-accent text-accent-foreground" : "border-border text-muted-foreground hover:text-foreground",
        )}
      >
        Tous
      </button>
      {resources.map((resource) => {
        const active = selected.includes(resource.id);
        return (
          <button
            key={resource.id}
            type="button"
            onClick={() => onToggle(resource.id)}
            className={cn(
              "rounded-full border px-2.5 py-0.5 text-xs transition",
              active ? "border-accent bg-accent text-accent-foreground" : "border-border text-muted-foreground hover:text-foreground",
            )}
          >
            {resource.label}
          </button>
        );
      })}
    </div>
  );
}
