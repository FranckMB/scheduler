import { cn } from "@/shared/lib/utils";

import type { Venue, VenueTrainingSlot } from "../api";
import { DAYS, hhmm } from "../lib/days";

const START_MIN = 8 * 60; // 08:00
const END_MIN = 22 * 60; // 22:00
const STEP = 30;
const ROW_H = 16; // px per 30 min
const WEEK = DAYS.filter((d) => d.n <= 6); // Lun-Sam

const rows = Array.from({ length: (END_MIN - START_MIN) / STEP }, (_, i) => START_MIN + i * STEP);
const fmt = (m: number) => `${String(Math.floor(m / 60)).padStart(2, "0")}:${String(m % 60).padStart(2, "0")}`;
const startMinutes = (t: string) => {
  const [h, m] = hhmm(t).split(":");
  return Number(h) * 60 + Number(m);
};

interface Props {
  venue: Venue;
  slots: VenueTrainingSlot[];
  selectedSlotId: string | null;
  onAdd: (dayOfWeek: number, startTime: string) => void;
  onSelect: (slot: VenueTrainingSlot) => void;
}

export function VenueAvailabilityGrid({ venue, slots, selectedSlotId, onAdd, onSelect }: Props) {
  const color = venue.color ?? "var(--accent)";
  const gridTemplateColumns = `3rem repeat(${WEEK.length}, minmax(3rem, 1fr))`;
  const gridTemplateRows = `1.5rem repeat(${rows.length}, ${ROW_H}px)`;

  return (
    <div className="overflow-x-auto rounded-lg border border-border bg-card">
      <div className="grid text-xs" style={{ gridTemplateColumns, gridTemplateRows }}>
        <div className="border-b border-r border-border" style={{ gridColumn: 1, gridRow: 1 }} />
        {WEEK.map((d, i) => (
          <div key={d.n} className="border-b border-l border-border py-0.5 text-center font-medium" style={{ gridColumn: 2 + i, gridRow: 1 }}>
            {d.label}
          </div>
        ))}

        {/* Time gutter — label on the hour */}
        {rows.map((m, i) => (
          <div key={`t${m}`} className="border-r border-border pr-1 text-right text-[10px] text-muted-foreground" style={{ gridColumn: 1, gridRow: 2 + i }}>
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
              title={`${hhmm(slot.startTime)} · ${slot.durationMinutes}min · cap ${slot.capacity} — cliquer pour modifier`}
              className={cn(
                "z-10 m-px flex items-start overflow-hidden rounded border-l-4 px-1 text-left text-[10px] leading-tight hover:ring-1 hover:ring-accent",
                slot.id === selectedSlotId ? "ring-2 ring-accent" : "",
              )}
              style={{ gridColumn: 2 + di, gridRow: `${startRow} / span ${span}`, borderLeftColor: color, backgroundColor: color.startsWith("#") ? `${color}33` : "var(--muted)" }}
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
