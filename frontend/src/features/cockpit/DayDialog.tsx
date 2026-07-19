import { CalendarOff, Trash2 } from "lucide-react";
import { type ReactNode, useState } from "react";
import { useNavigate } from "react-router-dom";

import { useSchedules, useVenues } from "@/features/planning/queries";
import { usePlanningStore } from "@/features/planning/store";
import { useWizardStore } from "@/features/wizard/store";
import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { Modal } from "@/shared/components/ui/modal";
import { toast } from "@/shared/stores/toastStore";

import type { CalendarEntry, PublicHoliday, SchoolHoliday } from "./api";
import { useWorkingSeason } from "@/features/auth/queries";

import { clampRangeToSeason, frDateShort, periodAdjustWeeks, todayISO, weeksCovering } from "./lib/date";
import { seasonLockTitle, useSocleValidated } from "./lib/socle";
import { useWeekAdapt } from "./lib/useWeekAdapt";
import { entryIcon, entryLabel, holidayIcon, isHolidayAnchor } from "./lib/markers";
import { useCalendarEntries, useCreateCutoff, useCreateEvent, useCreateVenueClosure, useDeleteEntry, useSchedulePlanForEntry, useSchedulePlans } from "./queries";
import { WeekPickerDialog } from "./WeekPickerDialog";

type Mode = "list" | "event" | "closure" | "cutoff";

interface DayDialogProps {
  iso: string;
  entries: CalendarEntry[];
  /** School-holiday window covering this day (amber), if any — enables the "Adapter" entry point. */
  holiday?: SchoolHoliday;
  /** Public holiday (jour férié) on this day, if any — shown as read-only info. */
  publicHoliday?: PublicHoliday;
  onClose: () => void;
}

/** Lightweight day dialog (annotation = modal, spec §5bis): lists the day's entries and creates an event / venue closure. */
export function DayDialog({ iso, entries, holiday, publicHoliday, onClose }: DayDialogProps) {
  const [mode, setMode] = useState<Mode>("list");

  return (
    <Modal label={`Jour ${iso}`} title={formatFrDate(iso)} onClose={onClose}>
      <div className="mt-4">
        {mode === "list" ? <DayList entries={entries} holiday={holiday} publicHoliday={publicHoliday} onCreate={setMode} onClose={onClose} /> : null}
        {mode === "event" ? <EventForm iso={iso} onBack={() => setMode("list")} onDone={onClose} /> : null}
        {mode === "closure" ? <ClosureForm iso={iso} onBack={() => setMode("list")} onDone={onClose} /> : null}
        {mode === "cutoff" ? <CutoffForm iso={iso} onBack={() => setMode("list")} onDone={onClose} /> : null}
      </div>
    </Modal>
  );
}

