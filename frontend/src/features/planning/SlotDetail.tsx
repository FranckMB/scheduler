import { Lock, X } from "lucide-react";

import { Card, CardContent, CardHeader, CardTitle } from "@/shared/components/ui/card";

import { DAYS, type GridCell } from "./lib/grid";

interface SlotDetailProps {
  cell: GridCell;
  categoryLabel: string;
  onClose: () => void;
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex justify-between gap-4 py-1 text-sm">
      <span className="text-muted-foreground">{label}</span>
      <span className="text-right font-medium">{value}</span>
    </div>
  );
}

export function SlotDetail({ cell, categoryLabel, onClose }: SlotDetailProps) {
  const dayLabel = DAYS.find((d) => d.n === cell.day)?.label ?? "—";

  return (
    <Card>
      <CardHeader className="flex-row items-center justify-between">
        <CardTitle className="flex items-center gap-2">
          {cell.teamLabel}
          {cell.locked ? <Lock className="size-4 text-muted-foreground" /> : null}
        </CardTitle>
        <button type="button" onClick={onClose} aria-label="Fermer" className="text-muted-foreground hover:text-foreground">
          <X className="size-4" />
        </button>
      </CardHeader>
      <CardContent className="pt-0">
        <Row label="Catégorie" value={categoryLabel} />
        <Row label="Gymnase" value={cell.venueLabel} />
        <Row label="Coach" value={cell.coachLabel} />
        <Row label="Jour" value={dayLabel} />
        <Row label="Horaire" value={`${cell.startLabel} – ${cell.endLabel}`} />
        <Row label="Verrou" value={cell.locked ? "Verrouillé" : "Libre"} />
      </CardContent>
    </Card>
  );
}
