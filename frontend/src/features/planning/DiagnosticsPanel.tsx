import { AlertTriangle, CheckCircle2, ChevronDown, ChevronRight, Info, XCircle } from "lucide-react";
import { useState } from "react";

import { Card, CardContent, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { cn } from "@/shared/lib/utils";

import type { Diagnostic, DiagnosticSeverity, Slot } from "./api";
import { concernedSlots, type Lookups } from "./lib/grid";

const SEVERITY: Record<DiagnosticSeverity, { icon: typeof Info; className: string; label: string }> = {
  ERROR: { icon: XCircle, className: "text-destructive", label: "Erreurs" },
  WARNING: { icon: AlertTriangle, className: "text-warning", label: "Alertes" },
  INFO: { icon: Info, className: "text-muted-foreground", label: "Infos" },
  SUCCESS: { icon: CheckCircle2, className: "text-success", label: "OK" },
};

const ORDER: DiagnosticSeverity[] = ["ERROR", "WARNING", "INFO", "SUCCESS"];

interface DiagnosticsPanelProps {
  diagnostics: Diagnostic[];
  slots: Slot[];
  /** Synthetic `vide` cells, so an "unused_slot" warning can highlight them. */
  emptySlots?: Slot[];
  lookups: Lookups;
  onHighlight: (slotIds: Set<string>) => void;
  /** "unused_slot" warning: bring the concerned venue's column on screen. */
  onFocusVenue?: (venueId: string) => void;
}

export function DiagnosticsPanel({ diagnostics, slots, emptySlots = [], lookups, onHighlight, onFocusVenue }: DiagnosticsPanelProps) {
  const [openSeverity, setOpenSeverity] = useState<DiagnosticSeverity | null>(null);
  const [activeId, setActiveId] = useState<string | null>(null);

  const groups = ORDER.map((severity) => ({ severity, items: diagnostics.filter((d) => d.severity === severity) })).filter((g) => g.items.length > 0);

  function toggleGroup(severity: DiagnosticSeverity) {
    setOpenSeverity((current) => (current === severity ? null : severity));
    setActiveId(null);
    onHighlight(new Set());
  }

  function selectDiagnostic(diagnostic: Diagnostic) {
    if (activeId === diagnostic.id) {
      setActiveId(null);
      onHighlight(new Set());
      return;
    }
    setActiveId(diagnostic.id);
    // The solver's "unused_slot" warning points at a defined-but-unfilled window:
    // highlight the matching `vide` cell(s) and bring their venue column on screen.
    if ("unused_slot" === diagnostic.type && null !== diagnostic.venueId) {
      onHighlight(new Set(emptySlots.filter((s) => s.venueId === diagnostic.venueId).map((s) => s.id)));
      onFocusVenue?.(diagnostic.venueId);
      return;
    }
    onHighlight(new Set(concernedSlots(diagnostic, slots, lookups).map((c) => c.slotId)));
  }

  return (
    <Card className="flex h-full min-h-0 flex-col">
      <CardHeader className="shrink-0 pb-3">
        <CardTitle className="text-base">Diagnostics du solveur</CardTitle>
      </CardHeader>
      <CardContent className="min-h-0 flex-1 overflow-y-auto pt-0">
        {0 === diagnostics.length ? (
          <EmptyHint>Aucun diagnostic — le planning est propre.</EmptyHint>
        ) : (
          <div className="flex flex-col gap-1">
            {groups.map((group) => {
              const meta = SEVERITY[group.severity];
              const Icon = meta.icon;
              const open = openSeverity === group.severity;
              return (
                <div key={group.severity} className="rounded-md border border-border">
                  <button type="button" onClick={() => toggleGroup(group.severity)} className="flex w-full items-center gap-2 px-2 py-1.5 text-left text-sm hover:bg-muted">
                    {open ? <ChevronDown className="size-4 shrink-0" /> : <ChevronRight className="size-4 shrink-0" />}
                    <Icon className={cn("size-4 shrink-0", meta.className)} />
                    <span className="flex-1 font-medium">{meta.label}</span>
                    <span className="rounded-full bg-muted px-1.5 text-xs text-muted-foreground">{group.items.length}</span>
                  </button>
                  {open ? (
                    <ul className="flex flex-col border-t border-border">
                      {group.items.map((item) => (
                        <li key={item.id}>
                          <button
                            type="button"
                            onClick={() => selectDiagnostic(item)}
                            className={cn(
                              "w-full px-3 py-1.5 text-left text-xs text-muted-foreground transition hover:bg-muted",
                              item.id === activeId ? "bg-muted text-foreground" : "",
                            )}
                          >
                            {item.message}
                          </button>
                        </li>
                      ))}
                    </ul>
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