function DayList({ entries, holiday, publicHoliday, onCreate, onClose }: { entries: CalendarEntry[]; holiday?: SchoolHoliday; publicHoliday?: PublicHoliday; onCreate: (m: Mode) => void; onClose: () => void }) {
  const deleteEntry = useDeleteEntry();
  const schedulesQuery = useSchedules();
  const [toDelete, setToDelete] = useState<CalendarEntry | null>(null);
  // Plannings couvrant ce jour (retour fondateur 2026-07-19) : une entrée qui PORTE
  // un plan (fermeture / semaine de vacances) devient accessible en AJUSTER (plan
  // pas encore validé) / Consulter (validé) — plus seulement supprimable. Le plan
  // se dérive de allPlans par calendarEntryId ; « en cours » = pas de chosenScheduleId.
  const navigate = useNavigate();
  const startPeriodMode = useWizardStore((s) => s.startPeriodMode);
  const setSelectedScheduleId = usePlanningStore((s) => s.setSelectedScheduleId);
  const { data: allPlans } = useSchedulePlans();
  const socleValidated = useSocleValidated();
  const lockTitle = seasonLockTitle(socleValidated);
  // Index construits UNE fois (revue B1 F5) : plan par entrée + plans portant une
  // version — plutôt qu'un .find/.some par ligne rendue.
  const planByEntry = new Map((allPlans ?? []).filter((p) => null !== p.calendarEntryId).map((p) => [p.calendarEntryId as string, p]));
  const plansWithVersions = new Set((schedulesQuery.data ?? []).map((s) => s.schedulePlanId));
  const adjust = (entryId: string) => {
    startPeriodMode(entryId);
    onClose();
    navigate("/wizard");
  };
  const consult = (scheduleId: string) => {
    setSelectedScheduleId(scheduleId);
    onClose();
    navigate("/planning");
  };
  // Décision fondateur (2026-07-18) : supprimer une période supprime son PLAN, donc TOUTES
  // ses versions liées — le gestionnaire doit en valider la PORTÉE. On avertit fort dès que
  // le plan porte ≥ 1 version (brouillon inclus : la cascade les emporte), pas seulement une
  // version validée. « Porte des versions » se dérive du plan de la période (schedulePlanId),
  // plus d'un pointeur sur l'entrée (lot D-b). Un plan vide (aucune version) ne perd rien → bénin.
  const planQuery = useSchedulePlanForEntry(toDelete?.id ?? null);
  const toDeletePlanId = planQuery.data?.id ?? null;
  // Restreint aux types OVERLAYABLES : seuls closure/holiday portent un plan (inv. 9) —
  // cutoff/mutualisation/custom et les événements n'en ont jamais, donc aucune cascade à
  // annoncer (les avertir serait un faux positif alarmant).
  const overlayCapable = "closure" === toDelete?.periodType || "holiday" === toDelete?.periodType;
  // Fail-closed sur l'absence de DONNÉE (pas le statut) : le dialogue s'ouvre avant que le
  // plan/les versions répondent. On n'affiche le message bénin que si on A la donnée des deux
  // requêtes — sinon un delete confirmé pendant le chargement/1er échec afficherait « rien à
  // perdre » puis emporterait le plan et ses versions. Clé sur `data`, PAS sur isSuccess :
  // TanStack bascule en error sur un refetch d'arrière-plan tout en gardant la donnée périmée
  // — s'y fier sur-avertirait un plan vide après un simple blip. (usePeriodAnchor n'expose pas
  // cet état → lecture directe des deux requêtes ici.)
  const versionsResolved = undefined !== planQuery.data && undefined !== schedulesQuery.data;
  const toDeleteHasVersions = overlayCapable && (!versionsResolved || (null !== toDeletePlanId && (schedulesQuery.data ?? []).some((s) => s.schedulePlanId === toDeletePlanId)));

  const confirmDelete = () => {
    if (!toDelete) return;
    deleteEntry.mutate(toDelete.id, { onSuccess: () => toast.success("Entrée supprimée") });
    setToDelete(null);
  };

  // La mère vacances est un ancrage invisible (la vacance scolaire EST l'événement,
  // portée par HolidayBlock) : jamais listée comme entrée supprimable. Ses
  // semaines-enfants et les autres entrées restent supprimables.
  const deletable = entries.filter((e) => !isHolidayAnchor(e));

  return (
    <div className="space-y-4">
      {publicHoliday ? (
        <p className="flex items-center gap-2 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm">
          <CalendarOff className="size-4 shrink-0 text-destructive" />
          <span>
            <span className="font-medium">Jour férié</span> — {publicHoliday.label}
          </span>
        </p>
      ) : null}

      {holiday ? <HolidayBlock holiday={holiday} entries={entries} onClose={onClose} /> : null}

      {deletable.length > 0 ? (
        <ul className="space-y-2">
          {deletable.map((entry) => {
            // Entrée porteuse d'un plan (fermeture / semaine de vacances) → AJUSTER
            // (pas de version validée) ou Consulter (validé). Les entrées sans plan
            // (événement, coupure) n'ont pas de CTA planning.
            const plan = planByEntry.get(entry.id) ?? null;
            const chosen = plan?.chosenScheduleId ?? null;
            const planHasVersions = null !== plan && plansWithVersions.has(plan.id);
            // Gating (#5/F3) : AJUSTER une fermeture PAS ENCORE commencée (0 version)
            // reste bloqué tant que la saison n'est pas validée ; reprendre un travail
            // déjà commencé (versions) ne l'est pas.
            const adjustLocked = !socleValidated && !planHasVersions;
            return (
              <li key={entry.id} className="flex items-center justify-between gap-2 rounded-md border border-border px-3 py-2 text-sm">
                <span className="flex min-w-0 items-center gap-2">
                  {/* Same emoji marker as the month calendar (decorative → aria-hidden;
                      the title/fallback text carries the meaning). */}
                  <span aria-hidden className="text-base leading-none">{entryIcon(entry)}</span>
                  <span className="truncate">{entry.title || entryLabel(entry)}</span>
                </span>
                <span className="flex shrink-0 items-center gap-1">
                  {null !== plan ? (
                    null !== chosen ? (
                      <Button variant="ghost" size="sm" onClick={() => consult(chosen)}>
                        Consulter
                      </Button>
                    ) : (
                      <Button variant="outline" size="sm" disabled={adjustLocked} title={adjustLocked ? lockTitle : undefined} onClick={() => adjust(entry.id)}>
                        Ajuster
                      </Button>
                    )
                  ) : null}
                  <button
                    type="button"
                    aria-label={`Supprimer ${entry.title}`}
                    className="rounded p-1 text-muted-foreground hover:text-destructive"
                    disabled={deleteEntry.isPending}
                    onClick={() => setToDelete(entry)}
                  >
                    <Trash2 className="size-4" />
                  </button>
                </span>
              </li>
            );
          })}
        </ul>
      ) : null}

      {/* « Rien ce jour-là » NE s'affiche PAS sous un jour de vacances : la mère
          holiday est masquée de la liste supprimable (ancrage invisible), donc
          `deletable` peut être vide alors que le bloc vacances ci-dessus tient la
          journée — dire « la semaine type tourne normalement » se contredirait. */}
      {deletable.length === 0 && !holiday ? (
        <p className="text-sm text-muted-foreground">Rien ce jour-là — la semaine type tourne normalement.</p>
      ) : null}

      <ConfirmDialog
        open={toDelete !== null}
        title={`Supprimer « ${toDelete?.title ?? ""} » ?`}
        description={
          toDeleteHasVersions
            ? "Supprimer cette période supprime aussi son plan et toutes ses versions générées. À refaire si besoin."
            : "Cette entrée sera retirée du calendrier."
        }
        confirmLabel="Supprimer"
        destructive={toDeleteHasVersions}
        onConfirm={confirmDelete}
        onCancel={() => setToDelete(null)}
      />

      <div className="grid grid-cols-1 gap-2">
        <Button variant="outline" onClick={() => onCreate("event")}>
          Événement
        </Button>
        <Button variant="outline" onClick={() => onCreate("closure")}>
          Signaler une indisponibilité
        </Button>
        <Button variant="outline" onClick={() => onCreate("cutoff")}>
          Coupure (pas d'entraînement)
        </Button>
        <Button variant="ghost" disabled title="Période générique (custom) — à venir. Utilise « Signaler une indisponibilité » ou le radar vacances.">
          Créer une période…
        </Button>
      </div>

      <div className="flex justify-end">
        <Button variant="ghost" onClick={onClose}>
          Fermer
        </Button>
      </div>
    </div>
  );
}

/**
 * School-holiday info + "Adapter" entry point (same action as the radar, but from
 * the day the manager clicked). The holiday is materialised as a period entry
 * (matched by schoolHolidayId): none yet → create it then open the wizard in
 * period mode; already there → "Adapter" (wizard) or "Voir le planning" if its
 * overlay is generated.
 */
function HolidayBlock({ holiday, entries, onClose }: { holiday: SchoolHoliday; entries: CalendarEntry[]; onClose: () => void }) {
  const navigate = useNavigate();
  const startPeriodMode = useWizardStore((s) => s.startPeriodMode);
  const setSelectedScheduleId = usePlanningStore((s) => s.setSelectedScheduleId);
  // Gating (#5) : tant que le plan de la SAISON n'est pas validé (chosenScheduleId),
  // on ne peut pas créer de planning secondaire — les ajustements sont désactivés.
  const socleValidated = useSocleValidated();
  const lockTitle = seasonLockTitle(socleValidated);
  // Clamp saison (même règle que le radar) : une période vit dans sa saison ;
  // les vacances à cheval (été) ne créent que leur part en-saison. null = vacance
  // entièrement hors saison, ou saison pas encore chargée → pas de création.
  const workingSeason = useWorkingSeason();
  const clamped = null === workingSeason ? null : clampRangeToSeason(holiday.startDate, holiday.endDate, workingSeason);

  const entry = entries.find((e) => e.schoolHolidayId === holiday.id) ?? null;
  // ADR-0002 lot D-b : « overlay généré » = plan validé (chosenScheduleId), dérivé du plan.
  const plan = useSchedulePlanForEntry(entry?.id ?? null);
  const activeId = plan.data?.chosenScheduleId ?? null;

  // P2-5 E1 : les SEMAINES enfants de cette période (fenêtrées sur la mère —
  // elles y vivent par construction). Le DayDialog ne reçoit que les entrées du
  // JOUR : requête ciblée, cache partagé avec le cockpit.
  const childrenQuery = useCalendarEntries(entry?.startDate ?? "", entry?.endDate ?? "", null !== entry);
  // Résolu ? — gate du picker : « 0 enfant » pendant le chargement n'est PAS
  // « pas découpée » (fail-open → 422 en série, revue #262 round 2).
  const childrenResolved = null === entry || undefined !== childrenQuery.data;
  const weekChildren = (childrenQuery.data ?? []).filter((e) => e.parentEntryId === (entry?.id ?? "")).sort((a, b) => a.startDate.localeCompare(b.startDate));
  const { data: allPlans } = useSchedulePlans();
  const chosenOfChild = (childId: string): string | null => (allPlans ?? []).find((p) => p.calendarEntryId === childId)?.chosenScheduleId ?? null;
  // Générée « d'un bloc » ? (versions sur le plan de la mère → pas de découpage.)
  const schedulesQuery = useSchedules();
  const schedulesResolved = undefined !== schedulesQuery.data;
  // Plan résolu ? blockGenerated est faux tant que plan.data est undefined
  // (chargement) — offrir le picker alors ferait 422 chaque semaine sur une mère
  // déjà générée en bloc (revue #262 round 3). entry null (aucune période encore)
  // = résolu par définition : la query est désactivée.
  const planResolved = null === entry || undefined !== plan.data;
  const blockGenerated = undefined !== plan.data && null !== plan.data && (schedulesQuery.data ?? []).some((s) => s.schedulePlanId === plan.data?.id);
  const adapt = (entryId: string) => {
    startPeriodMode(entryId);
    onClose();
    navigate("/wizard");
  };
  const viewOverlay = (overlayScheduleId: string) => {
    setSelectedScheduleId(overlayScheduleId);
    onClose();
    navigate("/planning");
  };
  // Flux de découpage partagé avec le radar ; ici, plusieurs semaines créées →
  // referme le DayDialog (le radar reprend le relais via ses cartes). Le chemin
  // `pending` matérialise la mère vacances SEULEMENT à la confirmation du picker.
  const { pickerFor, setPickerFor, pendingHoliday, setPendingHoliday, openPendingPicker, createWeekChildren, createHoliday, pickWeeks, pickWeeksPending, adaptWholePending, createOneWeek } = useWeekAdapt(adapt);
  // Même règle que le radar : période couvrant PLUSIEURS semaines calendaires →
  // choix des semaines (7 jours à cheval jeu→mer = 2 semaines, l'exemple
  // fondateur) ; sinon direct. Données pas résolues (schedules OU enfants) →
  // direct aussi (fail-open du picker = 422 en série, revue #262 round 2).
  const requestAdapt = (target: CalendarEntry) => {
    const multiWeek = null !== workingSeason && periodAdjustWeeks(target.startDate, target.endDate, workingSeason, "holiday").length > 1;
    if (multiWeek && childrenResolved && 0 === weekChildren.length && schedulesResolved && planResolved && !blockGenerated) {
      setPickerFor(target);
      return;
    }
    adapt(target.id);
  };

  return (
    <div className="space-y-2 rounded-md border border-amber-400/50 bg-amber-400/10 px-3 py-2">
      <p className="flex items-center gap-2 text-sm">
        {/* Same season emoji as the calendar (🎄/🎃/…) — decorative, the text names it. */}
        <span aria-hidden className="text-base leading-none">{holidayIcon(holiday)}</span>
        <span>
          <span className="font-medium">Vacances</span> — {holiday.label}
        </span>
      </p>
      {/* Toutes les vacances sont adaptables, été inclus (planning de reprise —
          retour fondateur 2026-07-18, P2-5 E2 : l'exclusion `ete` est levée). */}
      {null !== entry && weekChildren.length > 0 ? (
        // P2-5 E1 — période DÉCOUPÉE : la couverture par semaine, même lecture
        // que la carte radar (validée → Voir, sinon → Reprendre, MANQUANTE → +
        // créer — une semaine décochée reste planifiable, revue #262).
        <div className="flex flex-wrap justify-end gap-1">
          {(null === workingSeason
            ? weekChildren.map((c) => ({ week: { startDate: c.startDate, endDate: c.endDate, monday: c.startDate }, child: c as CalendarEntry | null }))
            : (() => {
              // Revue C F1 : toutes les semaines calendaires ; on garde celle qui porte
              // un enfant EXISTANT (toujours visible) OU qui est OFFERTE à la création
              // (periodAdjustWeeks écarte la semaine partielle d'une vacance Ven/Sam/Dim).
              const offeredMondays = new Set(periodAdjustWeeks(entry.startDate, entry.endDate, workingSeason, "holiday").map((w) => w.monday));
              return weeksCovering(entry.startDate, entry.endDate, workingSeason)
                .map((week) => ({ week, child: (weekChildren.find((c) => c.startDate <= week.endDate && c.endDate >= week.startDate) ?? null) as CalendarEntry | null }))
                .filter(({ week, child }) => null !== child || offeredMondays.has(week.monday));
            })()
          ).map(({ week, child }) => {
            if (null === child) {
              return (
                <Button
                  key={`new-${week.monday}`}
                  variant="outline"
                  size="sm"
                  disabled={createWeekChildren.isPending || !socleValidated}
                  title={lockTitle}
                  onClick={() => createOneWeek(entry, week)}
                >
                  {`+ sem. du ${frDateShort(week.startDate)}`}
                </Button>
              );
            }
            const chosen = chosenOfChild(child.id);
            // « Voir » (semaine validée) reste actif — lecture seule, sans gating.
            return (
              <Button
                key={child.id}
                variant={null !== chosen ? "ghost" : "outline"}
                size="sm"
                disabled={null === chosen && !socleValidated}
                title={null === chosen ? lockTitle : undefined}
                onClick={() => (null !== chosen ? viewOverlay(chosen) : adapt(child.id))}
              >
                {`sem. du ${frDateShort(child.startDate)} ${null !== chosen ? "✅" : "· à faire"}`}
              </Button>
            );
          })}
        </div>
      ) : null !== activeId ? (
        <div className="flex justify-end">
          <Button variant="outline" size="sm" onClick={() => viewOverlay(activeId)}>
            Voir le planning
          </Button>
        </div>
      ) : entry ? (
        <div className="flex justify-end">
          <Button variant="outline" size="sm" disabled={!socleValidated} title={lockTitle} onClick={() => requestAdapt(entry)}>
            Adapter
          </Button>
        </div>
      ) : null !== workingSeason && null === clamped ? (
        // Fenêtre entièrement disjointe de la saison (fait VÉRIFIÉ — la saison est
        // chargée) : un bouton mort sans explication serait pire que l'ancien
        // message (revue #260 round 2). Saison encore en vol → bouton désactivé
        // bref, ci-dessous.
        <p className="text-xs text-muted-foreground">Hors de la saison en cours — rien à adapter.</p>
      ) : (
        <div className="flex justify-end">
          <Button
            variant="outline"
            size="sm"
            disabled={createHoliday.isPending || null === clamped || !socleValidated}
            title={lockTitle}
            onClick={async () => {
              if (null === clamped) {
                return;
              }
              const pending = { schoolHolidayId: holiday.id, label: holiday.label, startDate: clamped.startDate, endDate: clamped.endDate };
              // Vacances couvrant PLUSIEURS semaines → choix des semaines SANS rien
              // créer (la mère naît à la confirmation — retour fondateur : annuler
              // ne doit laisser aucun événement fantôme).
              const multiWeek = null !== workingSeason && periodAdjustWeeks(clamped.startDate, clamped.endDate, workingSeason, "holiday").length > 1;
              if (multiWeek) {
                openPendingPicker(pending);
                return;
              }
              // 1 seule semaine (pas de picker, donc pas de fantôme possible) :
              // création + wizard direct. mutateAsync : la navigation part même si
              // la modale se referme pendant le POST. Erreur → filet global.
              try {
                const created = await createHoliday.mutateAsync(pending);
                adapt(created.id);
              } catch {
                /* surfaced by the global mutation-cache net */
              }
            }}
          >
            Adapter
          </Button>
        </div>
      )}

      {/* Vacance PAS encore matérialisée : picker sur une mère synthétique (aucune
          création tant que non confirmé). */}
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
          weeks={periodAdjustWeeks(pickerFor.startDate, pickerFor.endDate, workingSeason, "holiday")}
          busy={createWeekChildren.isPending}
          onPickWeeks={(weeks) => pickWeeks(pickerFor, weeks)}
          onAdaptWhole={() => {
            setPickerFor(null);
            adapt(pickerFor.id);
          }}
          onClose={() => setPickerFor(null)}
        />
      ) : null}
    </div>
  );
}

