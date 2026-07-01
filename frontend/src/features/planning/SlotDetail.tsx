import { Lock, LockOpen, X } from "lucide-react";
import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/shared/components/ui/card";

import type { Slot, SlotMovePatch, Venue } from "./api";
import { DAYS, type GridCell, toHourMinute } from "./lib/grid";

interface SlotDetailProps {
  cell: GridCell;
  slot: Slot;
  venues: Venue[];
  categoryLabel: string;
  busy: boolean;
  onClose: () => void;
  onToggleLock: () => void;
  onMove: (patch: SlotMovePatch) => void;
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex justify-between gap-4 py-1 text-sm">
      <span className="text-muted-foreground">{label}</span>
      <span className="text-right font-medium">{value}</span>
    </div>
  );
}

export function SlotDetail({ cell, slot, venues, categoryLabel, busy, onClose, onToggleLock, onMove }: SlotDetailProps) {
  const [day, setDay] = useState(slot.dayOfWeek);
  const [time, setTime] = useState(toHourMinute(slot.startTime));
  const [venueId, setVenueId] = useState(slot.venueId);

  const dirty = day !== slot.dayOfWeek || time !== toHourMinute(slot.startTime) || venueId !== slot.venueId;

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
        <Row label="Coach" value={cell.coachLabel} />
        <Row label="Durée" value={`${slot.durationMinutes} min`} />

        <div className="mt-3 flex flex-col gap-2 border-t border-border pt-3">
          <div className="grid grid-cols-3 gap-2">
            <select aria-label="Jour" value={day} onChange={(e) => setDay(Number(e.target.value))} className="h-9 rounded-md border border-input bg-background px-2 text-sm">
              {DAYS.map((d) => (
                <option key={d.n} value={d.n}>
                  {d.label}
                </option>
              ))}
            </select>
            <input aria-label="Heure" type="time" value={time} onChange={(e) => setTime(e.target.value)} className="h-9 rounded-md border border-input bg-background px-2 text-sm" />
            <select aria-label="Gymnase" value={venueId} onChange={(e) => setVenueId(e.target.value)} className="h-9 rounded-md border border-input bg-background px-2 text-sm">
              {venues.map((v) => (
                <option key={v.id} value={v.id}>
                  {v.name}
                </option>
              ))}
            </select>
          </div>

          <div className="flex gap-2">
            <Button size="sm" variant="outline" className="flex-1" disabled={!dirty || busy} onClick={() => onMove({ dayOfWeek: day, startTime: time, venueId })}>
              Déplacer
            </Button>
            <Button size="sm" variant={cell.locked ? "default" : "outline"} className="flex-1" disabled={busy} onClick={onToggleLock}>
              {cell.locked ? <LockOpen className="size-4" /> : <Lock className="size-4" />}
              {cell.locked ? "Déverrouiller" : "Verrouiller"}
            </Button>
          </div>
        </div>
      </CardContent>
    </Card>
  );
}
