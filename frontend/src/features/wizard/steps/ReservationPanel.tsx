import { useState } from "react";

import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { Select } from "@/shared/components/ui/select";
import { VenueSwatch } from "@/shared/components/ui/venue-swatch";

import type { PriorityTier, Team, Venue, VenueTrainingSlot } from "../api";
import { reservedTeamsBySlot, effectiveSlotCapacity, slotKey } from "../lib/reservationSlots";
import { useReservations, useVenueSlots } from "../queries";
import { ReservationGrid } from "./ReservationGrid";
import { SlotReservationModal } from "./SlotReservationModal";

/**
 * "Réserver" tab: a per-venue weekly grid of the club's availability slots — click
 * a slot to pin one or two teams on it (a HARD lock honored at generation). The
 * rank-sorted summary of all reservations lives in the Récap step, not here (kept
 * épuré). Server-backed via the Reservation entity; base plan vs period overlay by
 * `schedulePlanId` (ADR-0002 inv. 5 : la réservation est une RÉPONSE, elle pend au plan).
 */
export function ReservationPanel({ teams, tiers, venues, schedulePlanId }: { teams: Team[]; tiers: PriorityTier[]; venues: Venue[]; schedulePlanId: string | null }) {
  const { data: slots = [] } = useVenueSlots();
  const { data: reservations = [] } = useReservations(schedulePlanId);
  const [venueId, setVenueId] = useState("");
  const [activeSlot, setActiveSlot] = useState<VenueTrainingSlot | null>(null);

  if (0 === venues.length) {
    return <EmptyHint>Ajoutez d'abord un gymnase et ses créneaux pour pouvoir réserver.</EmptyHint>;
  }

  const selected = venues.find((v) => v.id === venueId) ?? venues[0];
  const venueCanSplit = new Map(venues.map((v) => [v.id, v.canSplit]));
  const teamName = new Map(teams.map((t) => [t.id, t.name]));
  const venueSlots = slots.filter((s) => s.venueId === selected.id);

  // slotKey → reserved team NAMES (for the grid badges).
  const reservedNames = new Map<string, string[]>();
  reservedTeamsBySlot(reservations).forEach((ids, key) => reservedNames.set(key, ids.map((id) => teamName.get(id) ?? "?")));

  return (
    <div>
      <p className="mb-3 text-xs text-muted-foreground">Cliquez un créneau pour y fixer une équipe (verrou HARD). Le récapitulatif des réservations est dans l'étape « Récap ».</p>

      <div className="mb-3 flex items-center gap-2">
        <span className="text-xs font-medium text-muted-foreground">Gymnase</span>
        <VenueSwatch color={selected.color ?? "transparent"} className="size-3 border border-border" />
        <Select
          aria-label="Gymnase"
          className="h-8 w-48"
          value={selected.id}
          onChange={(e) => {
            setActiveSlot(null); // never leave a modal open on a slot from the previous venue
            setVenueId(e.target.value);
          }}
        >
          {venues.map((v) => (
            <option key={v.id} value={v.id}>
              {v.name}
            </option>
          ))}
        </Select>
      </div>

      <ReservationGrid
        venue={selected}
        slots={venueSlots}
        reservedTeams={reservedNames}
        slotKeyOf={(s) => slotKey(s.venueId, s.dayOfWeek, s.startTime)}
        capacityOf={(s) => effectiveSlotCapacity(s, venueCanSplit)}
        onSelectSlot={setActiveSlot}
      />

      {null !== activeSlot ? (
        <SlotReservationModal
          slot={activeSlot}
          venue={selected}
          teams={teams}
          tiers={tiers}
          reservations={reservations}
          venueCanSplit={venueCanSplit}
          schedulePlanId={schedulePlanId}
          onClose={() => setActiveSlot(null)}
        />
      ) : null}
    </div>
  );
}
