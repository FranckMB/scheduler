import { AlertTriangle, CalendarClock, MapPin, PartyPopper } from "lucide-react";
import { Link } from "react-router-dom";

import { Button } from "@/shared/components/ui/button";

import type { CalendarEntry, SchoolHoliday } from "./api";
import { useEntryConflicts } from "./queries";
import { daysUntil, todayISO } from "./lib/date";

interface RadarPanelProps {
  entries: CalendarEntry[];
  holidays: SchoolHoliday[];
  zone: string | null;
}

/** The manager's to-do, sorted by urgency. Palier A surfaces; CTAs that generate an overlay are palier B. */
export function RadarPanel({ entries, holidays, zone }: RadarPanelProps) {
  const today = todayISO();

  const upcomingHolidays = holidays
    .filter((h) => h.startDate >= today)
    .sort((a, b) => a.startDate.localeCompare(b.startDate))
    .slice(0, 3);

  const disruptiveEvents = entries
    .filter((e) => e.kind === "event" && e.isDisruptive && e.endDate >= today)
    .sort((a, b) => a.startDate.localeCompare(b.startDate));

  const closures = entries.filter((e) => e.kind === "period" && e.periodType === "closure" && e.endDate >= today).sort((a, b) => a.startDate.localeCompare(b.startDate));

  const isEmpty = upcomingHolidays.length === 0 && disruptiveEvents.length === 0 && closures.length === 0 && zone !== null;

  return (
    <aside className="space-y-3 rounded-lg border border-border bg-card p-4">
      <h2 className="text-sm font-semibold">À traiter</h2>

      {zone === null ? (
        <RadarCard icon={<MapPin className="size-4" />} title="Zone scolaire à renseigner" detail="Renseigne la zone pour voir les vacances.">
          <Button variant="outline" size="sm" asChild>
            <Link to="/club">Renseigner</Link>
          </Button>
        </RadarCard>
      ) : null}

      {upcomingHolidays.map((h) => (
        <RadarCard key={h.id} icon={<CalendarClock className="size-4 text-accent" />} title={h.label} detail={`Dans ${daysUntil(today, h.startDate)} j · pas de plan`}>
          <Button variant="ghost" size="sm" disabled title="Génération de plan — à venir (palier B)">
            Adapter
          </Button>
        </RadarCard>
      ))}

      {disruptiveEvents.map((e) => (
        <RadarCard key={e.id} icon={<PartyPopper className="size-4 text-accent" />} title={e.title} detail={`Le ${e.startDate} · pas d'entraînement`} />
      ))}

      {closures.map((e) => (
        <ClosureRadarItem key={e.id} entry={e} />
      ))}

      {isEmpty ? <p className="text-sm text-muted-foreground">Rien à l'horizon. Tout roule.</p> : null}
    </aside>
  );
}

function ClosureRadarItem({ entry }: { entry: CalendarEntry }) {
  const { data } = useEntryConflicts(entry.id);
  const count = data?.conflicts.reduce((sum, c) => sum + c.dates.length, 0) ?? 0;

  return (
    <RadarCard
      icon={<AlertTriangle className="size-4 text-destructive" />}
      title={entry.title}
      detail={count > 0 ? `${count} séance${count > 1 ? "s" : ""} à replacer · plan secondaire absent` : "Indisponibilité signalée"}
    >
      <Button variant="ghost" size="sm" disabled title="Adapter la période — à venir (palier B)">
        Adapter
      </Button>
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
      {children ? <div className="mt-2 flex justify-end">{children}</div> : null}
    </div>
  );
}
