import { Plus, Trash2 } from "lucide-react";
import { type FormEvent, useEffect, useRef, useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { Input } from "@/shared/components/ui/input";
import { Modal } from "@/shared/components/ui/modal";
import { Select } from "@/shared/components/ui/select";
import { VenueSwatch } from "@/shared/components/ui/venue-swatch";
import { nextVenueColor } from "@/shared/lib/color";
import { formatDuration } from "@/shared/lib/duration";
import { toast } from "@/shared/stores/toastStore";

import type { Venue, VenueTrainingSlot } from "../api";
import { DAYS, hhmm } from "../lib/days";
import { conflictMessage, findSlotConflict } from "../lib/slotOverlap";
import { useCreateSlot, useCreateVenue, useDeleteSlot, useDeleteVenue, useUpdateSlot, useUpdateVenue, useVenueSlots, useWizardVenues } from "../queries";
import { useWizardStore } from "../store";
import { ReadonlyVenues } from "./StructureSummary";
import { VenueAvailabilityGrid } from "./VenueAvailabilityGrid";

const DURATIONS = [60, 75, 90, 105, 120];
const WEEK = DAYS.filter((d) => d.n <= 6);
const CAP_HINT = "Nombre d'équipes pouvant s'entraîner en même temps sur ce créneau (2 = terrain coupé en deux).";

/** Capacity picker — only a divisible gym can host 2 teams. On a non-divisible
 * gym there is no choice (always 1 team), so the control disappears entirely. */
function CapacitySelect({ value, onChange, canSplit, className }: { value: number; onChange: (n: number) => void; canSplit: boolean; className?: string }) {
  if (!canSplit) {
    return null;
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
export function ColorField({ venue, onApply }: { venue: Venue; onApply: (color: string) => void }) {
  const current = venue.color ?? "#666666";
  const [hex, setHex] = useState(current);
  // The native colour picker fires onChange CONTINUOUSLY while dragging — persisting
  // a PUT per step races the Doctrine @Version lock ("optimistic lock failed"). Keep
  // the live preview immediate (setHex) but debounce the write to the settled colour.
  // On unmount (leaving the step, switching gyms) we FLUSH the pending colour so a
  // last-second edit is never dropped. onApply is read through a ref to avoid a
  // stale closure.
  const applyTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const pending = useRef<string | null>(null);
  const onApplyRef = useRef(onApply);
  useEffect(() => {
    onApplyRef.current = onApply;
  }, [onApply]);
  // Reflect an EXTERNAL colour change (refetch, concurrent update) in the field —
  // but only when the user is not mid-edit (no pending write), so it never
  // clobbers what is being typed.
  useEffect(() => {
    if (null === pending.current) {
      setHex(venue.color ?? "#666666");
    }
  }, [venue.color]);

  const flush = () => {
    if (null !== applyTimer.current) {
      clearTimeout(applyTimer.current);
      applyTimer.current = null;
    }
    if (null !== pending.current) {
      onApplyRef.current(pending.current);
      pending.current = null;
    }
  };
  useEffect(() => flush, []);

  const commit = (value: string) => {
    setHex(value);
    if (HEX_RE.test(value)) {
      pending.current = value;
      if (null !== applyTimer.current) {
        clearTimeout(applyTimer.current);
      }
      applyTimer.current = setTimeout(flush, 300);
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

function SlotEditor({ slot, canSplit, otherSlots, onClose }: { slot: VenueTrainingSlot; canSplit: boolean; otherSlots: VenueTrainingSlot[]; onClose: () => void }) {
  const update = useUpdateSlot();
  const del = useDeleteSlot();
  const [day, setDay] = useState(slot.dayOfWeek);
  const [time, setTime] = useState(hhmm(slot.startTime));
  const [duration, setDuration] = useState(slot.durationMinutes);
  const [capacity, setCapacity] = useState(slot.capacity);
  const [error, setError] = useState<string | null>(null);

  const save = () => {
    // Never let an edit overlap another slot of the same gym/day (self excluded).
    const conflict = findSlotConflict(otherSlots, day, time, duration);
    if (null !== conflict) {
      setError(conflictMessage(conflict));
      return;
    }
    // Close ONLY once the write succeeds. If the backend rejects it (e.g. a stale
    // cache let a now-overlapping edit slip past the client check, caught by the
    // server backstop), keep the modal open so the edit is not silently lost and
    // the user can adjust — the global mutation net surfaces the error toast.
    // Otherwise a rejected edit would look saved (modal already gone).
    update.mutate({ id: slot.id, body: { venueId: slot.venueId, dayOfWeek: day, startTime: time, durationMinutes: duration, capacity: canSplit ? capacity : 1 } }, { onSuccess: onClose });
  };

  return (
    <Modal label="Modifier le créneau" title="Modifier le créneau" onClose={onClose}>
      <div className="mt-3 flex flex-wrap items-end gap-3">
        <label className="text-xs text-muted-foreground">
          Jour
          <Select aria-label="Jour" className="mt-0.5 h-9 w-28" value={day} onChange={(e) => (setDay(Number(e.target.value)), setError(null))}>
            {WEEK.map((d) => (
              <option key={d.n} value={d.n}>
                {d.label}
              </option>
            ))}
          </Select>
        </label>
        <label className="text-xs text-muted-foreground">
          Début
          <Input aria-label="Début" type="time" className="mt-0.5 h-9 w-28" value={time} onChange={(e) => (setTime(e.target.value), setError(null))} />
        </label>
        <label className="text-xs text-muted-foreground">
          Durée
          <Select aria-label="Durée" className="mt-0.5 h-9 w-28" value={duration} onChange={(e) => (setDuration(Number(e.target.value)), setError(null))}>
            {DURATIONS.map((d) => (
              <option key={d} value={d}>
                {formatDuration(d)}
              </option>
            ))}
          </Select>
        </label>
        {canSplit ? (
          <div className="text-xs text-muted-foreground">
            {/* CapacitySelect's inner <select> carries aria-label="Capacité". */}
            <span>Capacité</span>
            <CapacitySelect value={capacity} onChange={setCapacity} canSplit={canSplit} className="mt-0.5 block h-9 w-52" />
          </div>
        ) : null}
      </div>

      {null !== error ? (
        <p role="alert" className="mt-3 text-sm text-destructive">
          {error}
        </p>
      ) : null}

      <div className="mt-5 flex justify-end gap-2">
        <Button variant="ghost" className="text-destructive" onClick={() => (del.mutate(slot.id), onClose())}>
          <Trash2 className="size-4" />
          Supprimer
        </Button>
        <Button onClick={save} disabled={update.isPending}>
          Enregistrer
        </Button>
      </div>
    </Modal>
  );
}

export function VenuesStep() {
  const { mode, calendarEntryId } = useWizardStore();
  const periodMode = "period" === mode;
  if (periodMode) {
    return <ReadonlyVenues calendarEntryId={calendarEntryId} />;
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
  const [nameError, setNameError] = useState(false);
  const nameRef = useRef<HTMLInputElement>(null);
  const [selectedId, setSelectedId] = useState("");
  const [duration, setDuration] = useState(90);
  const [venueName, setVenueName] = useState("");
  const [editingSlot, setEditingSlot] = useState<VenueTrainingSlot | null>(null);
  // Capture the venue at click time — the dropdown selection may change before
  // the user confirms, and we must delete the venue the dialog is about.
  const [pendingDeleteVenue, setPendingDeleteVenue] = useState<Venue | null>(null);
  // Colours assigned to gyms created faster than the venues query refetches, so
  // rapid successive adds don't all pick VENUE_PALETTE[0] off the stale list.
  // Pruned once a colour actually lands in the venue list.
  const pendingColorsRef = useRef<string[]>([]);

  // Fall back to the first venue when the selected id isn't in the list yet
  // (just-created, list refetching) so the panel never flashes "no venue".
  const selected = (selectedId ? venues.find((v) => v.id === selectedId) : null) ?? venues[0] ?? null;
  const venueSlots = null === selected ? [] : slots.filter((s) => s.venueId === selected.id);

  // Drop pending colours that the refetched venue list now carries.
  useEffect(() => {
    const persisted = new Set(venues.map((v) => v.color?.toLowerCase()).filter((c): c is string => undefined !== c && null !== c));
    pendingColorsRef.current = pendingColorsRef.current.filter((c) => !persisted.has(c.toLowerCase()));
  }, [venues]);
  const pendingDeleteSlotCount = pendingDeleteVenue ? slots.filter((s) => s.venueId === pendingDeleteVenue.id).length : 0;

  const addVenue = (event: FormEvent) => {
    event.preventDefault();
    if ("" === name.trim()) {
      // Silent no-op was frustrating: surface why + jump to the empty field.
      setNameError(true);
      nameRef.current?.focus();
      return;
    }
    setNameError(false);
    // Select the freshly created venue so the slot grid targets it — otherwise
    // the selection stays on the previous gym and slots land on the wrong one.
    // A new gym gets a distinct rainbow colour by default (not flat grey); count
    // in-flight assignments so rapid adds don't collide on the same palette hue.
    const color = nextVenueColor([...venues.map((v) => v.color), ...pendingColorsRef.current]);
    pendingColorsRef.current.push(color);
    create.mutate(
      { name: name.trim(), color, canSplit: false },
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

      <form onSubmit={addVenue} className="mb-2 flex items-end gap-2 rounded-lg border border-border bg-card p-3">
        <Input
          ref={nameRef}
          aria-label="Nom du gymnase"
          aria-invalid={nameError}
          placeholder="Nom du gymnase"
          className={`h-8 flex-1 ${nameError ? "border-destructive focus-visible:ring-destructive" : ""}`}
          value={name}
          onChange={(e) => {
            setName(e.target.value);
            if (nameError) {
              setNameError(false);
            }
          }}
        />
        <Button type="submit" size="icon" className="size-8" disabled={create.isPending} title="Ajouter un gymnase" aria-label="Ajouter un gymnase">
          <Plus className="size-4" />
        </Button>
      </form>

      {nameError ? (
        <p role="alert" className="mb-3 text-sm text-destructive">
          Donnez un nom au gymnase avant de l'ajouter.
        </p>
      ) : null}

      {emptyVenues.length > 0 ? (
        <p role="alert" className="mb-3 text-sm text-destructive">
          {emptyVenues.length > 1 ? "Ces gymnases doivent avoir au moins un créneau" : "Ce gymnase doit avoir au moins un créneau"} : {emptyVenues.map((v) => v.name).join(", ")}.
        </p>
      ) : null}

      {null === selected ? (
        <EmptyHint>Aucun gymnase pour le moment.</EmptyHint>
      ) : (
        <>
          {/* Selection row — pick WHICH gym to work on (kept visually separate
              from the edit card below so the two intents don't blur together). */}
          <div className="mb-3 flex flex-wrap items-center gap-2">
            <label className="text-sm font-medium" htmlFor="venue-picker">
              Gymnase :
            </label>
            <Select
              id="venue-picker"
              aria-label="Gymnase"
              className="h-9 w-56"
              value={selected.id}
              onChange={(e) => {
                setSelectedId(e.target.value);
                setEditingSlot(null);
                // Drop the rename buffer so switching gyms can't write the name
                // typed for the previous one onto the newly selected gym.
                setVenueName("");
              }}
            >
              {venues.map((v) => (
                <option key={v.id} value={v.id}>
                  {v.name}
                </option>
              ))}
            </Select>
            <VenueSwatch color={selected.color ?? "#666666"} className="size-4 border border-input" />
          </div>

          {/* Edit card — properties of the SELECTED gym (distinct block). */}
          <div className="mb-3 rounded-lg border border-border bg-card p-3">
            <div className="mb-2 text-xs font-medium text-muted-foreground">Édition du gymnase « {selected.name} »</div>
            <div className="flex flex-wrap items-center gap-3">
              <ColorField key={`color-${selected.id}`} venue={selected} onApply={(color) => update.mutate({ id: selected.id, body: { name: selected.name, color, canSplit: selected.canSplit } })} />
              <Input
                aria-label="Renommer le gymnase"
                className="h-9 w-56"
                defaultValue={selected.name}
                key={`name-${selected.id}`}
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
              <Button size="icon" variant="ghost" className="ml-auto size-8 text-destructive" onClick={() => setPendingDeleteVenue(selected)} title="Supprimer ce gymnase" aria-label="Supprimer ce gymnase">
                <Trash2 className="size-4" />
              </Button>
            </div>
          </div>

          {/* Slot-placement toolbar — only the duration of the next dropped slot.
              Capacity is set per-slot in the edit panel (a new slot is always 1). */}
          <div className="mb-3 flex flex-wrap items-center gap-2">
            <span className="text-xs text-muted-foreground">À poser :</span>
            <Select aria-label="Durée à poser" className="h-9 w-24" value={duration} onChange={(e) => setDuration(Number(e.target.value))}>
              {DURATIONS.map((d) => (
                <option key={d} value={d}>
                  {formatDuration(d)}
                </option>
              ))}
            </Select>
          </div>

          <VenueAvailabilityGrid
            venue={selected}
            slots={venueSlots}
            selectedSlotId={editingSlot?.id ?? null}
            onAdd={(dayOfWeek, startTime) => {
              // Forbid dropping a slot that overlaps an existing one (same gym/day).
              const conflict = findSlotConflict(venueSlots, dayOfWeek, startTime, duration);
              if (null !== conflict) {
                toast.error(conflictMessage(conflict));
                return;
              }
              addSlot.mutate({ venueId: selected.id, dayOfWeek, startTime, durationMinutes: duration, capacity: 1 });
            }}
            onSelect={(slot) => setEditingSlot(slot)}
          />

          {null !== editingSlot ? (
            <SlotEditor key={editingSlot.id} slot={editingSlot} canSplit={selected.canSplit} otherSlots={venueSlots.filter((s) => s.id !== editingSlot.id)} onClose={() => setEditingSlot(null)} />
          ) : null}
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
