import { AlertTriangle, CheckCircle2, ChevronDown, ChevronRight, Info, XCircle } from "lucide-react";
import { useState } from "react";

import { Card, CardContent, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { cn } from "@/shared/lib/utils";

import type { Diagnostic, DiagnosticSeverity, Slot } from "./api";
import { concernedSlots, type Lookups } from "./lib/grid";

const SEVERITY: Record<DiagnosticSeverity, { icon: typeof Info; className: string }> = {
  ERROR: { icon: XCircle, className: "text-destructive" },
  WARNING: { icon: AlertTriangle, className: "text-amber-500" },
  INFO: { icon: Info, className: "text-muted-foreground" },
  SUCCESS: { icon: CheckCircle2, className: "text-emerald-500" },
};

const ORDER: DiagnosticSeverity[] = ["ERROR", "WARNING", "INFO", "SUCCESS"];

interface Group {
  type: string;
  severity: DiagnosticSeverity;
  items: Diagnostic[];
}

function groupByType(diagnostics: Diagnostic[]): Group[] {
  const map = new Map<string, Group>();
  for (const diagnostic of diagnostics) {
    const existing = map.get(diagnostic.type);
    if (undefined === existing) {
      map.set(diagnostic.type, { type: diagnostic.type, severity: diagnostic.severity, items: [diagnostic] });
    } else {
      existing.items.push(diagnostic);
      if (ORDER.indexOf(diagnostic.severity) < ORDER.indexOf(existing.severity)) {
        existing.severity = diagnostic.severity;
      }
    }
  }
  return [...map.values()].sort((a, b) => ORDER.indexOf(a.severity) - ORDER.indexOf(b.severity));
}

interface DiagnosticsPanelProps {
  diagnostics: Diagnostic[];
  slots: Slot[];
  lookups: Lookups;
  onHighlight: (slotIds: Set<string>) => void;
}

export function DiagnosticsPanel({ diagnostics, slots, lookups, onHighlight }: DiagnosticsPanelProps) {
  const [openType, setOpenType] = useState<string | null>(null);
  const groups = groupByType(diagnostics);

  function toggle(group: Group) {
    if (openType === group.type) {
      setOpenType(null);
      onHighlight(new Set());
      return;
    }
    setOpenType(group.type);
    const ids = new Set<string>();
    for (const item of group.items) {
      for (const concerned of concernedSlots(item, slots, lookups)) {
        ids.add(concerned.slotId);
      }
    }
    onHighlight(ids);
  }

  return (
    <Card className="flex h-full min-h-0 flex-col">
      <CardHeader className="shrink-0 pb-3">
        <CardTitle className="text-base">Diagnostics du solveur</CardTitle>
      </CardHeader>
      <CardContent className="min-h-0 flex-1 overflow-y-auto pt-0">
        {0 === diagnostics.length ? (
          <p className="text-sm text-muted-foreground">Aucun diagnostic — le planning est propre.</p>
        ) : (
          <div className="flex flex-col gap-1">
            {groups.map((group) => {
              const meta = SEVERITY[group.severity];
              const Icon = meta.icon;
              const open = openType === group.type;
              return (
                <div key={group.type} className="rounded-md border border-border">
                  <button type="button" onClick={() => toggle(group)} className="flex w-full items-center gap-2 px-2 py-1.5 text-left text-sm hover:bg-muted">
                    {open ? <ChevronDown className="size-4 shrink-0" /> : <ChevronRight className="size-4 shrink-0" />}
                    <Icon className={cn("size-4 shrink-0", meta.className)} />
                    <span className="flex-1 font-medium">{group.type}</span>
                    <span className="rounded-full bg-muted px-1.5 text-xs text-muted-foreground">{group.items.length}</span>
                  </button>
                  {open ? (
                    <div className="flex flex-col gap-2 border-t border-border px-3 py-2 text-xs">
                      {group.items.map((item) => {
                        const concerned = concernedSlots(item, slots, lookups);
                        return (
                          <div key={item.id}>
                            <p className="text-muted-foreground">{item.message}</p>
                            {concerned.length > 0 ? (
                              <ul className="mt-1 flex flex-col gap-0.5">
                                {concerned.map((c) => (
                                  <li key={c.slotId} className="flex justify-between gap-2">
                                    <span className="font-medium">
                                      {c.dayLabel} {c.timeLabel}
                                    </span>
                                    <span className="truncate text-muted-foreground">
                                      {c.teamLabel} · {c.venueLabel}
                                    </span>
                                  </li>
                                ))}
                              </ul>
                            ) : null}
                          </div>
                        );
                      })}
                    </div>
                  ) : null}
                </div>
              );
            })}
          </div>
        )}
      </CardContent>
    </Card>
  );
}