function FormShell({ children, onBack }: { children: ReactNode; onBack: () => void }) {
  return (
    <div className="space-y-3">
      {children}
      <button type="button" className="text-xs text-muted-foreground hover:text-foreground" onClick={onBack}>
        ← Retour
      </button>
    </div>
  );
}

const fieldClass = "w-full rounded-md border border-input bg-background px-3 py-2 text-sm";

/**
 * Shared "Du … Jusqu'au …" range of the three creation forms (event / closure /
 * cutoff). The clicked day is only the DEFAULT start — both ends are editable
 * (start ≥ today, end ≥ start). Changing the start bumps a now-earlier end so the
 * range never inverts.
 */
function DateRangeFields({ startDate, endDate, onStart, onEnd }: { startDate: string; endDate: string; onStart: (value: string) => void; onEnd: (value: string) => void }) {
  const today = todayISO();
  return (
    <div className="grid grid-cols-2 gap-2">
      <label className="block text-xs text-muted-foreground">
        Du
        <input type="date" className={`${fieldClass} mt-1`} value={startDate} min={today} onChange={(e) => onStart(e.target.value)} />
      </label>
      <label className="block text-xs text-muted-foreground">
        Jusqu'au
        <input type="date" className={`${fieldClass} mt-1`} value={endDate} min={startDate} onChange={(e) => onEnd(e.target.value)} />
      </label>
    </div>
  );
}

