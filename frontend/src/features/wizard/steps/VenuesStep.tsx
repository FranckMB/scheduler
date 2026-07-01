import { Plus, Trash2, X } from "lucide-react";
import { type FormEvent, useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { Input } from "@/shared/components/ui/input";
import { Select } from "@/shared/components/ui/select";

import type { VenueTrainingSlot } from "../api";
import { DAYS, hhmm } from "../lib/days";
import { useCreateSlot, useCreateVenue, useDeleteSlot, useDeleteVenue, useUpdateSlot, useUpdateVenue, useVenueSlots, useWizardVenues } from "../queries";
import { VenueAvailabilityGrid } from "./VenueAvailabilityGrid";

const DURATIONS = [60, 75, 90, 105, 120];
const WEEK = DAYS.filter((d) => d.n <= 6);

function SlotEditor({ slot, onClose }: { slot: VenueTrainingSlot; onClose: () => void }) {
  const update = useUpdateSlot();
  const del = useDeleteSlot();
  const [day, setDay] = useState(slot.dayOfWeek);
  const [time, setTime] = useState(hhmm(slot.startTime));
  const [duration, setDuration] = useState(slot.durationMinutes);
  const [capacity, setCapacity] = useState(slot.capacity);

  const save = () => {
    update.mutate({ id: slot.id, body: { venueId: slot.venueId, dayOfWeek: day, startTime: time, durationMinutes: duration, capacity } });
    onClose();
  };

  return (
    <div className="mt-3 flex flex-wrap items-end gap-2 rounded-lg border border-accent/40 bg-card p-3">
      <span className="text-xs font-medium">Modifier le créneau</span>
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
            {d} min
          </option>
        ))}
      </Select>
      <Select aria-label="Capacité" className="h-8 w-20" value={capacity} onChange={(e) => setCapacity(Number(e.target.value))}>
        <option value={1}>cap 1</option>
        <option value={2}>cap 2</option>
      </Select>
      <Button size="sm" onClick={save} disabled={update.isPending}>
        Enregistrer
      </Button>
      <Button size="sm" variant="ghost" className="text-destructive" onClick={() => (del.mutate(slot.id), onClose())}>
        <Trash2 className="size-4" />
        Supprimer
      </Button>
      <Button size="icon" variant="ghost" className="size-8" aria-label="Fermer" onClick={onClose}>
        <X className="size-4" />
      </Button>
    </div>
  );
}

export function VenuesStep() {
  const { data: venues = [] } = useWizardVenues();
  const { data: slots = [] } = useVenueSlots();
  const create = useCreateVenue();
  const update = useUpdateVenue();
  const delVenue = useDeleteVenue();
  const addSlot = useCreateSlot();

  const [name, setName] = useState("");
  const [selectedId, setSelectedId] = useState("");
  const [duration, setDuration] = useState(90);
  const [capacity, setCapacity] = useState(1);
  const [venueName, setVenueName] = useState("");
  const [editingSlot, setEditingSlot] = useState<VenueTrainingSlot | null>(null);

  const selected = venues.find((v) => v.id === (selectedId || venues[0]?.id)) ?? null;

  const addVenue = (event: FormEvent) => {
    event.preventDefault();
    if ("" === name.trim()) {
      return;
    }
    create.mutate({ name: name.trim(), canSplit: false });
    setName("");
  };

  const emptyVenues = venues.filter((v) => !slots.some((s) => s.venueId === v.id));

  return (
    <div>
      <h2 className="mb-1 text-xl font-semibold">Gymnases</h2>
      <p className="mb-4 text-sm text-muted-foreground">
        Ajoutez vos gymnases, puis cliquez dans la grille pour poser les créneaux de disponibilité (jour + heure). Un gymnase sans créneau ne peut pas être utilisé.
      </p>

      <form onSubmit={addVenue} className="mb-4 flex items-end gap-2 rounded-lg border border-border bg-card p-3">
        <Input aria-label="Nom du gymnase" placeholder="Nom du gymnase" className="h-9 flex-1" value={name} onChange={(e) => setName(e.target.value)} />
        <Button type="submit" disabled={create.isPending}>
          <Plus className="size-4" />
          Ajouter un gymnase
        </Button>
      </form>

      {emptyVenues.length > 0 ? <p className="mb-3 text-xs text-destructive">Sans créneau : {emptyVenues.map((v) => v.name).join(", ")}.</p> : null}

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
            <input
              aria-label="Couleur"
              type="color"
              className="size-9 shrink-0 rounded border border-input bg-background"
              value={selected.color ?? "#666666"}
              onChange={(e) => update.mutate({ id: selected.id, body: { name: selected.name, color: e.target.value, canSplit: selected.canSplit } })}
            />
            <Input
              aria-label="Renommer le gymnase"
              className="h-9 w-48"
              defaultValue={selected.name}
              key={selected.id}
              onChange={(e) => setVenueName(e.target.value)}
              onBlur={() => venueName.trim() && venueName !== selected.name && update.mutate({ id: selected.id, body: { name: venueName.trim(), color: selected.color, canSplit: selected.canSplit } })}
            />
            <span className="mx-2 h-6 w-px bg-border" />
            <span className="text-xs text-muted-foreground">À poser :</span>
            <Select aria-label="Durée à poser" className="h-9 w-24" value={duration} onChange={(e) => setDuration(Number(e.target.value))}>
              {DURATIONS.map((d) => (
                <option key={d} value={d}>
                  {d} min
                </option>
              ))}
            </Select>
            <Select aria-label="Capacité à poser" className="h-9 w-24" value={capacity} onChange={(e) => setCapacity(Number(e.target.value))}>
              <option value={1}>cap 1</option>
              <option value={2}>cap 2</option>
            </Select>
            <Button size="sm" variant="ghost" className="ml-auto text-destructive" onClick={() => delVenue.mutate(selected.id)}>
              <Trash2 className="size-4" />
              Supprimer ce gymnase
            </Button>
          </div>

          <VenueAvailabilityGrid
            venue={selected}
            slots={slots.filter((s) => s.venueId === selected.id)}
            selectedSlotId={editingSlot?.id ?? null}
            onAdd={(dayOfWeek, startTime) => addSlot.mutate({ venueId: selected.id, dayOfWeek, startTime, durationMinutes: duration, capacity })}
            onSelect={(slot) => setEditingSlot(slot)}
          />

          {null !== editingSlot ? <SlotEditor key={editingSlot.id} slot={editingSlot} onClose={() => setEditingSlot(null)} /> : null}
        </>
      )}
    </div>
  );
}
