/**
 * Le nom affiché d'un coach, et le libellé de son absence — foyer unique (D-33).
 *
 * ⚑ Trois formatages coexistaient, dont deux SANS `.trim()` : un coach sans nom de famille
 * (`lastName` est optionnel) s'affichait « Emerick » dans le wizard et « Emerick␣ » sur le
 * planning et le radar de conflits — espace final visible en badge et en infobulle. Et trois
 * replis différents désignaient le même vide (« Coach ? », « Coach », `null`).
 */
export const COACH_UNKNOWN = "Coach ?";

interface CoachLike {
  firstName?: string | null;
  lastName?: string | null;
}

/** « Prénom Nom », sans espace parasite quand une des deux moitiés manque. */
export const coachFullName = (coach: CoachLike | null | undefined): string =>
  null == coach ? COACH_UNKNOWN : `${coach.firstName ?? ""} ${coach.lastName ?? ""}`.trim() || COACH_UNKNOWN;
