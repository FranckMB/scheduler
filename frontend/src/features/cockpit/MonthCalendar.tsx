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
              aria-label={`Jour ${cell.iso}`}
            >
              <span className={cn("flex size-5 items-center justify-center rounded-full", isToday ? "bg-accent font-semibold text-accent-foreground" : "")}>{cell.day}</span>
              <span className="flex flex-wrap items-center gap-0.5">
                {holiday ? (
                  <span role="img" aria-label={`Vacances — ${holiday.label}`} title={`Vacances — ${holiday.label}`}>
                    🏖
                  </span>
                ) : null}
                {publicHoliday ? (
                  <span role="img" aria-label={`Férié — ${publicHoliday.label}`} title={`Férié — ${publicHoliday.label}`} className="inline-block size-1.5 rounded-full bg-destructive" />
                ) : null}
                {dayEntries.map((e) => (
                  <span key={e.id} role="img" aria-label={e.title} title={e.title}>
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
