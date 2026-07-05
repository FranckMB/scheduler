import { CalendarX2, PartyPopper, Trash2, X } from "lucide-react";
import { type ReactNode, useEffect, useState } from "react";
import { createPortal } from "react-dom";

import { useVenues } from "@/features/planning/queries";
import { Button } from "@/shared/components/ui/button";
import { toast } from "@/shared/stores/toastStore";

import type { CalendarEntry } from "./api";
import { useCreateEvent, useCreateVenueClosure, useDeleteEntry } from "./queries";

type Mode = "list" | "event" | "closure";

interface DayDialogProps {
  iso: string;
  entries: CalendarEntry[];
  onClose: () => void;
}

/** Lightweight day dialog (annotation = modal, spec §5bis): lists the day's entries and creates an event / venue closure. */
export function DayDialog({ iso, entries, onClose }: DayDialogProps) {
  const [mode, setMode] = useState<Mode>("list");

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") onClose();
    };
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [onClose]);

  return createPortal(
    <div className="fixed inset-0 z-[90] flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-black/50" aria-hidden="true" onClick={onClose} />
      <div role="dialog" aria-modal="true" aria-label={`Jour ${iso}`} className="relative w-full max-w-md rounded-lg border border-border bg-card p-5 text-card-foreground shadow-xl">
        <div className="flex items-center justify-between">
          <h2 className="text-base font-semibold">{formatFrDate(iso)}</h2>
          <button type="button" aria-label="Fermer" className="rounded p-1 text-muted-foreground hover:text-foreground" onClick={onClose}>
            <X className="size-4" />
          </button>
        </div>

        <div className="mt-4">
          {mode === "list" ? <DayList entries={entries} onCreate={setMode} onClose={onClose} /> : null}
          {mode === "event" ? <EventForm iso={iso} onBack={() => setMode("list")} onDone={onClose} /> : null}
          {mode === "closure" ? <ClosureForm iso={iso} onBack={() => setMode("list")} onDone={onClose} /> : null}
        </div>
      </div>
    </div>,
    document.body,
  );
}

function DayList({ entries, onCreate, onClose }: { entries: CalendarEntry[]; onCreate: (m: Mode) => void; onClose: () => void }) {
  const deleteEntry = useDeleteEntry();

  return (
    <div className="space-y-4">
      {entries.length > 0 ? (
        <ul className="space-y-2">
          {entries.map((entry) => (
            <li key={entry.id} className="flex items-center justify-between rounded-md border border-border px-3 py-2 text-sm">
              <span className="flex items-center gap-2">
                {entry.kind === "period" ? <CalendarX2 className="size-4 text-destructive" /> : <PartyPopper className="size-4 text-accent" />}
                <span>{entry.title}</span>
              </span>
              <button
                type="button"
                aria-label={`Supprimer ${entry.title}`}
                className="rounded p-1 text-muted-foreground hover:text-destructive"
                disabled={deleteEntry.isPending}
                onClick={() => deleteEntry.mutate(entry.id, { onSuccess: () => toast.success("Entrée supprimée"), onError: () => toast.error("Suppression impossible") })}
              >
                <Trash2 className="size-4" />
              </button>
            </li>
          ))}
        </ul>
      ) : (
        <p className="text-sm text-muted-foreground">Rien ce jour-là — la semaine type tourne normalement.</p>
      )}

      <div className="grid grid-cols-1 gap-2">
        <Button variant="outline" onClick={() => onCreate("event")}>
          Événement
        </Button>
        <Button variant="outline" onClick={() => onCreate("closure")}>
          Signaler une indisponibilité
        </Button>
        <Button variant="ghost" disabled title="Bientôt (palier B)">
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

function EventForm({ iso, onBack, onDone }: { iso: string; onBack: () => void; onDone: () => void }) {
  const [title, setTitle] = useState("");
  const [endDate, setEndDate] = useState(iso);
  const [isDisruptive, setDisruptive] = useState(false);
  const createEvent = useCreateEvent();

  const submit = () => {
    if (title.trim() === "") return;
    createEvent.mutate(
      { title: title.trim(), startDate: iso, endDate, isDisruptive },
      { onSuccess: () => { toast.success("Événement ajouté"); onDone(); }, onError: () => toast.error("Création impossible") },
    );
  };

  return (
    <FormShell onBack={onBack}>
      <input className={fieldClass} placeholder="Titre (AG, tournoi…)" value={title} onChange={(e) => setTitle(e.target.value)} autoFocus />
      <label className="block text-xs text-muted-foreground">
        Jusqu'au
        <input type="date" className={`${fieldClass} mt-1`} value={endDate} min={iso} onChange={(e) => setEndDate(e.target.value)} />
      </label>
      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" checked={isDisruptive} onChange={(e) => setDisruptive(e.target.checked)} />
        Perturbant (pas d'entraînement ce jour)
      </label>
      <Button className="w-full" onClick={submit} disabled={createEvent.isPending || title.trim() === ""}>
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

  const submit = () => {
    if (venueId === "") return;
    const venueName = venues?.find((v) => v.id === venueId)?.name ?? "Salle";
    createClosure.mutate(
      { title: title.trim() === "" ? `${venueName} fermé` : title.trim(), startDate: iso, endDate, venueId },
      { onSuccess: () => { toast.success("Indisponibilité enregistrée"); onDone(); }, onError: () => toast.error("Création impossible") },
    );
  };

  return (
    <FormShell onBack={onBack}>
      <select className={fieldClass} value={venueId} onChange={(e) => setVenueId(e.target.value)} autoFocus>
        <option value="">Salle indisponible…</option>
        {(venues ?? []).map((v) => (
          <option key={v.id} value={v.id}>
            {v.name}
          </option>
        ))}
      </select>
      <input className={fieldClass} placeholder="Intitulé (optionnel)" value={title} onChange={(e) => setTitle(e.target.value)} />
      <label className="block text-xs text-muted-foreground">
        Jusqu'au
        <input type="date" className={`${fieldClass} mt-1`} value={endDate} min={iso} onChange={(e) => setEndDate(e.target.value)} />
      </label>
      <Button className="w-full" onClick={submit} disabled={createClosure.isPending || venueId === ""}>
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
