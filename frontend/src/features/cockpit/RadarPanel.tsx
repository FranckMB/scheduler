import { AlertTriangle, CalendarClock, CalendarOff, MapPin, OctagonX, PartyPopper, Pencil } from "lucide-react";
import { Link, useNavigate } from "react-router-dom";

import { useWorkingSeason } from "@/features/auth/queries";
import { useSchedules } from "@/features/planning/queries";
import { usePlanningStore } from "@/features/planning/store";
import { useWizardStore } from "@/features/wizard/store";
import { Button } from "@/shared/components/ui/button";

import type { CalendarEntry, CalendarEntryPeriodType, PublicHoliday, SchoolHoliday } from "./api";
import { useEntryConflicts, useEntryConflictsList, useSchedulePlans } from "./queries";
import { clampRangeToSeason, daysUntil, frDateShort, periodAdjustWeeks, todayISO, weeksCovering, type WeekWindow } from "./lib/date";
import { seasonLockTitle, useSocleValidated } from "./lib/socle";
import { useWeekAdapt } from "./lib/useWeekAdapt";
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
  // Gating (#5) : sans plan de SAISON validé (chosenScheduleId), aucun planning
  // secondaire — les boutons d'ajustement sont désactivés, un encart rouge invite
  // à finir la validation.
  const socleValidated = useSocleValidated();
  const lockTitle = seasonLockTitle(socleValidated);
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

  // P2-5 E1 : flux de découpage partagé (radar + DayDialog) — voir requestAdapt.
  // Chemin `pending` : la mère vacances naît SEULEMENT à la confirmation du picker.
  const { pickerFor, setPickerFor, pendingHoliday, setPendingHoliday, openPendingPicker, createWeekChildren, createHoliday, pickWeeks, pickWeeksPending, adaptWholePending, createOneWeek } = useWeekAdapt(adapt);

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

  // « Planning en cours » (retour fondateur 2026-07-18) : deux niveaux, à ne pas
  // confondre.
  // - `startedEntryIds` : plan AVEC versions, pas encore validé = travail COMMENCÉ.
  //   Sert au libellé des chips (« en cours » vs « à faire ») et à l'état d'une
  //   fermeture — le distinguer d'un plan à 0 version est le sens de ces libellés.
  // - `pendingEntryIds` : plan existant sans version validée, versions OU NON
  //   (retour fondateur 2026-07-19) — sert à la carte générique « en cours » : une
  //   vacance ajustée « d'un bloc » mais PAS encore générée doit rester visible pour
  //   être reprise. Ces cartes échappent au cap des vacances et au filtre « à venir ».
  const schedulesQuery = useSchedules();
  const schedulesUnresolved = undefined === schedulesQuery.data;
  const plansWithVersions = new Set((schedulesQuery.data ?? []).map((s) => s.schedulePlanId));
  const startedEntryIds = new Set(
    (plans ?? [])
      .filter((p) => null !== p.calendarEntryId && null === p.chosenScheduleId && plansWithVersions.has(p.id))
      .map((p) => p.calendarEntryId as string),
  );
  const pendingEntryIds = new Set(
    (plans ?? [])
      .filter((p) => null !== p.calendarEntryId && null === p.chosenScheduleId)
      .map((p) => p.calendarEntryId as string),
  );
  // Cartes génériques « en cours » : les périodes SANS carte riche (vacances & co).
  // Les fermetures gardent leur ClosureRadarItem (détail des séances touchées) —
  // le remplacer par une carte générique ferait disparaître l'avertissement
  // d'impact tant que le plan n'est pas validé (revue #260 round 1).
  const inProgressEntries = roots.filter((e) => pendingEntryIds.has(e.id) && "closure" !== e.periodType && !childrenByParent.has(e.id) && e.endDate >= today);

  // Mères découpées à COUVRIR : une semaine existante non validée OU une semaine
  // MANQUANTE (décochée au picker, échec partiel — revue #262 : sans chip « à
  // créer », une semaine décochée devenait à jamais implanifiable). Visible tant
  // que la DERNIÈRE fenêtre (mère ou enfants — une semaine pleine déborde la
  // mère) n'est pas passée ; tout couvert → la carte s'efface (to-do).
  const motherWeekSlots = (m: CalendarEntry): { week: WeekWindow; child: CalendarEntry | null }[] => {
    const children = childrenByParent.get(m.id) ?? [];
    if (null === workingSeason) {
      // Saison inconnue : pas de calcul des manquantes — les enfants existants font foi.
      return children.map((c) => ({ week: { startDate: c.startDate, endDate: c.endDate, monday: c.startDate }, child: c }));
    }
    // Filet #262 + revue C F1 : on itère TOUTES les semaines calendaires et on garde
    // une semaine si elle porte un enfant EXISTANT (toujours visible/gérable) OU si
    // elle est OFFERTE à la création (periodAdjustWeeks écarte la semaine partielle
    // d'une vacance démarrant Ven/Sam/Dim). Une semaine partielle SANS enfant
    // disparaît (pas de chip « + créer ») ; AVEC enfant, elle reste (jamais orpheline).
    const offeredMondays = new Set(periodAdjustWeeks(m.startDate, m.endDate, workingSeason, m.periodType).map((w) => w.monday));
    return weeksCovering(m.startDate, m.endDate, workingSeason)
      .map((week) => ({ week, child: children.find((c) => c.startDate <= week.endDate && c.endDate >= week.startDate) ?? null }))
      .filter(({ week, child }) => null !== child || offeredMondays.has(week.monday));
  };
  const splitMothers = roots.filter((e) => {
    const children = childrenByParent.get(e.id);
    if (undefined === children) {
      return false;
    }
    const lastEnd = children.reduce((mx, c) => (c.endDate > mx ? c.endDate : mx), e.endDate);
    if (lastEnd < today) {
      return false;
    }
    return motherWeekSlots(e).some(({ child }) => null === child || !activeByEntry.has(child.id));
  });

  // Semaines ORPHELINES D'AFFICHAGE : une semaine dont la mère ne porte AUCUNE
  // carte de couverture — mère sortie de la fenêtre radar (finie), OU écartée
  // (ignorée) : dans les deux cas, sans surface, la semaine encore courante et
  // non validée serait implanifiable (revue #262 rounds 2-3). Carte dédiée.
  const renderedMotherIds = new Set(splitMothers.map((m) => m.id));
  const orphanWeekChildren = active.filter(
    (e) => null !== e.parentEntryId && !renderedMotherIds.has(e.parentEntryId) && e.endDate >= today && !activeByEntry.has(e.id),
  );

  // Adapter une période qui COUVRE PLUSIEURS SEMAINES = les choisir — sauf déjà
  // découpée (chips) ou déjà générée d'un bloc (on reste sur son plan). Le critère
  // est le NOMBRE de semaines calendaires, pas la durée : 7 jours à cheval
  // jeu→mer = 2 semaines (l'exemple fondateur 12–18 nov — revue #262 round 2).
  // Saison inconnue ou schedules pas résolus → direct (fail-safe : « bloc
  // généré ? » inconnu, offrir le picker ferait 422 chaque semaine).
  const requestAdapt = (entry: CalendarEntry) => {
    const planId = (plans ?? []).find((p) => p.calendarEntryId === entry.id)?.id ?? null;
    const blockGenerated = null !== planId && plansWithVersions.has(planId);
    const multiWeek = null !== workingSeason && periodAdjustWeeks(entry.startDate, entry.endDate, workingSeason, entry.periodType).length > 1;
    if (multiWeek && !childrenByParent.has(entry.id) && !schedulesUnresolved && !blockGenerated) {
      setPickerFor(entry);
      return;
    }
    adapt(entry.id);
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
      return undefined === e || (!pendingEntryIds.has(e.id) && !childrenByParent.has(e.id));
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
    if (startedEntryIds.has(entry.id)) {
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
  // Impact des mères CLOSURE découpées : la carte de couverture remplace leur
  // ClosureRadarItem — elle doit garder le CHIFFRE des séances touchées (revue
  // #260 l'avait exigé ; #262 round 2 l'a vu disparaître au découpage).
  const splitClosureMothers = splitMothers.filter((m) => "closure" === m.periodType);
  const splitClosureImpacts = useEntryConflictsList(splitClosureMothers.map((m) => m.id));
  const splitImpactCountByEntry = new Map<string, number>(
    splitClosureMothers.map((m, i) => [m.id, (splitClosureImpacts[i]?.data?.conflicts ?? []).reduce((sum, c) => sum + c.dates.length, 0)]),
  );
  // Disruption reminders, no CTA: a cutoff means "no training", there is no plan to prepare.
  const cutoffs = upcomingPeriods("cutoff");

  const upcomingPublicHolidays = publicHolidays
    .filter((h) => h.date >= today && daysUntil(today, h.date) <= PUBLIC_HOLIDAY_HORIZON_DAYS)
    .sort((a, b) => a.date.localeCompare(b.date));

  const isEmpty =
    inProgressEntries.length === 0 &&
    orphanWeekChildren.length === 0 &&
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

      {/* Gating (#5) : plan de saison non validé → tout ajustement est bloqué. Encart
          rouge en TÊTE, l'action la plus prioritaire : finir de valider la saison. */}
      {!socleValidated ? (
        <div className="rounded-md border border-destructive/50 bg-destructive/10 p-3">
          <div className="flex items-start gap-2">
            <AlertTriangle className="mt-0.5 size-4 shrink-0 text-destructive" />
            <div className="min-w-0 flex-1">
              <p className="text-sm font-medium text-destructive">Planning de la saison à valider</p>
              <p className="text-xs text-muted-foreground">Validez le planning principal pour débloquer les ajustements.</p>
            </div>
          </div>
          <div className="mt-2 flex justify-end">
            <Button variant="outline" size="sm" asChild>
              <Link to="/wizard">Valider le planning</Link>
            </Button>
          </div>
        </div>
      ) : null}

      {zone === null && !zoneLoading ? (
        <RadarCard icon={<MapPin className="size-4" />} title="Zone scolaire à renseigner" detail="Renseigne la zone pour voir les vacances.">
          <Button variant="outline" size="sm" asChild>
            <Link to="/club">Renseigner</Link>
          </Button>
        </RadarCard>
      ) : null}

      {/* Plannings EN COURS d'abord : l'action la plus pressante, jamais cachée. */}
      {/* Le gating (#5/F3) bloque le DÉMARRAGE d'un secondaire, pas la REPRISE d'un
          travail déjà commencé (versions). Une carte « en cours » à ZÉRO version
          (créée mais pas générée) est bloquée tant que la saison n'est pas validée ;
          une carte avec versions reste reprenable même après une réouverture. */}
      {inProgressEntries.map((e) => {
        const locked = !socleValidated && !startedEntryIds.has(e.id);
        return (
          <RadarCard key={`wip-${e.id}`} icon={<Pencil className="size-4 text-accent" />} title={e.title} detail="Planning en cours — à finaliser">
            <Button variant="outline" size="sm" disabled={locked} title={locked ? lockTitle : undefined} onClick={() => adapt(e.id)}>
              Reprendre
            </Button>
          </RadarCard>
        );
      })}

      {/* Semaine dont la MÈRE est sortie de la fenêtre radar : sa seule surface. */}
      {orphanWeekChildren.map((e) => {
        const locked = !socleValidated && !startedEntryIds.has(e.id);
        return (
          <RadarCard key={`orphan-${e.id}`} icon={<Pencil className="size-4 text-accent" />} title={e.title} detail="Planning de semaine à finaliser">
            <Button variant="outline" size="sm" disabled={locked} title={locked ? lockTitle : undefined} onClick={() => adapt(e.id)}>
              Reprendre
            </Button>
          </RadarCard>
        );
      })}

      {/* Couverture d'une période DÉCOUPÉE (P2-5 E1) : l'état par semaine, d'un
          coup d'œil — validée → Voir, en cours/à faire → Reprendre, MANQUANTE
          (décochée, échec partiel) → « + créer » (le dead-end de la revue #262).
          Visible tant qu'une semaine n'est pas couverte. */}
      {splitMothers.map((m) => {
        const slots = motherWeekSlots(m);
        const covered = slots.filter(({ child }) => null !== child && activeByEntry.has(child.id)).length;
        const impactCount = splitImpactCountByEntry.get(m.id) ?? 0;
        const coverageDetail = `${covered}/${slots.length} semaine${slots.length > 1 ? "s" : ""} couverte${covered > 1 ? "s" : ""}${impactCount > 0 ? ` · ${impactCount} séance${impactCount > 1 ? "s" : ""} touchée${impactCount > 1 ? "s" : ""}` : ""}`;
        return (
          <RadarCard key={`split-${m.id}`} icon={<CalendarClock className="size-4 text-accent" />} title={m.title} detail={coverageDetail}>
            {slots.map(({ week, child }) => {
              if (null === child) {
                return (
                  <Button
                    key={`new-${week.monday}`}
                    variant="outline"
                    size="sm"
                    disabled={createWeekChildren.isPending || !socleValidated}
                    title={lockTitle}
                    onClick={() => createOneWeek(m, week)}
                  >
                    {`+ sem. du ${frDateShort(week.startDate)}`}
                  </Button>
                );
              }
              const activeId = activeByEntry.get(child.id) ?? null;
              const wip = startedEntryIds.has(child.id);
              // Gating uniquement sur une semaine À CRÉER/DÉMARRER (« à faire ») :
              // « Voir » (validée) et « en cours » (reprise) restent actifs.
              const chipLocked = null === activeId && !wip && !socleValidated;
              return (
                <Button
                  key={child.id}
                  variant={null !== activeId ? "ghost" : "outline"}
                  size="sm"
                  disabled={chipLocked}
                  title={chipLocked ? lockTitle : undefined}
                  onClick={() => (null !== activeId ? viewOverlay(activeId) : adapt(child.id))}
                >
                  {`sem. du ${frDateShort(child.startDate)} ${null !== activeId ? "✅" : wip ? "· en cours" : "· à faire"}`}
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
              <Button variant="outline" size="sm" disabled={!socleValidated} title={lockTitle} onClick={() => requestAdapt(entry)}>
                Adapter
              </Button>
            ) : (
              <Button
                variant="outline"
                size="sm"
                disabled={createHoliday.isPending || null === seasonClamp(h) || !socleValidated}
                title={lockTitle}
                onClick={() => {
                  const range = seasonClamp(h);
                  if (null === range) {
                    return;
                  }
                  const pending = { schoolHolidayId: h.id, label: h.label, startDate: range.startDate, endDate: range.endDate };
                  // Vacances couvrant PLUSIEURS semaines → picker SANS création (la
                  // mère naît à la confirmation) ; 1 semaine → création + wizard direct.
                  const multiWeek = null !== workingSeason && periodAdjustWeeks(range.startDate, range.endDate, workingSeason, "holiday").length > 1;
                  if (multiWeek) {
                    openPendingPicker(pending);
                    return;
                  }
                  createHoliday.mutate(pending, { onSuccess: (created) => adapt(created.id) });
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
            return <ClosureRadarItem key={e.id} entry={e} activeScheduleId={activeId} inProgress={startedEntryIds.has(e.id)} seasonUnvalidated={!socleValidated} adaptTitle={lockTitle} onAdapt={() => requestAdapt(e)} onView={() => null !== activeId && viewOverlay(activeId)} />;
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

      {/* Vacance PAS encore matérialisée : picker sur une mère synthétique (aucune
          création tant que non confirmé — annuler ne laisse aucun fantôme). */}
      {null !== pendingHoliday && null !== workingSeason ? (
        <WeekPickerDialog
          title={pendingHoliday.label}
          startDate={pendingHoliday.startDate}
          endDate={pendingHoliday.endDate}
          weeks={periodAdjustWeeks(pendingHoliday.startDate, pendingHoliday.endDate, workingSeason, "holiday")}
          busy={createHoliday.isPending || createWeekChildren.isPending}
          onPickWeeks={(weeks) => pickWeeksPending(pendingHoliday, weeks)}
          onAdaptWhole={() => adaptWholePending(pendingHoliday)}
          onClose={() => setPendingHoliday(null)}
        />
      ) : null}

      {null !== pickerFor && null !== workingSeason ? (
        <WeekPickerDialog
          title={pickerFor.title}
          startDate={pickerFor.startDate}
          endDate={pickerFor.endDate}
          weeks={periodAdjustWeeks(pickerFor.startDate, pickerFor.endDate, workingSeason, pickerFor.periodType)}
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

function ClosureRadarItem({ entry, activeScheduleId, inProgress = false, seasonUnvalidated = false, adaptTitle, onAdapt, onView }: { entry: CalendarEntry; activeScheduleId: string | null; inProgress?: boolean; seasonUnvalidated?: boolean; adaptTitle?: string; onAdapt: () => void; onView: () => void }) {
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
          {/* Overlay validé → « Ajuster » retouche un secondaire existant (pas une
              création) : jamais bloqué par le gating saison. */}
          <Button variant="ghost" size="sm" onClick={onAdapt}>
            Ajuster
          </Button>
        </>
      ) : (
        // Gating seulement sur une fermeture À DÉMARRER (« Adapter ») ; « Reprendre »
        // (travail en cours) reste actif même si la saison est rouverte.
        <Button variant="outline" size="sm" disabled={!inProgress && seasonUnvalidated} title={!inProgress ? adaptTitle : undefined} onClick={onAdapt}>
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
      {/* Empilées à droite (pas en ligne) : les chips par semaine d'une carte de
          couverture débordaient de l'encart en ligne (retour fondateur 2026-07-24). */}
      {children ? <div className="mt-2 flex flex-col items-end gap-1">{children}</div> : null}
    </div>
  );
}
