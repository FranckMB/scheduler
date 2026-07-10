import { CalendarX2, OctagonX, PartyPopper, Trash2 } from "lucide-react";
import { type ReactNode, useState } from "react";

import { useVenues } from "@/features/planning/queries";
import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { Modal } from "@/shared/components/ui/modal";
import { toast } from "@/shared/stores/toastStore";

import type { CalendarEntry } from "./api";
import { useCreateCutoff, useCreateEvent, useCreateVenueClosure, useDeleteEntry } from "./queries";

type Mode = "list" | "event" | "closure" | "cutoff";

interface DayDialogProps {
  iso: string;
  entries: CalendarEntry[];
  onClose: () => void;
}

/** Lightweight day dialog (annotation = modal, spec §5bis): lists the day's entries and creates an event / venue closure. */
export function DayDialog({ iso, entries, onClose }: DayDialogProps) {
  const [mode, setMode] = useState<Mode>("list");

  return (
    <Modal label={`Jour ${iso}`} title={formatFrDate(iso)} onClose={onClose}>
      <div className="mt-4">
        {mode === "list" ? <DayList entries={entries} onCreate={setMode} onClose={onClose} /> : null}
        {mode === "event" ? <EventForm iso={iso} onBack={() => setMode("list")} onDone={onClose} /> : null}
        {mode === "closure" ? <ClosureForm iso={iso} onBack={() => setMode("list")} onDone={onClose} /> : null}
        {mode === "cutoff" ? <CutoffForm iso={iso} onBack={() => setMode("list")} onDone={onClose} /> : null}
      </div>
    </Modal>
  );
}

