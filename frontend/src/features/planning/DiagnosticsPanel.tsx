import { AlertTriangle, CheckCircle2, Info, XCircle } from "lucide-react";

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { cn } from "@/shared/lib/utils";

import type { Diagnostic, DiagnosticSeverity } from "./api";

const SEVERITY: Record<DiagnosticSeverity, { icon: typeof Info; className: string; label: string }> = {
  ERROR: { icon: XCircle, className: "text-destructive", label: "Erreur" },
  WARNING: { icon: AlertTriangle, className: "text-amber-500", label: "Alerte" },
  INFO: { icon: Info, className: "text-muted-foreground", label: "Info" },
  SUCCESS: { icon: CheckCircle2, className: "text-emerald-500", label: "OK" },
};

// Order by importance so the blocking items surface first.
const ORDER: DiagnosticSeverity[] = ["ERROR", "WARNING", "INFO", "SUCCESS"];

interface DiagnosticsPanelProps {
  diagnostics: Diagnostic[];
  selectedId: string | null;
  onSelect: (diagnostic: Diagnostic) => void;
}

export function DiagnosticsPanel({ diagnostics, selectedId, onSelect }: DiagnosticsPanelProps) {
  const sorted = [...diagnostics].sort((a, b) => ORDER.indexOf(a.severity) - ORDER.indexOf(b.severity));

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">Diagnostics du solveur</CardTitle>
        <CardDescription>
          {0 === diagnostics.length ? "Aucun diagnostic — le planning est propre." : `${diagnostics.length} retour(s) à traiter.`}
        </CardDescription>
      </CardHeader>
      {diagnostics.length > 0 ? (
        <CardContent className="flex flex-col gap-1 pt-0">
          {sorted.map((diagnostic) => {
            const meta = SEVERITY[diagnostic.severity];
            const Icon = meta.icon;
            return (
              <button
                key={diagnostic.id}
                type="button"
                onClick={() => onSelect(diagnostic)}
                className={cn(
                  "flex items-start gap-2 rounded-md border border-transparent px-2 py-1.5 text-left text-sm transition hover:bg-muted",
                  diagnostic.id === selectedId ? "border-border bg-muted" : "",
                )}
              >
                <Icon className={cn("mt-0.5 size-4 shrink-0", meta.className)} />
                <span>
                  <span className="font-medium">{diagnostic.type}</span>
                  <span className="block text-muted-foreground">{diagnostic.message}</span>
                </span>
              </button>
            );
          })}
        </CardContent>
      ) : null}
    </Card>
  );
}
