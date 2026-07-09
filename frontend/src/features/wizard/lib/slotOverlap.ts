import type { VenueTrainingSlot } from "../api";
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

export const conflictMessage = (c: VenueTrainingSlot): string =>
  `Chevauchement avec le créneau ${dayLabel(c.dayOfWeek)} ${hhmm(c.startTime)}–${fmtMinutes(toMinutes(c.startTime) + c.durationMinutes)}. Les créneaux ne peuvent pas se superposer.`;
