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

/** "18:00:00" | "18:00" → "18:00". */
export const hhmm = (time: string): string => time.slice(0, 5);
