import type { VenueTrainingSlot } from "../api";
import { formatDuration } from "@/shared/lib/duration";

import { dayLabel, fmtMinutes, hhmm, toMinutes } from "./days";

/**
 * Overlap guard (same gym): two slots on the SAME weekday may never share any
 * time. Divisibility is a within-slot capacity, not a licence to stack slots.
 * Returns the first conflicting slot, or null when the [start, start+duration)
 * window is free. `slots` should already exclude the slot being edited.
 */
export function findSlotConflict(slots: VenueTrainingSlot[], day: number, startTime: string, durationMinutes: number): VenueTrainingSlot | null {
  const start = toMinutes(startTime);
  const end = start + durationMinutes;
  return (
    slots.find((s) => {
      if (s.dayOfWeek !== day) {
        return false;
      }
      const sStart = toMinutes(s.startTime);
      return start < sStart + s.durationMinutes && sStart < end;
    }) ?? null
  );
}

/** Minutes dans une journée — un créneau ne peut pas franchir minuit. */
const DAY_END = 24 * 60;

/**
 * Un créneau doit finir dans SA journée.
 *
 * Personne ne gardait cette borne : `durationMinutes` n'a pas de maximum côté API
 * (`VenueTrainingSlotInput` ne porte qu'un `Range(min: 15)`) et le chevauchement, seule
 * vérification côté client, ne regarde que les créneaux VOISINS. Tant que la grille
 * s'arrêtait à 21:45 et les durées à 2h, le pire cas tombait à 23:45 et la question ne se
 * posait pas. P4-37 ayant ouvert les deux bouts (départ jusqu'à 22:45, durée jusqu'à
 * 2h30), « 22:45 + 2h30 » se posait en un clic, se persistait, et ressortait en
 * **« 25:15 »** partout où une fin d'horaire s'affiche — `divmod(1515, 60)` ne se plaint
 * de rien, côté front comme côté engine.
 *
 * Rend le message d'erreur, ou null si le créneau tient dans la journée.
 */
export function pastMidnightMessage(startTime: string, durationMinutes: number): string | null {
  if (toMinutes(startTime) + durationMinutes <= DAY_END) {
    return null;
  }

  return `Un créneau qui commence à ${hhmm(startTime)} ne peut pas durer ${formatDuration(durationMinutes)} : il finirait après minuit.`;
}

export const conflictMessage = (c: VenueTrainingSlot): string =>
  `Chevauchement avec le créneau ${dayLabel(c.dayOfWeek)} ${hhmm(c.startTime)}–${fmtMinutes(toMinutes(c.startTime) + c.durationMinutes)}. Les créneaux ne peuvent pas se superposer.`;
