import { AlertTriangle, CalendarClock, CalendarOff, MapPin, OctagonX, PartyPopper, Pencil } from "lucide-react";
import { useState } from "react";
import { Link, useNavigate } from "react-router-dom";

import { useWorkingSeason } from "@/features/auth/queries";
import { useSchedules } from "@/features/planning/queries";
import { usePlanningStore } from "@/features/planning/store";
import { useWizardStore } from "@/features/wizard/store";
import { Button } from "@/shared/components/ui/button";
import { toast } from "@/shared/stores/toastStore";

import type { CalendarEntry, CalendarEntryPeriodType, PublicHoliday, SchoolHoliday } from "./api";
import { useCreateHolidayPeriod, useCreateWeekChildren, useEntryConflicts, useEntryConflictsList, useSchedulePlans } from "./queries";
import { clampRangeToSeason, daysUntil, frDateShort, todayISO, weeksCovering, type WeekWindow } from "./lib/date";
import { WeekPickerDialog } from "./WeekPickerDialog";

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
  // Une période vit DANS sa saison : les dates de vacances sont clampées à la
  // fenêtre de saison avant création (l'été chevauche la frontière). Saison
  // inconnue (me en vol) → pas de création possible, fail-closed. Cache par
  // vacance (le clamp est lu au filtre + au disabled + au clic).
  const workingSeason = useWorkingSeason();
  const clampCache = new Map<string, { startDate: string; endDate: string } | null>();
  const seasonClamp = (h: SchoolHoliday): { startDate: string; endDate: string } | null => {
    if (!clampCache.has(h.id)) {
      clampCache.set(h.id, null === workingSeason ? null : clampRangeToSeason(h.startDate, h.endDate, workingSeason));
    }
    return clampCache.get(h.id) ?? null;
  };

  const adapt = (entryId: string) => {
    startPeriodMode(entryId);
    navigate("/wizard");
  };
  const viewOverlay = (overlayScheduleId: string) => {
    setSelectedScheduleId(overlayScheduleId);
    navigate("/planning");
  };

  // P2-5 E1 : adapter une période LONGUE (> 7 jours) passe par le choix des
  // semaines (un plan par semaine cochée) — voir requestAdapt plus bas.
  const [pickerFor, setPickerFor] = useState<CalendarEntry | null>(null);
  const createWeekChildren = useCreateWeekChildren();

  // ADR-0002 lot D-b : la « version active » d'une période = chosenScheduleId de son
  // plan (binaire — plan validé → on montre, non validé → on ajuste). Un seul appel,
  // mappé par entrée, plutôt qu'un hook par carte (règles des hooks dans la liste).
  // Fail-closed sur l'absence de DONNÉE : sans les plans, l'état d'une période est INCONNU —
  // on ne décide ni « à traiter » ni « tout roule », et on n'affiche aucun CTA qui pousserait à
  // régénérer un plan déjà validé (même philosophie que closureImpactsPending). Clé sur `data`,
  // PAS sur isSuccess : TanStack bascule en error sur un refetch d'arrière-plan tout en gardant
  // la donnée périmée — s'y fier ferait DISPARAÎTRE tout le radar sur un simple blip, alors qu'on
  // a des plans valides à afficher. Un 1er chargement en échec (aucune donnée) reste fail-closed.
  const plansQuery = useSchedulePlans();
  const plans = plansQuery.data;
  const plansUnresolved = undefined === plans;
  const activeByEntry = new Map<string, string>();
  for (const p of plans ?? []) {
    if (null !== p.calendarEntryId && null !== p.chosenScheduleId) {
      activeByEntry.set(p.calendarEntryId, p.chosenScheduleId);
    }
  }

  // The radar is a TO-DO list: entries the manager explicitly dismissed
  // (status=ignored) must not resurface (the calendar still shows them).
  const active = entries.filter((e) => e.status !== "ignored");

  // P2-5 E1 : une période DÉCOUPÉE = une mère + ses semaines enfants
  // (parentEntryId). Les cartes classiques ne montrent que les RACINES ; une mère
  // découpée porte une carte de COUVERTURE (chips par semaine) à la place.
  const childrenByParent = new Map<string, CalendarEntry[]>();
  for (const e of entries) {
    if (null !== e.parentEntryId) {
      childrenByParent.set(e.parentEntryId, [...(childrenByParent.get(e.parentEntryId) ?? []), e]);
    }
  }
  const roots = active.filter((e) => null === e.parentEntryId);

  // « Planning en cours » (retour fondateur 2026-07-18) : une période dont le plan a
  // des VERSIONS mais pas de version validée = travail commencé, non fini — le
  // gestionnaire a une action à faire. Ces cartes échappent au cap des vacances et
  // au filtre « à venir » (visibles jusqu'à la fin de la période) : un planning en
  // cours ne doit jamais disparaître du radar. Absence de donnée schedules →
  // aucune carte (fail-closed, même règle que plansUnresolved).
  const schedulesQuery = useSchedules();
  const schedulesUnresolved = undefined === schedulesQuery.data;
  const plansWithVersions = new Set((schedulesQuery.data ?? []).map((s) => s.schedulePlanId));
  const inProgressEntryIds = new Set(
    (plans ?? [])
      .filter((p) => null !== p.calendarEntryId && null === p.chosenScheduleId && plansWithVersions.has(p.id))
      .map((p) => p.calendarEntryId as string),
  );
  // Cartes génériques « en cours » : les périodes SANS carte riche (vacances & co).
  // Les fermetures gardent leur ClosureRadarItem (détail des séances touchées) —
  // le remplacer par une carte générique ferait disparaître l'avertissement
  // d'impact tant que le plan n'est pas validé (revue #260 round 1).
  const inProgressEntries = roots.filter((e) => inProgressEntryIds.has(e.id) && "closure" !== e.periodType && !childrenByParent.has(e.id) && e.endDate >= today);

  // Mères découpées à COUVRIR : au moins une semaine sans version validée (le radar
  // est une to-do — tout validé, la carte s'efface). Tenues hors cap et hors filtre
  // « à venir » : une couverture incomplète reste à traiter jusqu'au bout.
  const splitMothers = roots.filter((e) => {
    const children = childrenByParent.get(e.id);
    return undefined !== children && e.endDate >= today && children.some((c) => !activeByEntry.has(c.id));
  });

  // Adapter une période longue = choisir ses semaines — sauf déjà découpée (chips)
  // ou déjà générée d'un bloc (on reste sur son plan). ≤ 7 jours : direct, comme
  // avant (zéro friction sur une petite coupure). Saison inconnue → direct aussi
  // (le picker a besoin de la fenêtre de saison pour clamper).
  const requestAdapt = (entry: CalendarEntry) => {
    const planId = (plans ?? []).find((p) => p.calendarEntryId === entry.id)?.id ?? null;
    const blockGenerated = null !== planId && plansWithVersions.has(planId);
    const longPeriod = daysUntil(entry.startDate, entry.endDate) + 1 > 7;
    if (longPeriod && !childrenByParent.has(entry.id) && !blockGenerated && null !== workingSeason) {
      setPickerFor(entry);
      return;
    }
    adapt(entry.id);
  };
  const pickWeeks = (mother: CalendarEntry, weeks: WeekWindow[]) => {
    createWeekChildren.mutate(
      { mother, weeks },
      {
        onSuccess: (created) => {
          setPickerFor(null);
          if (1 === created.length) {
            adapt(created[0].id);
            return;
          }
          toast.success(`${created.length} plannings de semaine créés — reprenez-les depuis le radar.`);
        },
      },
    );
  };

  // A holiday already materialised as a period entry (matched by schoolHolidayId).
  // Ignored ones stay in the map so a dismissed holiday is skipped below, not re-proposed.
  const entryByHoliday = new Map(entries.filter((e) => null !== e.schoolHolidayId).map((e) => [e.schoolHolidayId as string, e]));

  const upcomingHolidays = holidays
    .filter((h) => h.startDate >= today)
    .filter((h) => entryByHoliday.get(h.id)?.status !== "ignored")
    // Entièrement hors de la fenêtre de saison → rien à bâtir, pas de carte.
    // (Saison inconnue = on garde la carte ; le bouton Adapter, lui, est gardé.)
    .filter((h) => null === workingSeason || null !== seasonClamp(h))
    // Déjà affichée en carte « en cours » ou en carte de COUVERTURE — pas de doublon.
    .filter((h) => {
      const e = entryByHoliday.get(h.id);
      return undefined === e || (!inProgressEntryIds.has(e.id) && !childrenByParent.has(e.id));
    })
    .sort((a, b) => a.startDate.localeCompare(b.startDate))
    .slice(0, 3);

  const disruptiveEvents = roots
    .filter((e) => e.kind === "event" && e.isDisruptive && e.endDate >= today)
    .sort((a, b) => a.startDate.localeCompare(b.startDate));

  const upcomingPeriods = (periodType: CalendarEntryPeriodType): CalendarEntry[] =>
    roots.filter((e) => e.kind === "period" && e.periodType === periodType && e.endDate >= today).sort((a, b) => a.startDate.localeCompare(b.startDate));

  const closures = upcomingPeriods("closure");
  // Le radar montre ce qui CHANGE par rapport au quotidien, pas un inventaire : une
  // fermeture qui ne heurte aucune séance, sur un planning validé, ne demande rien —
  // elle n'a rien à faire dans une liste « à traiter ». On ne peut le savoir qu'en
  // lisant l'impact, que le serveur seul calcule ; d'où la lecture groupée ici, dont
  // le résultat sert aussi à garder `isEmpty` honnête (une carte qui s'efface toute
  // seule laisserait le panneau vide SANS son « Rien à l'horizon »).
  const closureImpacts = useEntryConflictsList(closures.map((e) => e.id));
  const visibleClosures = closures.filter((entry, i) => {
    if (childrenByParent.has(entry.id)) {
      return false; // mère découpée : sa carte de COUVERTURE prend le relais
    }
    if (activeByEntry.has(entry.id)) {
      return true; // un planning secondaire VALIDÉ existe : cette semaine EST différente
    }
    if (inProgressEntryIds.has(entry.id)) {
      return true; // travail commencé, non validé : toujours à traiter (jamais masqué)
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
    inProgressEntries.length === 0 &&
    splitMothers.length === 0 &&
    upcomingHolidays.length === 0 &&
    disruptiveEvents.length === 0 &&
    visibleClosures.length === 0 &&
    !closureImpactsPending &&
    !plansUnresolved &&
    // Même règle que plansUnresolved : schedules en vol = une carte « en cours »
    // peut encore apparaître — « Tout roule » serait un silence menteur.
    !schedulesUnresolved &&
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

      {/* Plannings EN COURS d'abord : l'action la plus pressante, jamais cachée. */}
      {inProgressEntries.map((e) => (
        <RadarCard key={`wip-${e.id}`} icon={<Pencil className="size-4 text-accent" />} title={e.title} detail="Planning en cours — à finaliser">
          <Button variant="outline" size="sm" onClick={() => adapt(e.id)}>
            Reprendre
          </Button>
        </RadarCard>
      ))}

      {/* Couverture d'une période DÉCOUPÉE (P2-5 E1) : l'état par semaine, d'un
          coup d'œil — validée → Voir, sinon → Reprendre. Visible tant qu'une
          semaine n'est pas couverte. */}
      {splitMothers.map((m) => {
        const children = [...(childrenByParent.get(m.id) ?? [])].sort((a, b) => a.startDate.localeCompare(b.startDate));
        const covered = children.filter((c) => activeByEntry.has(c.id)).length;
        return (
          <RadarCard key={`split-${m.id}`} icon={<CalendarClock className="size-4 text-accent" />} title={m.title} detail={`${covered}/${children.length} semaine${children.length > 1 ? "s" : ""} couverte${covered > 1 ? "s" : ""}`}>
            {children.map((c) => {
              const activeId = activeByEntry.get(c.id) ?? null;
              const wip = inProgressEntryIds.has(c.id);
              return (
                <Button key={c.id} variant={null !== activeId ? "ghost" : "outline"} size="sm" onClick={() => (null !== activeId ? viewOverlay(activeId) : adapt(c.id))}>
                  {`sem. du ${frDateShort(c.startDate)} ${null !== activeId ? "✅" : wip ? "· en cours" : "· à faire"}`}
                </Button>
              );
            })}
          </RadarCard>
        );
      })}

      {upcomingHolidays.map((h) => {
        const entry = entryByHoliday.get(h.id);
        const activeId = entry ? (activeByEntry.get(entry.id) ?? null) : null;
        // Entrée matérialisée mais plans encore en vol : validée ou non, on ne sait PAS —
        // on n'offre donc ni « Voir » ni « Adapter » (adapter à tort régénère un plan validé).
        const stateUnknown = undefined !== entry && plansUnresolved;
        return (
          <RadarCard key={h.id} icon={<CalendarClock className="size-4 text-accent" />} title={h.label} detail={`Dans ${daysUntil(today, h.startDate)} j · ${null !== activeId ? "planning validé" : stateUnknown ? "chargement…" : "pas de planning"}`}>
            {stateUnknown ? null : null !== activeId ? (
              <Button variant="outline" size="sm" onClick={() => viewOverlay(activeId)}>
                Voir le planning
              </Button>
            ) : entry ? (
              <Button variant="outline" size="sm" onClick={() => requestAdapt(entry)}>
                Adapter
              </Button>
            ) : (
              <Button
                variant="outline"
                size="sm"
                disabled={createHoliday.isPending || null === seasonClamp(h)}
                onClick={() => {
                  const range = seasonClamp(h);
                  if (null === range) {
                    return;
                  }
                  createHoliday.mutate(
                    { schoolHolidayId: h.id, label: h.label, startDate: range.startDate, endDate: range.endDate },
                    // Vacances longues → choix des semaines (P2-5 E1) ; courtes → direct.
                    { onSuccess: (created) => requestAdapt(created) },
                  );
                }}
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
      {plansUnresolved
        ? null
        : visibleClosures.map((e) => {
            const activeId = activeByEntry.get(e.id) ?? null;
            return <ClosureRadarItem key={e.id} entry={e} activeScheduleId={activeId} inProgress={inProgressEntryIds.has(e.id)} onAdapt={() => requestAdapt(e)} onView={() => null !== activeId && viewOverlay(activeId)} />;
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

      {null !== pickerFor && null !== workingSeason ? (
        <WeekPickerDialog
          mother={pickerFor}
          weeks={weeksCovering(pickerFor.startDate, pickerFor.endDate, workingSeason)}
          busy={createWeekChildren.isPending}
          onPickWeeks={(weeks) => pickWeeks(pickerFor, weeks)}
          onAdaptWhole={() => {
            setPickerFor(null);
            adapt(pickerFor.id);
          }}
          onClose={() => setPickerFor(null)}
        />
      ) : null}
    </aside>
  );
}

function ClosureRadarItem({ entry, activeScheduleId, inProgress = false, onAdapt, onView }: { entry: CalendarEntry; activeScheduleId: string | null; inProgress?: boolean; onAdapt: () => void; onView: () => void }) {
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
  // « en cours » remplace « absent » sans perdre le CHIFFRE d'impact : la carte
  // générique qui gommait le détail des séances touchées est réservée aux
  // périodes sans carte riche (revue #260 round 1).
  const detail = hasOverlay
    ? "Planning secondaire validé"
    : impactUnknown
      ? "Impact non évalué · réessayez"
      : planIncomplete
        ? "Planning de la saison incomplet · impact non évalué"
        : `${count} séance${count > 1 ? "s" : ""} à replacer · ${inProgress ? "planning en cours — à finaliser" : "planning secondaire absent"}`;

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
          {inProgress ? "Reprendre" : "Adapter"}
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