function DayList({ entries, onCreate, onClose }: { entries: CalendarEntry[]; onCreate: (m: Mode) => void; onClose: () => void }) {
  const deleteEntry = useDeleteEntry();
  const [toDelete, setToDelete] = useState<CalendarEntry | null>(null);

  const confirmDelete = () => {
    if (!toDelete) return;
    deleteEntry.mutate(toDelete.id, { onSuccess: () => toast.success("Entrée supprimée") });
    setToDelete(null);
  };

  return (
    <div className="space-y-4">
      {entries.length > 0 ? (
        <ul className="space-y-2">
          {entries.map((entry) => (
            <li key={entry.id} className="flex items-center justify-between rounded-md border border-border px-3 py-2 text-sm">
              <span className="flex items-center gap-2">
                {entry.kind === "period" ? (
                  entry.periodType === "cutoff" ? (
                    <OctagonX className="size-4 text-destructive" />
                  ) : (
                    <CalendarX2 className="size-4 text-destructive" />
                  )
                ) : (
                  <PartyPopper className="size-4 text-accent" />
                )}
                <span>{entry.title}</span>
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
          toDelete?.overlayScheduleId
            ? "Cette période a un plan de période généré : il sera supprimé aussi (à refaire si besoin)."
            : "Cette entrée sera retirée du calendrier."
        }
        confirmLabel="Supprimer"
        destructive={Boolean(toDelete?.overlayScheduleId)}
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

/** Shared "Jusqu'au" end-date field of the three creation forms (event / closure / cutoff). */
function EndDateField({ iso, value, onChange }: { iso: string; value: string; onChange: (value: string) => void }) {
  return (
    <label className="block text-xs text-muted-foreground">
      Jusqu'au
      <input type="date" className={`${fieldClass} mt-1`} value={value} min={iso} onChange={(e) => onChange(e.target.value)} />
    </label>
  );
}

function EventForm({ iso, onBack, onDone }: { iso: string; onBack: () => void; onDone: () => void }) {
  const [title, setTitle] = useState("");
  const [endDate, setEndDate] = useState(iso);
  const [isDisruptive, setDisruptive] = useState(false);
  const createEvent = useCreateEvent();

  const validEnd = endDate >= iso;
  const submit = () => {
    if (title.trim() === "" || !validEnd) return;
    createEvent.mutate(
      { title: title.trim(), startDate: iso, endDate, isDisruptive },
      { onSuccess: () => { toast.success("Événement ajouté"); onDone(); } },
    );
  };

  return (
    <FormShell onBack={onBack}>
      {/* eslint-disable-next-line jsx-a11y/no-autofocus -- inside a Modal: focusing the first field on step change is intentional, better than the neutral panel */}
      <input className={fieldClass} aria-label="Titre de l'événement" placeholder="Titre (AG, tournoi…)" value={title} onChange={(e) => setTitle(e.target.value)} autoFocus />
      <EndDateField iso={iso} value={endDate} onChange={setEndDate} />
      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" checked={isDisruptive} onChange={(e) => setDisruptive(e.target.checked)} />
        Perturbant (pas d'entraînement ce jour)
      </label>
      <Button className="w-full" onClick={submit} disabled={createEvent.isPending || title.trim() === "" || !validEnd}>
        Enregistrer
      </Button>
    </FormShell>
  );
}

function ClosureForm({ iso, onBack, onDone }: { iso: string; onBack: () => void; onDone: () => void }) {
  const { data: venues } = useVenues();
  const [title, setTitle] = useState("");
  const [endDate, setEndDate] = useState(iso);
  const [venueId, setVenueId] = useState("");
  const createClosure = useCreateVenueClosure();

  const validEnd = endDate >= iso;
  const submit = () => {
    if (venueId === "" || !validEnd) return;
    const venueName = venues?.find((v) => v.id === venueId)?.name ?? "Gymnase";
    // Structured "gymnase — raison" so the calendar tooltip names both the venue
    // and why it's closed. Don't prefix when the typed reason already mentions the
    // venue (avoids "Gymnase A — Gymnase A …"); default reason to "fermé" when
    // blank; cap to the Constraint.name column (180) so a long reason can't make
    // the paired FACILITY constraint fail to persist.
    const reason = title.trim();
    const base = reason === "" ? `${venueName} — fermé` : reason.includes(venueName) ? reason : `${venueName} — ${reason}`;
    createClosure.mutate(
      { title: base.slice(0, 180), startDate: iso, endDate, venueId },
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
      <EndDateField iso={iso} value={endDate} onChange={setEndDate} />
      <Button className="w-full" onClick={submit} disabled={createClosure.isPending || venueId === "" || !validEnd}>
        Enregistrer
      </Button>
    </FormShell>
  );
}

/** A cutoff is a bare period ("no training on the window") — no venue, no constraint, no overlay to generate. */
function CutoffForm({ iso, onBack, onDone }: { iso: string; onBack: () => void; onDone: () => void }) {
  const [title, setTitle] = useState("");
  const [endDate, setEndDate] = useState(iso);
  const createCutoff = useCreateCutoff();

  const validEnd = endDate >= iso;
  const submit = () => {
    if (!validEnd) return;
    createCutoff.mutate(
      { title: title.trim() === "" ? "Coupure" : title.trim(), startDate: iso, endDate },
      { onSuccess: () => { toast.success("Coupure enregistrée"); onDone(); } },
    );
  };

  return (
    <FormShell onBack={onBack}>
      {/* eslint-disable-next-line jsx-a11y/no-autofocus -- inside a Modal: focusing the first field on step change is intentional */}
      <input className={fieldClass} aria-label="Intitulé de la coupure (optionnel)" placeholder="Intitulé (optionnel, ex. Coupure de Noël)" value={title} onChange={(e) => setTitle(e.target.value)} autoFocus />
      <EndDateField iso={iso} value={endDate} onChange={setEndDate} />
      <p className="text-xs text-muted-foreground">Rappel affiché au calendrier (🛑) et au radar — le planning de base reste inchangé, rien à générer.</p>
      <Button className="w-full" onClick={submit} disabled={createCutoff.isPending || !validEnd}>
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
