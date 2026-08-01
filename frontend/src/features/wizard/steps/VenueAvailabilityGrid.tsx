import { formatDuration } from "@/shared/lib/duration";
import { cn } from "@/shared/lib/utils";

import type { Venue, VenueTrainingSlot } from "../api";
import { fmtMinutes as fmt, hhmm, toMinutes as startMinutes } from "../lib/days";
import { gridTemplateColumns, gridTemplateRows, rows, START_MIN, STEP, WEEK } from "../lib/weekGrid";

interface Props {
  venue: Venue;
  slots: VenueTrainingSlot[];
  selectedSlotId: string | null;
  onAdd: (dayOfWeek: number, startTime: string) => void;
  onSelect: (slot: VenueTrainingSlot) => void;
}

export function VenueAvailabilityGrid({ venue, slots, selectedSlotId, onAdd, onSelect }: Props) {
  const color = venue.color ?? "var(--accent)";

  return (
    // P4-37 — la grille DÉFILE en interne au lieu de pousser la page. Elle n'avait aucune
    // borne verticale : passer de 22h à 23h l'aurait allongée d'autant, aggravant un
    // problème qui existait déjà. `max-h` + `overflow-y-auto` la bornent au viewport.
    <div className="max-h-[min(70vh,40rem)] overflow-auto rounded-lg border border-border bg-card">
      <div className="grid text-xs" style={{ gridTemplateColumns, gridTemplateRows }}>
        {/* Coin figé sur les DEUX axes : il masque la gouttière quand elle défile sous
            l'en-tête, sinon les heures passeraient par-dessus les noms de jours. */}
        <div className="sticky left-0 top-0 z-30 border-b border-r border-border bg-card" style={{ gridColumn: 1, gridRow: 1 }} />
        {WEEK.map((d, i) => (
          <div key={d.n} className="sticky top-0 z-20 border-b border-l border-border bg-card py-0.5 text-center font-medium" style={{ gridColumn: 2 + i, gridRow: 1 }}>
            {d.label}
          </div>
        ))}

        {/* Time gutter — label on the hour */}
        {rows.map((m, i) => (
          // La gouttière reste lisible pendant le défilement HORIZONTAL : sans `sticky`,
          // les heures disparaissaient sous les colonnes dès qu'on faisait défiler.
          <div key={`t${m}`} className="sticky left-0 z-10 border-r border-border bg-card pr-1 text-right text-[10px] text-muted-foreground" style={{ gridColumn: 1, gridRow: 2 + i }}>
            {0 === m % 60 ? fmt(m) : ""}
          </div>
        ))}

        {/* Empty clickable cells */}
        {WEEK.map((d, di) =>
          rows.map((m, ri) => (
            <button
              key={`c${d.n}-${m}`}
              type="button"
              aria-label={`${d.label} ${fmt(m)}`}
              onClick={() => onAdd(d.n, fmt(m))}
              className={cn("border-l border-t border-border/40 hover:bg-muted", 0 === m % 60 ? "border-t-border/70" : "")}
              style={{ gridColumn: 2 + di, gridRow: 2 + ri }}
            />
          )),
        )}

        {/* Slots */}
        {slots.map((slot) => {
          const di = WEEK.findIndex((d) => d.n === slot.dayOfWeek);
          if (di < 0) {
            return null;
          }
          const startRow = 2 + Math.round((startMinutes(slot.startTime) - START_MIN) / STEP);
          const span = Math.max(1, Math.round(slot.durationMinutes / STEP));
          return (
            <button
              key={slot.id}
              type="button"
              onClick={() => onSelect(slot)}
              title={`${hhmm(slot.startTime)} · ${formatDuration(slot.durationMinutes)} · cap ${slot.capacity} — cliquer pour modifier`}
              className={cn(
                // Full border + OPAQUE fill so a slot is always clearly bounded —
                // the old semi-transparent var(--muted) fill was identical to the
                // empty cells' hover:bg-muted, so hovering the grid made slots
                // "vanish" into the highlighted cells (reliability bug).
                "z-10 m-px flex items-start overflow-hidden rounded border border-border border-l-4 px-1 text-left text-[10px] font-medium leading-tight hover:ring-1 hover:ring-accent",
                slot.id === selectedSlotId ? "ring-2 ring-accent" : "",
              )}
              style={{ gridColumn: 2 + di, gridRow: `${startRow} / span ${span}`, borderLeftColor: color, backgroundColor: `color-mix(in oklch, ${color} 30%, var(--card))` }}
            >
              {hhmm(slot.startTime)}
              {slot.capacity > 1 ? ` ·${slot.capacity}` : ""}
            </button>
          );
        })}
      </div>
    </div>
  );
}
