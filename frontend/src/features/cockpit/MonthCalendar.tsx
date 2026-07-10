import { ChevronLeft, ChevronRight } from "lucide-react";
import { useState } from "react";

import { cn } from "@/shared/lib/utils";

import type { CalendarEntry, PublicHoliday, SchoolHoliday } from "./api";
import { DayDialog } from "./DayDialog";
import { buildMonthGrid, isWithin, monthLabel, todayISO } from "./lib/date";

const WEEKDAYS = ["L", "M", "M", "J", "V", "S", "D"];

/** Single home of the entry→marker mapping (next periodType goes here, not in the JSX). */
const entryIcon = (e: CalendarEntry): string => {
  if (e.kind === "period") {
    return e.periodType === "cutoff" ? "🛑" : "⛔";
  }
  return e.isDisruptive ? "🚫" : "🎉";
};

/**
 * Accessible name for an entry marker. `title` may be empty (imported / auto-named
 * entries), so fall back to the marker's meaning — never an empty aria-label, which
 * would be a silent marker for a screen reader (and an axe violation).
 */
const entryLabel = (e: CalendarEntry): string => {
  if (e.title.trim() !== "") {
    return e.title;
  }
  if (e.kind === "period") {
    return e.periodType === "cutoff" ? "Coupure" : "Période fermée";
  }
  return e.isDisruptive ? "Événement perturbant" : "Événement";
};

interface MonthCalendarProps {
  year: number;
  month: number;
  entries: CalendarEntry[];
  holidays: SchoolHoliday[];
  publicHolidays: PublicHoliday[];
  onPrev: () => void;
  onNext: () => void;
}

/** Month grid of the exception layer (events / closures / holidays) — NOT the weekly base plan. */
export function MonthCalendar({ year, month, entries, holidays, publicHolidays, onPrev, onNext }: MonthCalendarProps) {
  const [selectedDay, setSelectedDay] = useState<string | null>(null);
  const grid = buildMonthGrid(year, month);
  const today = todayISO();

  const entriesOn = (iso: string): CalendarEntry[] => entries.filter((e) => isWithin(iso, e.startDate, e.endDate));
  const holidayOn = (iso: string): SchoolHoliday | undefined => holidays.find((h) => isWithin(iso, h.startDate, h.endDate));
  const publicHolidayOn = (iso: string): PublicHoliday | undefined => publicHolidays.find((h) => h.date === iso);

  return (
    <div className="rounded-lg border border-border bg-card p-4">
      <div className="mb-3 flex items-center justify-between">
        <h2 className="text-sm font-semibold">
          {monthLabel(month)} {year}
        </h2>
        <div className="flex gap-1">
          <button type="button" aria-label="Mois précédent" className="rounded p-1 text-muted-foreground hover:text-foreground" onClick={onPrev}>
            <ChevronLeft className="size-4" />
          </button>
          <button type="button" aria-label="Mois suivant" className="rounded p-1 text-muted-foreground hover:text-foreground" onClick={onNext}>
            <ChevronRight className="size-4" />
          </button>
        </div>
      </div>

      <div className="grid grid-cols-7 gap-1 text-center text-xs text-muted-foreground">
        {WEEKDAYS.map((d, i) => (
          <div key={i} className="py-1">
            {d}
          </div>
        ))}
      </div>

      <div className="grid grid-cols-7 gap-1">
        {grid.map((cell) => {
          const dayEntries = entriesOn(cell.iso);
          const holiday = holidayOn(cell.iso);
          const publicHoliday = publicHolidayOn(cell.iso);
          const isToday = cell.iso === today;
          // A11Y-07: one composed accessible name for the whole cell. The button's
          // aria-label overrides its children, so a bare `Jour {ISO}` used to hide
          // every marker (holiday / férié / events) from a screen reader — compose
          // them all here so nothing is lost, and read a human date, not the ISO.
          const dayMarks = [
            holiday ? `vacances — ${holiday.label}` : null,
            publicHoliday ? `jour férié — ${publicHoliday.label}` : null,
            ...dayEntries.map((e) => entryLabel(e)),
          ].filter((m): m is string => m !== null);
          const dayLabel = [cell.inMonth ? `${cell.day} ${monthLabel(month)}` : cell.iso, isToday ? "aujourd'hui" : null, ...dayMarks]
            .filter(Boolean)
            .join(", ");
          return (
            <button
              key={cell.iso}
              type="button"
              onClick={() => setSelectedDay(cell.iso)}
              className={cn(
                "flex min-h-14 flex-col items-start gap-1 rounded-md border p-1.5 text-left text-xs transition-colors",
                cell.inMonth ? "border-border hover:bg-muted" : "border-transparent text-muted-foreground/50",
                // School-holiday days get a distinct amber wash (kept apart from the
                // accent, which marks "today") so a break is spottable at a glance.
                holiday && cell.inMonth ? "bg-amber-400/15 dark:bg-amber-400/10" : "",
              )}
              aria-label={dayLabel}
            >
              <span className={cn("flex size-5 items-center justify-center rounded-full", isToday ? "bg-accent font-semibold text-accent-foreground" : "")}>{cell.day}</span>
              {/* Markers are purely visual — the button's aria-label already names them
                  to a screen reader, so hide them from the a11y tree (no double read). */}
              <span aria-hidden className="flex flex-wrap items-center gap-0.5">
                {holiday ? <span title={`Vacances — ${holiday.label}`}>🏖</span> : null}
                {publicHoliday ? (
                  // A11Y-08: was a bare red dot (info by colour alone). A shape + the
                  // letter "F" makes "férié" legible without relying on colour.
                  <span title={`Férié — ${publicHoliday.label}`} className="rounded-sm bg-destructive/15 px-0.5 text-[9px] font-bold leading-none text-destructive">
                    F
                  </span>
                ) : null}
                {dayEntries.map((e) => (
                  <span key={e.id} title={e.title || entryLabel(e)}>
                    {entryIcon(e)}
                  </span>
                ))}
              </span>
              {holiday && cell.inMonth ? (
                <span className="w-full truncate text-[10px] leading-tight text-amber-700 dark:text-amber-300" title={holiday.label}>
                  {holiday.label}
                </span>
              ) : null}
            </button>
          );
        })}
      </div>

      {selectedDay !== null ? <DayDialog iso={selectedDay} entries={entriesOn(selectedDay)} onClose={() => setSelectedDay(null)} /> : null}
    </div>
  );
}
