import { AlertTriangle, CalendarClock, CalendarOff, MapPin, OctagonX, PartyPopper } from "lucide-react";
import { Link, useNavigate } from "react-router-dom";

import { usePlanningStore } from "@/features/planning/store";
import { useWizardStore } from "@/features/wizard/store";
import { Button } from "@/shared/components/ui/button";

import type { CalendarEntry, CalendarEntryPeriodType, PublicHoliday, SchoolHoliday } from "./api";
import { useCreateHolidayPeriod, useEntryConflicts } from "./queries";
import { daysUntil, frDateShort, todayISO } from "./lib/date";

/** Public holidays further out than this are noise, not a to-do. */
export const PUBLIC_HOLIDAY_HORIZON_DAYS = 30;

interface RadarPanelProps {
  entries: CalendarEntry[];
  holidays: SchoolHoliday[];
  publicHolidays: PublicHoliday[];
  /** Public-holidays query still in flight — don't flash the all-clear meanwhile. */
  publicHolidaysLoading?: boolean;
  zone: string | null;
  /** Holidays query still in flight — don't flash "zone à renseigner" meanwhile. */
  zoneLoading?: boolean;
}

/** The manager's to-do, sorted by urgency. "Adapter" opens the wizard in period mode (palier B). */
export function RadarPanel({ entries, holidays, publicHolidays, publicHolidaysLoading = false, zone, zoneLoading = false }: RadarPanelProps) {
  const today = todayISO();
  const navigate = useNavigate();
  const startPeriodMode = useWizardStore((s) => s.startPeriodMode);
  const setSelectedScheduleId = usePlanningStore((s) => s.setSelectedScheduleId);
  const createHoliday = useCreateHolidayPeriod();

  const adapt = (entryId: string) => {
    startPeriodMode(entryId);
    navigate("/wizard");
  };
  const viewOverlay = (overlayScheduleId: string) => {
    setSelectedScheduleId(overlayScheduleId);
    navigate("/planning");
  };

  // The radar is a TO-DO list: entries the manager explicitly dismissed
  // (status=ignored) must not resurface (the calendar still shows them).
  const active = entries.filter((e) => e.status !== "ignored");

  // A holiday already materialised as a period entry (matched by schoolHolidayId).
  // Ignored ones stay in the map so a dismissed holiday is skipped below, not re-proposed.
  const entryByHoliday = new Map(entries.filter((e) => null !== e.schoolHolidayId).map((e) => [e.schoolHolidayId as string, e]));

  const upcomingHolidays = holidays
    .filter((h) => h.startDate >= today)
    .filter((h) => entryByHoliday.get(h.id)?.status !== "ignored")
    .sort((a, b) => a.startDate.localeCompare(b.startDate))
    .slice(0, 3);

  const disruptiveEvents = active
    .filter((e) => e.kind === "event" && e.isDisruptive && e.endDate >= today)
    .sort((a, b) => a.startDate.localeCompare(b.startDate));

  const upcomingPeriods = (periodType: CalendarEntryPeriodType): CalendarEntry[] =>
    active.filter((e) => e.kind === "period" && e.periodType === periodType && e.endDate >= today).sort((a, b) => a.startDate.localeCompare(b.startDate));

  const closures = upcomingPeriods("closure");
  // Disruption reminders, no CTA: a cutoff means "no training", there is no plan to prepare.
  const cutoffs = upcomingPeriods("cutoff");

  const upcomingPublicHolidays = publicHolidays
    .filter((h) => h.date >= today && daysUntil(today, h.date) <= PUBLIC_HOLIDAY_HORIZON_DAYS)
    .sort((a, b) => a.date.localeCompare(b.date));

  const isEmpty =
    upcomingHolidays.length === 0 &&
    disruptiveEvents.length === 0 &&
    closures.length === 0 &&
    cutoffs.length === 0 &&
    upcomingPublicHolidays.length === 0 &&
    zone !== null &&
    !zoneLoading &&
    !publicHolidaysLoading;

  return (
    <aside className="space-y-3 rounded-lg border border-border bg-card p-4">
      <h2 className="text-sm font-semibold">À traiter</h2>

      {zone === null && !zoneLoading ? (
        <RadarCard icon={<MapPin className="size-4" />} title="Zone scolaire à renseigner" detail="Renseigne la zone pour voir les vacances.">
          <Button variant="outline" size="sm" asChild>
            <Link to="/club">Renseigner</Link>
          </Button>
        </RadarCard>
      ) : null}

      {upcomingHolidays.map((h) => {
        const entry = entryByHoliday.get(h.id);
        return (
          <RadarCard key={h.id} icon={<CalendarClock className="size-4 text-accent" />} title={h.label} detail={`Dans ${daysUntil(today, h.startDate)} j · ${entry?.overlayScheduleId ? "planning généré" : "pas de planning"}`}>
            {entry?.overlayScheduleId ? (
              <Button variant="outline" size="sm" onClick={() => viewOverlay(entry.overlayScheduleId as string)}>
                Voir le planning
              </Button>
            ) : entry ? (
              <Button variant="outline" size="sm" onClick={() => adapt(entry.id)}>
                Adapter
              </Button>
            ) : (
              <Button
                variant="outline"
                size="sm"
                disabled={createHoliday.isPending}
                onClick={() =>
                  createHoliday.mutate(
                    { schoolHolidayId: h.id, label: h.label, startDate: h.startDate, endDate: h.endDate },
                    { onSuccess: (created) => adapt(created.id) },
                  )
                }
              >
                Adapter
              </Button>
            )}
          </RadarCard>
        );
      })}

      {disruptiveEvents.map((e) => (
        <RadarCard key={e.id} icon={<PartyPopper className="size-4 text-accent" />} title={e.title} detail={`Le ${frDateShort(e.startDate)} · pas d'entraînement`} />
      ))}

      {closures.map((e) => (
        <ClosureRadarItem key={e.id} entry={e} onAdapt={() => adapt(e.id)} onView={() => e.overlayScheduleId && viewOverlay(e.overlayScheduleId)} />
      ))}

      {cutoffs.map((e) => (
        <RadarCard
          key={e.id}
          icon={<OctagonX className="size-4 text-destructive" />}
          title={e.title}
          detail={e.startDate === e.endDate ? `Le ${frDateShort(e.startDate)} · aucun entraînement` : `Du ${frDateShort(e.startDate)} au ${frDateShort(e.endDate)} · aucun entraînement`}
        />
      ))}

      {upcomingPublicHolidays.map((h) => (
        <RadarCard key={h.id} icon={<CalendarOff className="size-4 text-destructive" />} title={h.label} detail={`Dans ${daysUntil(today, h.date)} j · jour férié`} />
      ))}

      {isEmpty ? <p className="text-sm text-muted-foreground">Rien à l'horizon. Tout roule.</p> : null}
    </aside>
  );
}

function ClosureRadarItem({ entry, onAdapt, onView }: { entry: CalendarEntry; onAdapt: () => void; onView: () => void }) {
  const { data } = useEntryConflicts(entry.id);
  const count = data?.conflicts.reduce((sum, c) => sum + c.dates.length, 0) ?? 0;
  const hasOverlay = null !== entry.overlayScheduleId;

  return (
    <RadarCard
      icon={<AlertTriangle className={hasOverlay ? "size-4 text-accent" : "size-4 text-destructive"} />}
      title={entry.title}
      detail={hasOverlay ? "Planning secondaire généré" : count > 0 ? `${count} séance${count > 1 ? "s" : ""} à replacer · planning secondaire absent` : "Indisponibilité signalée"}
    >
      {hasOverlay ? (
        <>
          <Button variant="outline" size="sm" onClick={onView}>
            Voir le planning
          </Button>
          <Button variant="ghost" size="sm" onClick={onAdapt}>
            Ajuster
          </Button>
        </>
      ) : (
        <Button variant="outline" size="sm" onClick={onAdapt}>
          Adapter
        </Button>
      )}
    </RadarCard>
  );
}

function RadarCard({ icon, title, detail, children }: { icon: React.ReactNode; title: string; detail: string; children?: React.ReactNode }) {
  return (
    <div className="rounded-md border border-border p-3">
      <div className="flex items-start gap-2">
        <span className="mt-0.5">{icon}</span>
        <div className="min-w-0 flex-1">
          <p className="truncate text-sm font-medium">{title}</p>
          <p className="text-xs text-muted-foreground">{detail}</p>
        </div>
      </div>
      {children ? <div className="mt-2 flex justify-end gap-1">{children}</div> : null}
    </div>
  );
}
