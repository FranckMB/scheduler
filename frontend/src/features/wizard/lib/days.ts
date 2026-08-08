/** dayOfWeek 1-7 (ISO: 1=Monday). Availability + constraints use this convention. */
// D-22 : les sept jours vivent en `shared/lib/days` — une copie de ce tableau
// s'arrêtait au samedi et rendait un planning à six colonnes « complet ».
import { parseTime } from "@/shared/lib/time";
import { DAYS } from "@/shared/lib/days";

export { DAYS };

export const dayLabel = (n: number): string => DAYS.find((d) => d.n === n)?.label ?? "?";

/** First HH:MM of a time-ish string ("18:00:00", "1970-01-01T18:00:00+00:00", "18:00"). */
export const hhmm = (time: string): string => time.match(/(\d{2}):(\d{2})/)?.[0] ?? time;

/** Minutes since midnight for a time-ish string (single source for the grid + overlap). */
export const toMinutes = (time: string): number => {
  // D-21 — la LECTURE est partagée, le repli reste local et VOULU : `NaN` est load-bearing.
  // `slotOverlap` teste `!Number.isFinite(toMinutes(...))` pour refuser une heure vide en
  // nommant le champ ; rendre 0 ici rendrait cette garde muette et laisserait partir une
  // écriture que l'API rejette par un 422 générique.
  return parseTime(time) ?? Number.NaN;
};

/** Minutes depuis minuit → "HH:MM" — foyer unique `shared/lib/time` (D-20 : cette copie rendait « 25:15 »). */
export { formatMinutes as fmtMinutes } from "@/shared/lib/time";

/** Durées proposées pour un créneau (min) — éditeurs de saison et de période. */
// 45 min → 2h30 par pas de 15 (retour terrain 2026-07-31 : la liste s'arrêtait à 1h→2h,
// alors qu'un club pose des séances courtes de jeunes comme des créneaux longs de senior).
export const DURATIONS = [45, 60, 75, 90, 105, 120, 135, 150];

/**
 * Les durées à OFFRIR dans un éditeur, la valeur courante comprise.
 *
 * Un select ne peut pas afficher une valeur qu'il n'offre pas : sans cela, un créneau dont
 * la durée ne figure pas dans la liste (donnée antérieure à l'élargissement de P4-37,
 * import, écriture directe — l'API n'impose qu'un `Range(min: 15)`) s'ouvrait sur un champ
 * VIDE, exactement comme le select « Jour » face à un dimanche. CHOISIR se restreint,
 * NOMMER jamais : la liste offerte se complète de ce qui est déjà là.
 */
export function durationOptions(...present: number[]): number[] {
  // ⚠ Variadique, et non « la valeur courante » : appelée avec le seul état du select, elle
  // retirait l'option d'origine dès le premier changement — le gestionnaire ouvrait un
  // créneau de 30 min, déroulait pour comparer, et ne pouvait PLUS revenir à 30. Il faut
  // donc lui passer la valeur STOCKÉE autant que la valeur éditée.
  const extra = present.filter((d) => Number.isFinite(d) && d > 0 && !DURATIONS.includes(d));

  return 0 === extra.length ? DURATIONS : [...new Set([...DURATIONS, ...extra])].sort((a, b) => a - b);
}
