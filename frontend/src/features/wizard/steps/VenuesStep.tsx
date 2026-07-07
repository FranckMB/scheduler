import { Check, Plus, Trash2, X } from "lucide-react";
import { type FormEvent, useRef, useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { Input } from "@/shared/components/ui/input";
import { Select } from "@/shared/components/ui/select";
import { formatDuration } from "@/shared/lib/duration";

import { useEntryConflicts } from "@/features/cockpit/queries";

import type { Venue, VenueTrainingSlot } from "../api";
import { DAYS, hhmm } from "../lib/days";
import { useCreateSlot, useCreateVenue, useDeleteSlot, useDeleteVenue, useUpdateSlot, useUpdateVenue, useVenueSlots, useWizardVenues } from "../queries";
import { useWizardStore } from "../store";
import { PeriodReadOnlyStructure } from "./PeriodReadOnly";
import { VenueAvailabilityGrid } from "./VenueAvailabilityGrid";

const DURATIONS = [60, 75, 90, 105, 120];
const WEEK = DAYS.filter((d) => d.n <= 6);
const CAP_HINT = "Nombre d'équipes pouvant s'entraîner en même temps sur ce créneau (2 = terrain coupé en deux).";

/** Capacity select shared by the editor and the "à poser" toolbar. Only a
 * splittable gym can host 2 teams; otherwise the whole court is used. */
function CapacitySelect({ value, onChange, canSplit, className }: { value: number; onChange: (n: number) => void; canSplit: boolean; className?: string }) {
  if (!canSplit) {
    return <span className={`inline-flex items-center rounded-md border border-input bg-muted/40 px-2 text-xs text-muted-foreground ${className ?? ""}`}>1 équipe (terrain entier)</span>;
  }
  return (
    <Select aria-label="Capacité" title={CAP_HINT} className={className} value={value} onChange={(e) => onChange(Number(e.target.value))}>
      <option value={1}>1 équipe (terrain entier)</option>
      <option value={2}>2 équipes (terrain divisé)</option>
    </Select>
  );
}

const HEX_RE = /^#[0-9a-fA-F]{6}$/;

/** Colour swatch + free hex field; both apply immediately, no Enter needed. */
function ColorField({ venue, onApply }: { venue: Venue; onApply: (color: string) => void }) {
  const current = venue.color ?? "#666666";
  const [hex, setHex] = useState(current);
  const commit = (value: string) => {
    setHex(value);
    if (HEX_RE.test(value)) {
      onApply(value);
    }
  };
  return (
    <div className="flex items-center gap-1">
      <input
        aria-label="Couleur"
        type="color"
        className="size-9 shrink-0 rounded border border-input bg-background"
        value={HEX_RE.test(hex) ? hex : current}
        onChange={(e) => commit(e.target.value)}
      />
      <Input aria-label="Couleur (hexadécimal)" className="h-9 w-24 font-mono text-xs" value={hex} placeholder="#3498DB" onChange={(e) => commit(e.target.value)} />
    </div>
  );
}

function SlotEditor({ slot, canSplit, onClose }: { slot: VenueTrainingSlot; canSplit: boolean; onClose: () => void }) {
  const update = useUpdateSlot();
  const del = useDeleteSlot();
  const [day, setDay] = useState(slot.dayOfWeek);
  const [time, setTime] = useState(hhmm(slot.startTime));
  const [duration, setDuration] = useState(slot.durationMinutes);
  const [capacity, setCapacity] = useState(slot.capacity);

  const save = () => {
    update.mutate({ id: slot.id, body: { venueId: slot.venueId, dayOfWeek: day, startTime: time, durationMinutes: duration, capacity: canSplit ? capacity : 1 } });
    onClose();
  };

  return (
    <div className="mt-3 rounded-lg border border-accent/40 bg-card p-3">
      <div className="mb-2 text-xs font-medium">Modification du créneau</div>
      <div className="flex flex-wrap items-end gap-2">
        <Select aria-label="Jour" className="h-8 w-24" value={day} onChange={(e) => setDay(Number(e.target.value))}>
          {WEEK.map((d) => (
            <option key={d.n} value={d.n}>
              {d.label}
            </option>
          ))}
        </Select>
        <Input aria-label="Début" type="time" className="h-8 w-28" value={time} onChange={(e) => setTime(e.target.value)} />
        <Select aria-label="Durée" className="h-8 w-24" value={duration} onChange={(e) => setDuration(Number(e.target.value))}>
          {DURATIONS.map((d) => (
            <option key={d} value={d}>
              {formatDuration(d)}
            </option>
          ))}
        </Select>
        <CapacitySelect value={capacity} onChange={setCapacity} canSplit={canSplit} className="h-8 w-52" />
        <Button size="icon" className="size-8" onClick={save} disabled={update.isPending} title="Enregistrer" aria-label="Enregistrer">
          <Check className="size-4" />
        </Button>
        <Button size="icon" variant="ghost" className="size-8 text-destructive" onClick={() => (del.mutate(slot.id), onClose())} title="Supprimer" aria-label="Supprimer">
          <Trash2 className="size-4" />
        </Button>
        <Button size="icon" variant="ghost" className="size-8" aria-label="Fermer" title="Fermer" onClick={onClose}>
          <X className="size-4" />
        </Button>
      </div>
    </div>
  );
}

export function VenuesStep() {
  const { mode, calendarEntryId } = useWizardStore();
  const periodMode = "period" === mode;
  const { data: venues = [] } = useWizardVenues();
  const { data: conflicts } = useEntryConflicts(periodMode ? calendarEntryId : null);
  if (periodMode) {
    const closed = new Set(conflicts?.venueIds ?? []);
    return (
      <PeriodReadOnlyStructure
        title="Gymnases"
        items={venues.map((v) => ({ id: v.id, label: v.name, note: closed.has(v.id) ? "fermé cette période" : undefined }))}
      />
    );
  }
  return <VenuesEditor />;
}

function VenuesEditor() {
  const { data: venues = [] } = useWizardVenues();
  const { data: slots = [] } = useVenueSlots();
  const create = useCreateVenue();
  const update = useUpdateVenue();
  const delVenue = useDeleteVenue();
  const addSlot = useCreateSlot();

  const [name, setName] = useState("");
  const nameRef = useRef<HTMLInputElement>(null);
  const [selectedId, setSelectedId] = useState("");
  const [duration, setDuration] = useState(90);
  const [capacity, setCapacity] = useState(1);
  const [venueName, setVenueName] = useState("");
  const [editingSlot, setEditingSlot] = useState<VenueTrainingSlot | null>(null);
  // Capture the venue at click time — the dropdown selection may change before
  // the user confirms, and we must delete the venue the dialog is about.
  const [pendingDeleteVenue, setPendingDeleteVenue] = useState<Venue | null>(null);

  // Fall back to the first venue when the selected id isn't in the list yet
  // (just-created, list refetching) so the panel never flashes "no venue".
  const selected = (selectedId ? venues.find((v) => v.id === selectedId) : null) ?? venues[0] ?? null;
  const pendingDeleteSlotCount = pendingDeleteVenue ? slots.filter((s) => s.venueId === pendingDeleteVenue.id).length : 0;

  const addVenue = (event: FormEvent) => {
    event.preventDefault();
    if ("" === name.trim()) {
      return;
    }
    // Select the freshly created venue so the slot grid targets it — otherwise
    // the selection stays on the previous gym and slots land on the wrong one.
    create.mutate(
      { name: name.trim(), canSplit: false },
      { onSuccess: (created) => setSelectedId(created.id) },
    );
    setName("");
    // Keep the cursor in the name field to add the next gym without re-clicking.
    nameRef.current?.focus();
  };

  const emptyVenues = venues.filter((v) => !slots.some((s) => s.venueId === v.id));

  return (
    <div>
      <p className="mb-4 text-sm text-muted-foreground">
        Ajoutez vos gymnases, puis cliquez dans la grille pour poser les créneaux de disponibilité (jour + heure). Un gymnase sans créneau ne peut pas être utilisé.
      </p>

      <form onSubmit={addVenue} className="mb-4 flex items-end gap-2 rounded-lg border border-border bg-card p-3">
        <Input ref={nameRef} aria-label="Nom du gymnase" placeholder="Nom du gymnase" className="h-8 flex-1" value={name} onChange={(e) => setName(e.target.value)} />
        <Button type="submit" size="icon" className="size-8" disabled={create.isPending} title="Ajouter un gymnase" aria-label="Ajouter un gymnase">
          <Plus className="size-4" />
        </Button>
      </form>

      {emptyVenues.length > 0 ? (
        <p role="alert" className="mb-3 text-sm text-destructive">
          {emptyVenues.length > 1 ? "Ces gymnases doivent avoir au moins un créneau" : "Ce gymnase doit avoir au moins un créneau"} : {emptyVenues.map((v) => v.name).join(", ")}.
        </p>
      ) : null}

      {null === selected ? (
        <p className="text-sm text-muted-foreground">Aucun gymnase pour le moment.</p>
      ) : (
        <>
          <div className="mb-3 flex flex-wrap items-center gap-2">
            <Select
              aria-label="Gymnase"
              className="h-9 w-48"
              value={selected.id}
              onChange={(e) => {
                setSelectedId(e.target.value);
                setEditingSlot(null);
              }}
            >
              {venues.map((v) => (
                <option key={v.id} value={v.id}>
                  {v.name}
                </option>
              ))}
            </Select>
            <ColorField key={selected.id} venue={selected} onApply={(color) => update.mutate({ id: selected.id, body: { name: selected.name, color, canSplit: selected.canSplit } })} />
            <Input
              aria-label="Renommer le gymnase"
              className="h-9 w-48"
              defaultValue={selected.name}
              key={selected.id}
              onChange={(e) => setVenueName(e.target.value)}
              onBlur={() => venueName.trim() && venueName !== selected.name && update.mutate({ id: selected.id, body: { name: venueName.trim(), color: selected.color, canSplit: selected.canSplit } })}
            />
            <label className="flex items-center gap-1 text-xs text-muted-foreground" title="Un gymnase divisible peut accueillir 2 équipes en même temps (terrain coupé en deux).">
              <input
                type="checkbox"
                checked={selected.canSplit}
                onChange={(e) => update.mutate({ id: selected.id, body: { name: selected.name, color: selected.color, canSplit: e.target.checked } })}
              />
              Terrain divisible
            </label>
            <span className="mx-2 h-6 w-px bg-border" />
            <span className="text-xs text-muted-foreground">À poser :</span>
            <Select aria-label="Durée à poser" className="h-9 w-24" value={duration} onChange={(e) => setDuration(Number(e.target.value))}>
              {DURATIONS.map((d) => (
                <option key={d} value={d}>
                  {formatDuration(d)}
                </option>
              ))}
            </Select>
            <CapacitySelect value={capacity} onChange={setCapacity} canSplit={selected.canSplit} className="h-9 w-52" />
            <Button size="icon" variant="ghost" className="ml-auto size-8 text-destructive" onClick={() => setPendingDeleteVenue(selected)} title="Supprimer ce gymnase" aria-label="Supprimer ce gymnase">
              <Trash2 className="size-4" />
            </Button>
          </div>

          <VenueAvailabilityGrid
            venue={selected}
            slots={slots.filter((s) => s.venueId === selected.id)}
            selectedSlotId={editingSlot?.id ?? null}
            onAdd={(dayOfWeek, startTime) => addSlot.mutate({ venueId: selected.id, dayOfWeek, startTime, durationMinutes: duration, capacity: selected.canSplit ? capacity : 1 })}
            onSelect={(slot) => setEditingSlot(slot)}
          />

          {null !== editingSlot ? <SlotEditor key={editingSlot.id} slot={editingSlot} canSplit={selected.canSplit} onClose={() => setEditingSlot(null)} /> : null}
        </>
      )}

      <ConfirmDialog
        open={pendingDeleteVenue !== null}
        title="Supprimer ce gymnase ?"
        description={
          pendingDeleteVenue ? (
            <>
              Le gymnase « {pendingDeleteVenue.name} »
              {pendingDeleteSlotCount > 0 ? (
                <>
                  {" "}
                  et ses {pendingDeleteSlotCount} créneau{pendingDeleteSlotCount > 1 ? "x" : ""} de disponibilité
                </>
              ) : null}{" "}
              seront supprimés définitivement.
            </>
          ) : null
        }
        confirmLabel="Supprimer"
        onCancel={() => setPendingDeleteVenue(null)}
        onConfirm={() => {
          if (pendingDeleteVenue) {
            delVenue.mutate(pendingDeleteVenue.id);
          }
          setPendingDeleteVenue(null);
        }}
      />
    </div>
  );
}
