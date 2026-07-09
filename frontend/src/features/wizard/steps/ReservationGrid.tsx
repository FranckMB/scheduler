import { cn } from "@/shared/lib/utils";

import type { Venue, VenueTrainingSlot } from "../api";
import { fmtMinutes as fmt, hhmm, toMinutes as startMinutes } from "../lib/days";
import { END_MIN, gridTemplateColumns, START_MIN, STEP, WEEK } from "../lib/weekGrid";

interface Props {
  venue: Venue;
  slots: VenueTrainingSlot[];
  /** slotKey → names of the teams reserved on it (empty/absent = free). */
  reservedTeams: Map<string, string[]>;
  slotKeyOf: (slot: VenueTrainingSlot) => string;
  capacityOf: (slot: VenueTrainingSlot) => number;
  onSelectSlot: (slot: VenueTrainingSlot) => void;
}

/**
 * Per-venue weekly grid for the "Réserver" tab. Mirrors VenueAvailabilityGrid's
 * geometry but is ASSIGN-mode: empty cells are inert (you reserve existing slots,
 * not create them), and each slot shows the team(s) reserved on it + its
 * remaining capacity, click → the assign modal.
 */
export function ReservationGrid({ venue, slots, reservedTeams, slotKeyOf, capacityOf, onSelectSlot }: Props) {
  const color = venue.color ?? "var(--accent)";

  // Dynamic vertical range: only the hours that actually hold slots for THIS
  // venue (Réserver-only — the Gymnases grid keeps the fixed 08–22 for creation).
  // Rounded to the hour; falls back to the fixed range when the venue has none.
  const startMins = slots.map((s) => startMinutes(s.startTime));
  const endMins = slots.map((s) => startMinutes(s.startTime) + s.durationMinutes);
  const gridStart = slots.length > 0 ? Math.floor(Math.min(...startMins) / 60) * 60 : START_MIN;
  const gridEnd = slots.length > 0 ? Math.ceil(Math.max(...endMins) / 60) * 60 : END_MIN;
  const gridRows = Array.from({ length: Math.max(1, (gridEnd - gridStart) / STEP) }, (_, i) => gridStart + i * STEP);
  const gridTemplateRows = `1.5rem repeat(${gridRows.length}, 11px)`;

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
        {gridRows.map((m, i) => (
          <div key={`t${m}`} className="border-r border-border pr-1 text-right text-[10px] text-muted-foreground" style={{ gridColumn: 1, gridRow: 2 + i }}>
            {0 === m % 60 ? fmt(m) : ""}
          </div>
        ))}

        {/* Inert grid lines — no create-on-click here (unlike the Gymnases grid). */}
        {WEEK.map((d, di) =>
          gridRows.map((m, ri) => <div key={`g${d.n}-${m}`} className={cn("border-l border-t border-border/40", 0 === m % 60 ? "border-t-border/70" : "")} style={{ gridColumn: 2 + di, gridRow: 2 + ri }} aria-hidden="true" />),
        )}

        {/* Slots */}
        {slots.map((slot) => {
          const di = WEEK.findIndex((d) => d.n === slot.dayOfWeek);
          if (di < 0) {
            return null;
          }
          const startRow = 2 + Math.round((startMinutes(slot.startTime) - gridStart) / STEP);
          const span = Math.max(1, Math.round(slot.durationMinutes / STEP));
          const teams = reservedTeams.get(slotKeyOf(slot)) ?? [];
          const capacity = capacityOf(slot);
          const full = teams.length >= capacity;
          const label = 0 === teams.length ? "libre" : teams.join(", ");
          const dayLabel = WEEK[di]?.label ?? "";
          return (
            <button
              key={slot.id}
              type="button"
              onClick={() => onSelectSlot(slot)}
              aria-label={`${dayLabel} ${hhmm(slot.startTime)} · ${venue.name} · ${teams.length}/${capacity} réservé — cliquer pour gérer`}
              className={cn(
                "z-10 m-px flex flex-col items-start gap-0.5 overflow-hidden rounded border border-border border-l-4 px-1 py-0.5 text-left text-[10px] leading-tight hover:ring-1 hover:ring-accent",
              )}
              style={{ gridColumn: 2 + di, gridRow: `${startRow} / span ${span}`, borderLeftColor: color, backgroundColor: `color-mix(in oklch, ${color} 30%, var(--card))` }}
            >
              <span className="flex w-full items-center justify-between gap-1 font-medium">
                <span>{hhmm(slot.startTime)}</span>
                <span className={cn("shrink-0 tabular-nums", full ? "text-muted-foreground" : "text-accent")}>
                  {teams.length}/{capacity}
                </span>
              </span>
              <span className={cn("truncate", 0 === teams.length ? "text-muted-foreground" : "font-medium")}>{label}</span>
            </button>
          );
        })}
      </div>
    </div>
  );
}
