import { Check, ChevronDown } from "lucide-react";
import { useState } from "react";

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
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");

  if (0 === resources.length) {
    return null;
  }

  const filtered = resources.filter((resource) => resource.label.toLowerCase().includes(query.trim().toLowerCase()));
  const count = selected.length;
  const summary = 0 === count ? "tous" : `${count} sélectionné${count > 1 ? "s" : ""}`;

  return (
    <div className="relative inline-block">
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        className="flex h-8 items-center gap-2 rounded-md border border-border bg-background px-3 text-xs text-foreground hover:bg-muted"
      >
        <span className="font-medium text-muted-foreground">{LABELS[viewMode]} :</span>
        <span>{summary}</span>
        <ChevronDown className="size-3.5 text-muted-foreground" />
      </button>

      {open ? (
        <>
          <button type="button" aria-hidden className="fixed inset-0 z-50 cursor-default" onClick={() => setOpen(false)} />
          <div className="absolute z-[60] mt-1 w-72 rounded-md border border-border bg-card shadow-md">
            <div className="border-b border-border p-2">
              <input
                // eslint-disable-next-line jsx-a11y/no-autofocus -- search field inside a just-opened popover; focusing it is the expected behaviour
                autoFocus
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                placeholder="Rechercher…"
                className="h-8 w-full rounded-md border border-input bg-background px-2 text-sm outline-none focus:ring-2 focus:ring-ring"
              />
            </div>
            <ul className="max-h-64 overflow-y-auto p-1">
              {count > 0 ? (
                <li>
                  <button type="button" onClick={onClear} className="w-full rounded px-2 py-1 text-left text-xs text-muted-foreground hover:bg-muted">
                    Tout effacer ({count})
                  </button>
                </li>
              ) : null}
              {filtered.map((resource) => {
                const active = selected.includes(resource.id);
                return (
                  <li key={resource.id}>
                    <button
                      type="button"
                      onClick={() => onToggle(resource.id)}
                      className="flex w-full items-center gap-2 rounded px-2 py-1 text-left text-sm hover:bg-muted"
                    >
                      <Check className={cn("size-4 shrink-0 text-accent", active ? "" : "invisible")} />
                      <span className="truncate">{resource.label}</span>
                    </button>
                  </li>
                );
              })}
              {0 === filtered.length ? <li className="px-2 py-1 text-xs text-muted-foreground">Aucun résultat</li> : null}
            </ul>
          </div>
        </>
      ) : null}
    </div>
  );
}
