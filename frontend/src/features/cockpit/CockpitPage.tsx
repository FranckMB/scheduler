import { useState } from "react";
import { Navigate } from "react-router-dom";

import { useMe } from "@/features/auth/queries";
import { useSchedules } from "@/features/planning/queries";
import { FullPageSpinner } from "@/shared/components/ui/spinner";

import { BaselineBanner } from "./BaselineBanner";
import { MonthCalendar } from "./MonthCalendar";
import { RadarPanel } from "./RadarPanel";
import { useCalendarEntries, useSchoolHolidays } from "./queries";
import { monthWindow } from "./lib/date";

/** Home cockpit — unlocked once the season's baseline plan has been validated (sticky). Before that, the work-loop is home. */
export function CockpitPage() {
  const { data: me, isLoading } = useMe();
  const now = new Date();
  const [cursor, setCursor] = useState({ year: now.getFullYear(), month: now.getMonth() });

  const { from, to } = monthWindow(cursor.year, cursor.month);
  const { data: entries = [] } = useCalendarEntries(from, to);
  const { data: holidays } = useSchoolHolidays();
  const { data: schedules = [] } = useSchedules();

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
      <BaselineBanner schedules={schedules} baselineScheduleId={me.baselineScheduleId} />
      <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <MonthCalendar year={cursor.year} month={cursor.month} entries={entries} holidays={holidays?.items ?? []} onPrev={prev} onNext={next} />
        <RadarPanel entries={entries} holidays={holidays?.items ?? []} zone={holidays?.zone ?? null} />
      </div>
    </div>
  );
}
