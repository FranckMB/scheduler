import type { Reservation, VenueTrainingSlot } from "../api";
import { hhmm } from "./days";

/** A reservation and a slot refer to the same physical time-slot when venue +
 *  day + start (normalised to HH:MM) match. Reservations store start as HH:MM;
 *  slots may carry seconds or an ISO datetime, so normalise both sides. */
const slotKey = (venueId: string, dayOfWeek: number, startTime: string): string => `${venueId}|${dayOfWeek}|${hhmm(startTime)}`;

/**
 * Slots still reservable for `teamId` in the "Réserver" tab. A slot is offered
 * until its team-capacity is filled by DISTINCT teams — `capacity` when the gym
 * is divisible, otherwise 1 (a non-divisible gym takes a single team per slot).
 * A slot already reserved by this very team is dropped too (no double-booking).
 * So a capacity-2 slot booked by one team stays available for exactly one other.
 */
export function availableReservationSlots(
  slots: VenueTrainingSlot[],
  reservations: Reservation[],
  venueCanSplit: Map<string, boolean>,
  teamId: string,
): VenueTrainingSlot[] {
  const reservedTeamsBySlot = new Map<string, Set<string>>();
  for (const r of reservations) {
    const key = slotKey(r.venueId, r.dayOfWeek, r.startTime);
    const set = reservedTeamsBySlot.get(key) ?? new Set<string>();
    set.add(r.teamId);
    reservedTeamsBySlot.set(key, set);
  }

  return slots.filter((slot) => {
    const teamsOnSlot = reservedTeamsBySlot.get(slotKey(slot.venueId, slot.dayOfWeek, slot.startTime)) ?? new Set<string>();
    if (teamsOnSlot.has(teamId)) {
      return false;
    }
    const allowed = true === venueCanSplit.get(slot.venueId) ? slot.capacity : 1;
    return teamsOnSlot.size < allowed;
  });
}
