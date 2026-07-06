import { useState } from "react";
import { Navigate } from "react-router-dom";

import { useMe } from "@/features/auth/queries";
import { useSchedules } from "@/features/planning/queries";
import { FullPageSpinner } from "@/shared/components/ui/spinner";

import { BaselineBanner } from "./BaselineBanner";
import { MonthCalendar } from "./MonthCalendar";
import { PUBLIC_HOLIDAY_HORIZON_DAYS, RadarPanel } from "./RadarPanel";
import { useCalendarEntries, usePublicHolidays, useSchoolHolidays } from "./queries";
import { addDays, monthWindow, todayISO } from "./lib/date";

/** Home cockpit — unlocked once the season's baseline plan has been validated (sticky). Before that, the work-loop is home. */
export function CockpitPage() {
  const { data: me, isLoading } = useMe();
  const now = new Date();
  const [cursor, setCursor] = useState({ year: now.getFullYear(), month: now.getMonth() });

  const { from, to } = monthWindow(cursor.year, cursor.month);
  const { data: entries = [] } = useCalendarEntries(from, to);
  // The radar surfaces upcoming to-dos season-wide, not just the visible month.
  const radarToday = todayISO();
  const { data: radarEntries = [] } = useCalendarEntries(radarToday, addDays(radarToday, 300));
  const { data: holidays, isLoading: holidaysLoading } = useSchoolHolidays();
  // Two explicit windows (the endpoint 400s without one when no season is active):
  // the visible month grid for the calendar dots, the radar horizon for reminders.
  const { data: publicHolidays } = usePublicHolidays(from, to);
  const { data: radarPublicHolidays, isLoading: publicHolidaysLoading } = usePublicHolidays(radarToday, addDays(radarToday, PUBLIC_HOLIDAY_HORIZON_DAYS));
  const { data: schedules = [], isLoading: schedulesLoading } = useSchedules();

  if (isLoading) {
    return <FullPageSpinner />;
  }
  // Sticky gate: no validated socle yet → the work-loop is the home screen.
  if (!me?.socleValidatedAt) {
    return <Navigate to="/planning" replace />;
  }

  const prev = () => setCursor((c) => (c.month === 0 ? { year: c.year - 1, month: 11 } : { year: c.year, month: c.month - 1 }));
  const next = () => setCursor((c) => (c.month === 11 ? { year: c.year + 1, month: 0 } : { year: c.year, month: c.month + 1 }));

  return (
    <div className="space-y-4">
      <BaselineBanner schedules={schedules} baselineScheduleId={me.baselineScheduleId} loading={schedulesLoading} />
      <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <MonthCalendar year={cursor.year} month={cursor.month} entries={entries} holidays={holidays?.items ?? []} publicHolidays={publicHolidays?.items ?? []} onPrev={prev} onNext={next} />
        <RadarPanel
          entries={radarEntries}
          holidays={holidays?.items ?? []}
          publicHolidays={radarPublicHolidays?.items ?? []}
          publicHolidaysLoading={publicHolidaysLoading}
          zone={holidays?.zone ?? null}
          zoneLoading={holidaysLoading}
        />
      </div>
    </div>
  );
}
