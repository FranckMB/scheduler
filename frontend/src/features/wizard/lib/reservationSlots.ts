import { groupTeamsByTier, type TierLike } from "@/shared/lib/teamTiers";

import type { Reservation, Team, VenueTrainingSlot } from "../api";
import { hhmm } from "./days";

/** A reservation and a slot refer to the same physical time-slot when venue +
 *  day + start (normalised to HH:MM) match. Reservations store start as HH:MM;
 *  slots may carry seconds or an ISO datetime, so normalise both sides. */
export const slotKey = (venueId: string, dayOfWeek: number, startTime: string): string => `${venueId}|${dayOfWeek}|${hhmm(startTime)}`;

/**
 * Teams reserved on each slot (by slotKey → list of teamIds, insertion order).
 * Drives the grid badges and tells the modal who is already on a slot.
 */
export function reservedTeamsBySlot(reservations: Reservation[]): Map<string, string[]> {
  const map = new Map<string, string[]>();
  for (const r of reservations) {
    const key = slotKey(r.venueId, r.dayOfWeek, r.startTime);
    map.set(key, [...(map.get(key) ?? []), r.teamId]);
  }
  return map;
}

/** How many reservations each team currently holds (its progress toward its ceiling). */
export function teamReservationCount(reservations: Reservation[]): Map<string, number> {
  const counts = new Map<string, number>();
  for (const r of reservations) {
    counts.set(r.teamId, (counts.get(r.teamId) ?? 0) + 1);
  }
  return counts;
}

/**
 * How many DISTINCT teams a slot accepts: `capacity` on a divisible gym, else 1.
 * When the venue is not (yet) loaded we trust `slot.capacity` — the backend forces
 * it to 1 for non-divisible gyms — so a lagging venues query can't hide a seat.
 */
export function effectiveSlotCapacity(slot: VenueTrainingSlot, venueCanSplit: Map<string, boolean>): number {
  return false === venueCanSplit.get(slot.venueId) ? 1 : slot.capacity;
}

/** Ce que la modale de confirmation « décocher terrain divisible » doit montrer et vider. */
export interface SplitCascadePreview {
  /** Les créneaux du gymnase à capacité ≥ 2 — ceux qui repasseront à 1 équipe. */
  slots: VenueTrainingSlot[];
  /** Combien de réservations posées sur ces créneaux seront vidées. */
  reservationCount: number;
}

/**
 * v2 cohérence canSplit — décocher « terrain divisible » sur un gymnase dont des créneaux
 * accueillent 2 équipes ou plus est une cascade destructive : ces créneaux repassent à 1 et
 * leurs réservations sont vidées (backend `VenueStateProcessor`). La modale AVERTIT avec ces
 * deux nombres AVANT de confirmer. Fonction pure : le test la falsifie sans monter l'écran.
 * Vide (`slots: []`) ⇒ décocher est cohérent, pas de modale.
 */
export function splitCascadePreview(slots: VenueTrainingSlot[], reservations: Reservation[], venueId: string): SplitCascadePreview {
  const affected = slots.filter((s) => s.venueId === venueId && s.capacity >= 2);
  const keys = new Set(affected.map((s) => slotKey(s.venueId, s.dayOfWeek, s.startTime)));
  const reservationCount = reservations.filter((r) => keys.has(slotKey(r.venueId, r.dayOfWeek, r.startTime))).length;
  return { slots: affected, reservationCount };
}

/** Un créneau partagé et l'état de ses réservations, pour les avertissements du récap. */
export interface SharedSlotStatus {
  slot: VenueTrainingSlot;
  /** Équipes réservées sur le créneau (ordre d'insertion). */
  reservedTeamIds: string[];
  /** Capacité EFFECTIVE (canSplit appliqué) — toujours ≥ 2 ici. */
  capacity: number;
  /**
   * `unreserved` — personne n'a choisi : le système associera les équipes lui-même
   * (information, pas un défaut). `partial` — réservé en dessous de la capacité :
   * ALIGN-07 fait qu'une réservation ferme le créneau ENTIER au système, la ou les
   * places restantes resteront VIDES (avertissement, la conséquence est une perte).
   */
  kind: "unreserved" | "partial";
}

/**
 * Les créneaux partagés (capacité effective ≥ 2) qui méritent un mot au récap : non
 * réservés ou PARTIELLEMENT réservés. Un créneau plein n'apparaît pas. Règle extraite en
 * fonction pure (§7.2) : le test la falsifie sans monter l'écran.
 */
export function sharedSlotStatuses(
  slots: VenueTrainingSlot[],
  reservations: Reservation[],
  venueCanSplit: Map<string, boolean>,
): SharedSlotStatus[] {
  const bySlot = reservedTeamsBySlot(reservations);
  const statuses: SharedSlotStatus[] = [];
  for (const slot of slots) {
    const capacity = effectiveSlotCapacity(slot, venueCanSplit);
    if (capacity < 2) {
      continue;
    }
    const reservedTeamIds = bySlot.get(slotKey(slot.venueId, slot.dayOfWeek, slot.startTime)) ?? [];
    if (reservedTeamIds.length >= capacity) {
      continue;
    }
    statuses.push({ slot, reservedTeamIds, capacity, kind: 0 === reservedTeamIds.length ? "unreserved" : "partial" });
  }
  return statuses;
}

/**
 * Teams the manager may still assign to `slot`, in canonical rank order (fanion
 * S → A → B → C → D). Excludes: teams already on the slot, and teams that reached
 * their ceiling of `sessionsPerWeek` reservations (a team at N sessions with N
 * reservations disappears everywhere). Returns [] when the slot is already full,
 * so the modal never offers a seat that doesn't exist.
 */
export function assignableTeams(
  teams: Team[],
  tiers: TierLike[],
  slot: VenueTrainingSlot,
  reservations: Reservation[],
  venueCanSplit: Map<string, boolean>,
): Team[] {
  const onSlot = new Set(reservedTeamsBySlot(reservations).get(slotKey(slot.venueId, slot.dayOfWeek, slot.startTime)) ?? []);
  if (onSlot.size >= effectiveSlotCapacity(slot, venueCanSplit)) {
    return [];
  }
  const counts = teamReservationCount(reservations);
  const rankOrdered = groupTeamsByTier(teams, tiers).flatMap((group) => group.teams);
  return rankOrdered.filter((team) => !onSlot.has(team.id) && (counts.get(team.id) ?? 0) < team.sessionsPerWeek);
}
