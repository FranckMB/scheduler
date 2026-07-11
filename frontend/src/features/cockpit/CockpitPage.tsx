import { Lock } from "lucide-react";
import { useState } from "react";
import { Navigate } from "react-router-dom";

import { useMe } from "@/features/auth/queries";
import { useSchedules } from "@/features/planning/queries";
import { FullPageSpinner } from "@/shared/components/ui/spinner";

import { BaselineBanner } from "./BaselineBanner";
import { isAdaptableHoliday } from "./lib/holidays";
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
  // School holidays: season-wide for the radar (reminders), visible-month for the
  // calendar (so summer — and any month outside the season — shows when browsed).
  const { data: holidays, isLoading: holidaysLoading } = useSchoolHolidays();
  const { data: monthHolidays } = useSchoolHolidays(from, to);
  // Summer is an INFO band only (season boundary, not an exception to plan) — it
  // shows on the calendar but must never become a radar to-do reminder (revue #204).
  const radarHolidays = (holidays?.items ?? []).filter(isAdaptableHoliday);
  // Two explicit windows (the endpoint 400s without one when no season is active):
  // the visible month grid for the calendar dots, the radar horizon for reminders.
  const { data: publicHolidays } = usePublicHolidays(from, to);
  const { data: radarPublicHolidays, isLoading: publicHolidaysLoading } = usePublicHolidays(radarToday, addDays(radarToday, PUBLIC_HOLIDAY_HORIZON_DAYS));
  const { data: schedules = [], isLoading: schedulesLoading } = useSchedules();

  if (isLoading) {
    return <FullPageSpinner />;
  }
  // Onboarding (no main plan yet) → the wizard is home (AuthGuard also enforces
  // this; kept here as a defensive redirect). Once a baseline exists the cockpit
  // is the home screen, whether or not the socle is validated yet.
  if (null === (me?.baselineScheduleId ?? null)) {
    return <Navigate to="/wizard" replace />;
  }
  // State 2 (baseline exists but not validated): the cockpit is reachable but
  // matches + secondary plans stay locked until the main plan is validated.
  const socleValidated = null !== me?.socleValidatedAt;

  const prev = () => setCursor((c) => (c.month === 0 ? { year: c.year - 1, month: 11 } : { year: c.year, month: c.month - 1 }));
  const next = () => setCursor((c) => (c.month === 11 ? { year: c.year + 1, month: 0 } : { year: c.year, month: c.month + 1 }));

  return (
    <div className="space-y-4">
      {!socleValidated ? (
        <div className="flex items-start gap-2 rounded-md border border-accent/40 bg-accent/10 px-3 py-2 text-sm" role="status">
          <Lock className="mt-0.5 size-4 shrink-0 text-accent" />
          <span className="text-muted-foreground">
            Planning principal <strong className="text-foreground">non validé</strong> — validez-le pour débloquer les <strong className="text-foreground">matchs</strong> et les <strong className="text-foreground">plannings secondaires</strong>.
          </span>
        </div>
      ) : null}
      <BaselineBanner schedules={schedules} baselineScheduleId={me?.baselineScheduleId ?? null} socleValidated={socleValidated} loading={schedulesLoading} />
      <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <MonthCalendar year={cursor.year} month={cursor.month} entries={entries} holidays={monthHolidays?.items ?? []} publicHolidays={publicHolidays?.items ?? []} onPrev={prev} onNext={next} />
        <RadarPanel
          entries={radarEntries}
          holidays={radarHolidays}
          publicHolidays={radarPublicHolidays?.items ?? []}
          publicHolidaysLoading={publicHolidaysLoading}
          zone={holidays?.zone ?? null}
          zoneLoading={holidaysLoading}
        />
      </div>
    </div>
  );
}
