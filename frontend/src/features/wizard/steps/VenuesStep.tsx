import { Plus, Trash2 } from "lucide-react";
import { type FormEvent, useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { Input } from "@/shared/components/ui/input";
import { Select } from "@/shared/components/ui/select";

import { useCreateSlot, useCreateVenue, useDeleteSlot, useDeleteVenue, useUpdateVenue, useVenueSlots, useWizardVenues } from "../queries";
import { VenueAvailabilityGrid } from "./VenueAvailabilityGrid";

const DURATIONS = [60, 75, 90, 105, 120];

export function VenuesStep() {
  const { data: venues = [] } = useWizardVenues();
  const { data: slots = [] } = useVenueSlots();
  const create = useCreateVenue();
  const update = useUpdateVenue();
  const delVenue = useDeleteVenue();
  const addSlot = useCreateSlot();
  const delSlot = useDeleteSlot();

  const [name, setName] = useState("");
  const [selectedId, setSelectedId] = useState("");
  const [duration, setDuration] = useState(90);
  const [capacity, setCapacity] = useState(1);
  const [venueName, setVenueName] = useState("");

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
            <Select aria-label="Gymnase" className="h-9 w-48" value={selected.id} onChange={(e) => setSelectedId(e.target.value)}>
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
            onAdd={(dayOfWeek, startTime) => addSlot.mutate({ venueId: selected.id, dayOfWeek, startTime, durationMinutes: duration, capacity })}
            onRemove={(id) => delSlot.mutate(id)}
          />
        </>
      )}
    </div>
  );
}