/**
 * Editable [start, end] range shared by the three creation forms. `today` is
 * frozen at mount (not re-read each render → a dialog left open past midnight
 * stays submittable). Moving the start past the end bumps the end so the range
 * never inverts. `valid` = today ≤ start ≤ end.
 */
function useDateRange(iso: string) {
  const [today] = useState(todayISO);
  const [startDate, setStartDate] = useState(iso);
  const [endDate, setEndDate] = useState(iso);
  const setStart = (value: string) => {
    setStartDate(value);
    setEndDate((prev) => (prev < value ? value : prev));
  };
  return { startDate, endDate, setStart, setEnd: setEndDate, valid: startDate >= today && endDate >= startDate };
}

function EventForm({ iso, onBack, onDone }: { iso: string; onBack: () => void; onDone: () => void }) {
  const [title, setTitle] = useState("");
  const { startDate, endDate, setStart, setEnd, valid } = useDateRange(iso);
  const [isDisruptive, setDisruptive] = useState(false);
  const createEvent = useCreateEvent();

  const submit = () => {
    if (title.trim() === "" || !valid) return;
    createEvent.mutate(
      { title: title.trim(), startDate, endDate, isDisruptive },
      { onSuccess: () => { toast.success("Événement ajouté"); onDone(); } },
    );
  };

  return (
    <FormShell onBack={onBack}>
      {/* eslint-disable-next-line jsx-a11y/no-autofocus -- inside a Modal: focusing the first field on step change is intentional, better than the neutral panel */}
      <input className={fieldClass} aria-label="Titre de l'événement" placeholder="Titre (AG, tournoi…)" value={title} onChange={(e) => setTitle(e.target.value)} autoFocus />
      <DateRangeFields startDate={startDate} endDate={endDate} onStart={setStart} onEnd={setEnd} />
      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" checked={isDisruptive} onChange={(e) => setDisruptive(e.target.checked)} />
        Perturbant (pas d'entraînement ce jour)
      </label>
      <Button className="w-full" onClick={submit} disabled={createEvent.isPending || title.trim() === "" || !valid}>
        Enregistrer
      </Button>
    </FormShell>
  );
}

function ClosureForm({ iso, onBack, onDone }: { iso: string; onBack: () => void; onDone: () => void }) {
  const { data: venues } = useVenues();
  const [title, setTitle] = useState("");
  const { startDate, endDate, setStart, setEnd, valid } = useDateRange(iso);
  const [venueId, setVenueId] = useState("");
  const createClosure = useCreateVenueClosure();

  const submit = () => {
    if (venueId === "" || !valid) return;
    const venueName = venues?.find((v) => v.id === venueId)?.name ?? "Gymnase";
    // Structured "gymnase — raison" so the calendar tooltip names both the venue
    // and why it's closed. Don't prefix when the typed reason already mentions the
    // venue (avoids "Gymnase A — Gymnase A …"); default reason to "fermé" when
    // blank; cap to the Constraint.name column (180) so a long reason can't make
    // the paired FACILITY constraint fail to persist.
    const reason = title.trim();
    const base = reason === "" ? `${venueName} — fermé` : reason.includes(venueName) ? reason : `${venueName} — ${reason}`;
    createClosure.mutate(
      { title: base.slice(0, 180), startDate, endDate, venueId },
      // Errors are toasted by the hook itself (unmount-safe rollback message).
      { onSuccess: () => { toast.success("Indisponibilité enregistrée"); onDone(); } },
    );
  };

  return (
    <FormShell onBack={onBack}>
      {/* eslint-disable-next-line jsx-a11y/no-autofocus -- inside a Modal: focusing the first field on step change is intentional */}
      <select className={fieldClass} aria-label="Gymnase indisponible" value={venueId} onChange={(e) => setVenueId(e.target.value)} autoFocus>
        <option value="">Gymnase indisponible…</option>
        {(venues ?? []).map((v) => (
          <option key={v.id} value={v.id}>
            {v.name}
          </option>
        ))}
      </select>
      <input className={fieldClass} aria-label="Intitulé de l'indisponibilité (optionnel)" placeholder="Intitulé (optionnel)" maxLength={140} value={title} onChange={(e) => setTitle(e.target.value)} />
      <DateRangeFields startDate={startDate} endDate={endDate} onStart={setStart} onEnd={setEnd} />
      <Button className="w-full" onClick={submit} disabled={createClosure.isPending || venueId === "" || !valid}>
        Enregistrer
      </Button>
    </FormShell>
  );
}

/** A cutoff is a bare period ("no training on the window") — no venue, no constraint, no overlay to generate. */
function CutoffForm({ iso, onBack, onDone }: { iso: string; onBack: () => void; onDone: () => void }) {
  const [title, setTitle] = useState("");
  const { startDate, endDate, setStart, setEnd, valid } = useDateRange(iso);
  const createCutoff = useCreateCutoff();

  const submit = () => {
    if (!valid) return;
    createCutoff.mutate(
      { title: title.trim() === "" ? "Coupure" : title.trim(), startDate, endDate },
      { onSuccess: () => { toast.success("Coupure enregistrée"); onDone(); } },
    );
  };

  return (
    <FormShell onBack={onBack}>
      {/* eslint-disable-next-line jsx-a11y/no-autofocus -- inside a Modal: focusing the first field on step change is intentional */}
      <input className={fieldClass} aria-label="Intitulé de la coupure (optionnel)" placeholder="Intitulé (optionnel, ex. Coupure de Noël)" value={title} onChange={(e) => setTitle(e.target.value)} autoFocus />
      <DateRangeFields startDate={startDate} endDate={endDate} onStart={setStart} onEnd={setEnd} />
      <p className="text-xs text-muted-foreground">Rappel affiché au calendrier (🛑) et au radar — le planning de base reste inchangé, rien à générer.</p>
      <Button className="w-full" onClick={submit} disabled={createCutoff.isPending || !valid}>
        Enregistrer
      </Button>
    </FormShell>
  );
}

function formatFrDate(iso: string): string {
  const [y, m, d] = iso.split("-").map(Number);
  const date = new Date(y, m - 1, d);
  return date.toLocaleDateString("fr-FR", { weekday: "long", day: "numeric", month: "long", year: "numeric" });
}
