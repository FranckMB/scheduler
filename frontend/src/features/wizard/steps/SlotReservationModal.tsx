import { Lock, Trash2 } from "lucide-react";

import { Modal } from "@/shared/components/ui/modal";
import { Select } from "@/shared/components/ui/select";

import type { PriorityTier, Reservation, Team, Venue, VenueTrainingSlot } from "../api";
import { dayLabel, hhmm } from "../lib/days";
import { useCreateReservation, useDeleteReservation } from "../queries";
import { assignableTeams, effectiveSlotCapacity, slotKey } from "../lib/reservationSlots";

interface Props {
  slot: VenueTrainingSlot;
  venue: Venue;
  teams: Team[];
  tiers: PriorityTier[];
  reservations: Reservation[];
  venueCanSplit: Map<string, boolean>;
  schedulePlanId: string | null;
  onClose: () => void;
}

/**
 * Assign/remove teams on ONE availability slot (the "Réserver" tab's interaction).
 * Shows the teams already pinned (removable) and a rank-ordered picker limited to
 * the slot's remaining capacity, excluding teams at their session ceiling.
 */
export function SlotReservationModal({ slot, venue, teams, tiers, reservations, venueCanSplit, schedulePlanId, onClose }: Props) {
  const create = useCreateReservation();
  const del = useDeleteReservation();

  const teamName = new Map(teams.map((t) => [t.id, t.name]));
  const key = slotKey(slot.venueId, slot.dayOfWeek, slot.startTime);
  // Reservations pinned on THIS physical slot (with their id, for removal).
  const onSlot = reservations.filter((r) => slotKey(r.venueId, r.dayOfWeek, r.startTime) === key);
  const capacity = effectiveSlotCapacity(slot, venueCanSplit);
  const pickable = assignableTeams(teams, tiers, slot, reservations, venueCanSplit);

  const add = (teamId: string) => {
    if ("" === teamId) {
      return;
    }
    create.mutate({ teamId, venueId: slot.venueId, dayOfWeek: slot.dayOfWeek, startTime: hhmm(slot.startTime), durationMinutes: slot.durationMinutes, schedulePlanId });
  };

  return (
    <Modal label="Réserver ce créneau" title={`${venue.name} · ${dayLabel(slot.dayOfWeek)} ${hhmm(slot.startTime)}`} onClose={onClose}>
      <p className="mb-3 text-xs text-muted-foreground">
        Fixe une équipe sur ce créneau (verrou pris en compte à chaque génération). Ce créneau accepte {capacity} équipe{capacity > 1 ? "s" : ""}.
      </p>

      {onSlot.length > 0 ? (
        <ul className="mb-3 flex flex-col gap-1">
          {onSlot.map((r) => (
            <li key={r.id} className="flex items-center gap-2 rounded-md border border-border bg-card px-3 py-1.5 text-sm">
              <Lock className="size-3.5 text-accent" />
              <span className="flex-1 font-medium">{teamName.get(r.teamId) ?? "?"}</span>
              <button type="button" aria-label={`Retirer ${teamName.get(r.teamId) ?? "l'équipe"}`} className="text-muted-foreground hover:text-destructive" onClick={() => del.mutate(r.id)}>
                <Trash2 className="size-4" />
              </button>
            </li>
          ))}
        </ul>
      ) : null}

      {onSlot.length < capacity ? (
        pickable.length > 0 ? (
          <Select
            aria-label="Ajouter une équipe"
            className="h-9 w-full"
            value=""
            onChange={(e) => add(e.target.value)}
            disabled={create.isPending}
          >
            <option value="">— ajouter une équipe —</option>
            {pickable.map((t) => (
              <option key={t.id} value={t.id}>
                {t.name}
              </option>
            ))}
          </Select>
        ) : (
          <p className="text-xs text-muted-foreground">Aucune équipe disponible (toutes ont atteint leur nombre de séances ou sont déjà sur ce créneau).</p>
        )
      ) : (
        <p className="text-xs text-muted-foreground">Créneau complet ({capacity}/{capacity}).</p>
      )}
    </Modal>
  );
}
