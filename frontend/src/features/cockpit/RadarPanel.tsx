import { AlertTriangle, CalendarClock, CalendarOff, MapPin, OctagonX, PartyPopper } from "lucide-react";
import { Link, useNavigate } from "react-router-dom";

import { usePlanningStore } from "@/features/planning/store";
import { useWizardStore } from "@/features/wizard/store";
import { Button } from "@/shared/components/ui/button";

import type { CalendarEntry, CalendarEntryPeriodType, PublicHoliday, SchoolHoliday } from "./api";
import { useCreateHolidayPeriod, useEntryConflicts, useEntryConflictsList, useSchedulePlans } from "./queries";
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

  // ADR-0002 lot D-b : la « version active » d'une période = chosenScheduleId de son
  // plan (binaire — plan validé → on montre, non validé → on ajuste). Un seul appel,
  // mappé par entrée, plutôt qu'un hook par carte (règles des hooks dans la liste).
  // Fail-closed : tant que les plans chargent, l'état d'une période est INCONNU — on ne
  // décide donc ni « à traiter » ni « tout roule », et on n'affiche aucun CTA qui, à tort,
  // pousserait à régénérer un plan déjà validé (même philosophie que closureImpactsPending).
  const { data: plans, isLoading: plansLoading } = useSchedulePlans();
  const activeByEntry = new Map<string, string>();
  for (const p of plans ?? []) {
    if (null !== p.calendarEntryId && null !== p.chosenScheduleId) {
      activeByEntry.set(p.calendarEntryId, p.chosenScheduleId);
    }
  }

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
  // Le radar montre ce qui CHANGE par rapport au quotidien, pas un inventaire : une
  // fermeture qui ne heurte aucune séance, sur un planning validé, ne demande rien —
  // elle n'a rien à faire dans une liste « à traiter ». On ne peut le savoir qu'en
  // lisant l'impact, que le serveur seul calcule ; d'où la lecture groupée ici, dont
  // le résultat sert aussi à garder `isEmpty` honnête (une carte qui s'efface toute
  // seule laisserait le panneau vide SANS son « Rien à l'horizon »).
  const closureImpacts = useEntryConflictsList(closures.map((e) => e.id));
  const visibleClosures = closures.filter((entry, i) => {
    if (activeByEntry.has(entry.id)) {
      return true; // un planning secondaire VALIDÉ existe : cette semaine EST différente
    }
    const impact = closureImpacts[i];
    if (impact?.isPending) {
      return false; // on ne sait pas ENCORE : ne pas faire clignoter une carte qui va disparaître
    }
    if (undefined === impact?.data) {
      return true; // la requête a échoué : on ne sait pas, et ne pas savoir se traite
    }
    if (false === impact.data.seasonPlanChosen) {
      return true; // plan incomplet : impact non évalué
    }
    return impact.data.conflicts.some((c) => c.dates.length > 0);
  });
  // Masquer une fermeture parce qu'on ne SAIT PAS encore, tout en annonçant « Tout
  // roule », c'est le silence qui ment que `seasonPlanChosen` sert à tuer — déplacé
  // du libellé vers le filtre de visibilité, où le drapeau n'est même plus lu. Tant
  // qu'un impact est en vol, le panneau n'est pas « vide », il est incomplet.
  const closureImpactsPending = closureImpacts.some((q) => q.isPending);
  // Disruption reminders, no CTA: a cutoff means "no training", there is no plan to prepare.
  const cutoffs = upcomingPeriods("cutoff");

  const upcomingPublicHolidays = publicHolidays
    .filter((h) => h.date >= today && daysUntil(today, h.date) <= PUBLIC_HOLIDAY_HORIZON_DAYS)
    .sort((a, b) => a.date.localeCompare(b.date));

  const isEmpty =
    upcomingHolidays.length === 0 &&
    disruptiveEvents.length === 0 &&
    visibleClosures.length === 0 &&
    !closureImpactsPending &&
    !plansLoading &&
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
        const activeId = entry ? (activeByEntry.get(entry.id) ?? null) : null;
        // Entrée matérialisée mais plans encore en vol : validée ou non, on ne sait PAS —
        // on n'offre donc ni « Voir » ni « Adapter » (adapter à tort régénère un plan validé).
        const stateUnknown = undefined !== entry && plansLoading;
        return (
          <RadarCard key={h.id} icon={<CalendarClock className="size-4 text-accent" />} title={h.label} detail={`Dans ${daysUntil(today, h.startDate)} j · ${null !== activeId ? "planning validé" : stateUnknown ? "chargement…" : "pas de planning"}`}>
            {stateUnknown ? null : null !== activeId ? (
              <Button variant="outline" size="sm" onClick={() => viewOverlay(activeId)}>
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

      {/* Fermetures : tenues tant que les plans chargent (Voir/Adapter dépend de l'état du plan). */}
      {plansLoading
        ? null
        : visibleClosures.map((e) => {
            const activeId = activeByEntry.get(e.id) ?? null;
            return <ClosureRadarItem key={e.id} entry={e} activeScheduleId={activeId} onAdapt={() => adapt(e.id)} onView={() => null !== activeId && viewOverlay(activeId)} />;
          })}

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

function ClosureRadarItem({ entry, activeScheduleId, onAdapt, onView }: { entry: CalendarEntry; activeScheduleId: string | null; onAdapt: () => void; onView: () => void }) {
  const { data } = useEntryConflicts(entry.id);
  const count = data?.conflicts.reduce((sum, c) => sum + c.dates.length, 0) ?? 0;
  // ADR-0002 lot D-b : « a un overlay » = le plan de la période est VALIDÉ (chosenScheduleId).
  const hasOverlay = null !== activeScheduleId;
  // Le plan de la saison existe mais ne pointe aucune version : il est INCOMPLET,
  // et le serveur n'a donc aucun calendrier à comparer. Dire « aucun impact » serait
  // un mensonge rassurant — le gestionnaire n'adapterait pas une fermeture qui, en
  // vrai, touchera ses séances. Un plan qui pointe et ne heurte rien, lui, n'a
  // vraiment rien à signaler : les deux états ne doivent pas se dire pareil.
  // Trois causes distinctes de « pas de chiffre », à ne pas confondre : le plan de la
  // saison ne pointe rien (fait VÉRIFIÉ), ou la lecture a échoué (on ne sait pas, et
  // affirmer « plan incomplet » serait énoncer un fait jamais contrôlé).
  const planIncomplete = false === data?.seasonPlanChosen;
  const impactUnknown = undefined === data;

  // Le parent ne monte cette carte que s'il y a quelque chose à traiter (voir
  // visibleClosures) : pas de branche « rien à signaler » ici, elle ne serait
  // jamais rendue — et l'écrire laisserait croire que le radar inventorie.
  const detail = hasOverlay
    ? "Planning secondaire validé"
    : impactUnknown
      ? "Impact non évalué · réessayez"
      : planIncomplete
        ? "Planning de la saison incomplet · impact non évalué"
        : `${count} séance${count > 1 ? "s" : ""} à replacer · planning secondaire absent`;

  return (
    <RadarCard
      icon={<AlertTriangle className={hasOverlay ? "size-4 text-accent" : "size-4 text-destructive"} />}
      title={entry.title}
      detail={detail}
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
