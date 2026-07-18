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
import { todayISO } from "./lib/date";
import { isAdaptableHoliday } from "./lib/holidays";
import { entryIcon, entryLabel, holidayIcon } from "./lib/markers";
import { useCreateCutoff, useCreateEvent, useCreateHolidayPeriod, useCreateVenueClosure, useDeleteEntry, useSchedulePlanForEntry } from "./queries";

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
  const { data: schedules = [] } = useSchedules();
  const [toDelete, setToDelete] = useState<CalendarEntry | null>(null);
  // Décision fondateur (2026-07-18) : supprimer une période supprime son PLAN, donc TOUTES
  // ses versions liées — le gestionnaire doit en valider la PORTÉE. On avertit fort dès que
  // le plan porte ≥ 1 version (brouillon inclus : la cascade les emporte), pas seulement une
  // version validée. « Porte des versions » se dérive du plan de la période (schedulePlanId),
  // plus d'un pointeur sur l'entrée (lot D-b). Un plan vide (aucune version) ne perd rien → bénin.
  const toDeletePlanId = useSchedulePlanForEntry(toDelete?.id ?? null).data?.id ?? null;
  const toDeleteHasVersions = null !== toDeletePlanId && schedules.some((s) => s.schedulePlanId === toDeletePlanId);

  const confirmDelete = () => {
    if (!toDelete) return;
    deleteEntry.mutate(toDelete.id, { onSuccess: () => toast.success("Entrée supprimée") });
    setToDelete(null);
  };

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

      {entries.length > 0 ? (
        <ul className="space-y-2">
          {entries.map((entry) => (
            <li key={entry.id} className="flex items-center justify-between rounded-md border border-border px-3 py-2 text-sm">
              <span className="flex items-center gap-2">
                {/* Same emoji marker as the month calendar (decorative → aria-hidden;
                    the title/fallback text carries the meaning). */}
                <span aria-hidden className="text-base leading-none">{entryIcon(entry)}</span>
                <span>{entry.title || entryLabel(entry)}</span>
              </span>
              <button
                type="button"
                aria-label={`Supprimer ${entry.title}`}
                className="rounded p-1 text-muted-foreground hover:text-destructive"
                disabled={deleteEntry.isPending}
                onClick={() => setToDelete(entry)}
              >
                <Trash2 className="size-4" />
              </button>
            </li>
          ))}
        </ul>
      ) : (
        <p className="text-sm text-muted-foreground">Rien ce jour-là — la semaine type tourne normalement.</p>
      )}

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
  const createHoliday = useCreateHolidayPeriod();

  const entry = entries.find((e) => e.schoolHolidayId === holiday.id) ?? null;
  // ADR-0002 lot D-b : « overlay généré » = plan validé (chosenScheduleId), dérivé du plan.
  const plan = useSchedulePlanForEntry(entry?.id ?? null);
  const activeId = plan.data?.chosenScheduleId ?? null;
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

  return (
    <div className="space-y-2 rounded-md border border-amber-400/50 bg-amber-400/10 px-3 py-2">
      <p className="flex items-center gap-2 text-sm">
        {/* Same season emoji as the calendar (🎄/🎃/…) — decorative, the text names it. */}
        <span aria-hidden className="text-base leading-none">{holidayIcon(holiday)}</span>
        <span>
          <span className="font-medium">Vacances</span> — {holiday.label}
        </span>
      </p>
      {/* An existing overlay is always viewable (even for summer — legacy data).
          "Adapter" (create/replay) is only offered for adaptable holidays: summer
          (ete) is off-season, a schedule spans one season, so nothing to build —
          same rule as the radar (isAdaptableHoliday, single source of truth). */}
      {null !== activeId ? (
        <div className="flex justify-end">
          <Button variant="outline" size="sm" onClick={() => viewOverlay(activeId)}>
            Voir le planning
          </Button>
        </div>
      ) : !isAdaptableHoliday(holiday) ? (
        <p className="text-xs text-muted-foreground">Vacances d'été — hors saison, pas de planning à adapter.</p>
      ) : entry ? (
        <div className="flex justify-end">
          <Button variant="outline" size="sm" onClick={() => adapt(entry.id)}>
            Adapter
          </Button>
        </div>
      ) : (
        <div className="flex justify-end">
          <Button
            variant="outline"
            size="sm"
            disabled={createHoliday.isPending}
            onClick={async () => {
              // mutateAsync (not a mutate-scoped onSuccess): the navigation must
              // fire even if the modal is dismissed mid-POST — otherwise the period
              // IS created but the wizard never opens, leaving an orphan entry.
              // The 409/error is surfaced by the global mutation-cache net (queryClient.ts).
              try {
                const created = await createHoliday.mutateAsync({ schoolHolidayId: holiday.id, label: holiday.label, startDate: holiday.startDate, endDate: holiday.endDate });
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
