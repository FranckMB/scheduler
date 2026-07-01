import { Plus, Trash2 } from "lucide-react";
import { type FormEvent, useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { Input } from "@/shared/components/ui/input";
import { Select } from "@/shared/components/ui/select";
import { cn } from "@/shared/lib/utils";

import type { Venue, VenueTrainingSlot } from "../api";
import { DAYS, dayLabel, hhmm } from "../lib/days";
import { useCreateSlot, useCreateVenue, useDeleteSlot, useDeleteVenue, useUpdateVenue, useVenueSlots, useWizardVenues } from "../queries";

function AddSlotForm({ venueId }: { venueId: string }) {
  const create = useCreateSlot();
  const [day, setDay] = useState(1);
  const [time, setTime] = useState("18:00");
  const [duration, setDuration] = useState("90");
  const [capacity, setCapacity] = useState(1);

  const submit = (event: FormEvent) => {
    event.preventDefault();
    create.mutate({ venueId, dayOfWeek: day, startTime: time, durationMinutes: Number(duration), capacity });
  };

  return (
    <form onSubmit={submit} className="mt-2 flex flex-wrap items-end gap-2">
      <Select aria-label="Jour" className="h-8 w-20" value={day} onChange={(e) => setDay(Number(e.target.value))}>
        {DAYS.map((d) => (
          <option key={d.n} value={d.n}>
            {d.label}
          </option>
        ))}
      </Select>
      <Input aria-label="Heure" type="time" className="h-8 w-28" value={time} onChange={(e) => setTime(e.target.value)} />
      <Input aria-label="Durée (min)" type="number" min={15} step={15} className="h-8 w-20" value={duration} onChange={(e) => setDuration(e.target.value)} />
      <Select aria-label="Capacité" className="h-8 w-16" value={capacity} onChange={(e) => setCapacity(Number(e.target.value))}>
        <option value={1}>1</option>
        <option value={2}>2</option>
      </Select>
      <Button size="sm" type="submit" variant="outline" disabled={create.isPending}>
        <Plus className="size-4" />
        Créneau
      </Button>
    </form>
  );
}

function VenueCard({ venue, slots }: { venue: Venue; slots: VenueTrainingSlot[] }) {
  const update = useUpdateVenue();
  const del = useDeleteVenue();
  const delSlot = useDeleteSlot();
  const [name, setName] = useState(venue.name);

  const noSlot = 0 === slots.length;

  return (
    <div className={cn("rounded-lg border bg-card p-3", noSlot ? "border-destructive/50" : "border-border")}>
      <div className="flex items-center gap-2">
        <Input
          aria-label="Nom du gymnase"
          className="h-8 flex-1 font-medium"
          value={name}
          onChange={(e) => setName(e.target.value)}
          onBlur={() => name.trim() && name !== venue.name && update.mutate({ id: venue.id, body: { name: name.trim(), color: venue.color, canSplit: venue.canSplit } })}
        />
        <input
          aria-label="Couleur"
          type="color"
          className="size-8 shrink-0 rounded border border-input bg-background"
          value={venue.color ?? "#666666"}
          onChange={(e) => update.mutate({ id: venue.id, body: { name: venue.name, color: e.target.value, canSplit: venue.canSplit } })}
        />
        <Button size="icon" variant="ghost" className="size-8 text-destructive" aria-label="Supprimer le gymnase" onClick={() => del.mutate(venue.id)}>
          <Trash2 className="size-4" />
        </Button>
      </div>

      <div className="mt-2">
        {noSlot ? (
          <p className="text-xs text-destructive">Aucun créneau — ce gymnase est inutilisable.</p>
        ) : (
          <ul className="flex flex-wrap gap-1.5">
            {slots.map((slot) => (
              <li key={slot.id} className="flex items-center gap-1 rounded-full border border-border px-2 py-0.5 text-xs">
                <span>
                  {dayLabel(slot.dayOfWeek)} {hhmm(slot.startTime)} · {slot.durationMinutes}min · cap {slot.capacity}
                </span>
                <button type="button" aria-label="Retirer le créneau" className="text-muted-foreground hover:text-destructive" onClick={() => delSlot.mutate(slot.id)}>
                  <Trash2 className="size-3" />
                </button>
              </li>
            ))}
          </ul>
        )}
        <AddSlotForm venueId={venue.id} />
      </div>
    </div>
  );
}

export function VenuesStep() {
  const { data: venues = [] } = useWizardVenues();
  const { data: slots = [] } = useVenueSlots();
  const create = useCreateVenue();
  const [name, setName] = useState("");

  const add = (event: FormEvent) => {
    event.preventDefault();
    if ("" === name.trim()) {
      return;
    }
    create.mutate({ name: name.trim(), canSplit: false });
    setName("");
  };

  return (
    <div>
      <h2 className="mb-1 text-xl font-semibold">Gymnases</h2>
      <p className="mb-4 text-sm text-muted-foreground">Ajoutez vos gymnases et leurs créneaux de disponibilité (jour + heure). Un gymnase sans créneau ne peut pas être utilisé.</p>

      <form onSubmit={add} className="mb-6 flex items-end gap-2 rounded-lg border border-border bg-card p-3">
        <Input aria-label="Nom du gymnase" placeholder="Nom du gymnase" className="h-9 flex-1" value={name} onChange={(e) => setName(e.target.value)} />
        <Button type="submit" disabled={create.isPending}>
          <Plus className="size-4" />
          Ajouter
        </Button>
      </form>

      {0 === venues.length ? (
        <p className="text-sm text-muted-foreground">Aucun gymnase pour le moment.</p>
      ) : (
        <div className="flex flex-col gap-3">
          {venues.map((venue) => (
            <VenueCard key={venue.id} venue={venue} slots={slots.filter((s) => s.venueId === venue.id)} />
          ))}
        </div>
      )}
    </div>
  );
}
