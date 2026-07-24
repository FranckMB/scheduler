import { mondayOf } from "@/features/cockpit/lib/date";

/** Minimum d'une entrée calendrier pour cibler sa todo-list de doléances. */
interface EntryLike {
  id: string;
  parentEntryId: string | null;
  startDate: string;
  periodType: string | null;
}

/**
 * Résout la cible d'ouverture de la todo-list depuis l'entrée du wizard (feature #10).
 *
 * Les doléances vivent sur l'entrée MÈRE des vacances ; le wizard peut porter une SEMAINE
 * enfant. On remonte donc à la mère (`parentEntryId ?? id`) et on filtre la liste sur la
 * semaine du plan courant — plan de bloc (pas d'enfant) → pas de filtre, tout groupé.
 * Réservé aux vacances (`periodType === 'holiday'`).
 */
export function wishesMotherId(entry: EntryLike | null | undefined): string | null {
  if (!entry) {
    return null;
  }
  return entry.parentEntryId ?? entry.id;
}

export function wishesWeekFilter(entry: EntryLike | null | undefined): string | null {
  if (!entry || null === entry.parentEntryId) {
    return null;
  }
  return mondayOf(entry.startDate);
}

export function canOpenWishes(entry: EntryLike | null | undefined): boolean {
  return "holiday" === entry?.periodType;
}
