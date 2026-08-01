/** dayOfWeek 1-7 (ISO: 1=Monday). Availability + constraints use this convention. */
export const DAYS: { n: number; label: string }[] = [
  { n: 1, label: "Lun" },
  { n: 2, label: "Mar" },
  { n: 3, label: "Mer" },
  { n: 4, label: "Jeu" },
  { n: 5, label: "Ven" },
  { n: 6, label: "Sam" },
  { n: 7, label: "Dim" },
];

export const dayLabel = (n: number): string => DAYS.find((d) => d.n === n)?.label ?? "?";

/** First HH:MM of a time-ish string ("18:00:00", "1970-01-01T18:00:00+00:00", "18:00"). */
export const hhmm = (time: string): string => time.match(/(\d{2}):(\d{2})/)?.[0] ?? time;

/** Minutes since midnight for a time-ish string (single source for the grid + overlap). */
export const toMinutes = (time: string): number => {
  const [h, m] = hhmm(time).split(":").map(Number);
  return h * 60 + m;
};

/** Minutes since midnight back to "HH:MM". */
export const fmtMinutes = (m: number): string => `${String(Math.floor(m / 60)).padStart(2, "0")}:${String(m % 60).padStart(2, "0")}`;

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
