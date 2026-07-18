/** Cockpit calendar date helpers — pure, no date library. All dates are ISO Y-m-d. */

const MONTH_LABELS = ["Janvier", "Février", "Mars", "Avril", "Mai", "Juin", "Juillet", "Août", "Septembre", "Octobre", "Novembre", "Décembre"];

export const monthLabel = (month: number): string => MONTH_LABELS[month] ?? "";

/** Local Y-m-d (avoids the UTC shift of toISOString). */
export function toISODate(date: Date): string {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, "0");
  const d = String(date.getDate()).padStart(2, "0");
  return `${y}-${m}-${d}`;
}

export function todayISO(): string {
  return toISODate(new Date());
}

/** First and last ISO date covering the calendar grid for a given month (Monday-first, 6 weeks). */
export function monthWindow(year: number, month: number): { from: string; to: string } {
  const grid = buildMonthGrid(year, month);
  return { from: grid[0].iso, to: grid[grid.length - 1].iso };
}

export interface GridDay {
  iso: string;
  day: number;
  inMonth: boolean;
}

/**
 * A 6-row Monday-first grid of the month. Leading/trailing days spill from the
 * adjacent months so every row has 7 cells.
 */
export function buildMonthGrid(year: number, month: number): GridDay[] {
  const first = new Date(year, month, 1);
  // getDay(): 0=Sun..6=Sat → Monday-first offset (Mon=0 … Sun=6).
  const offset = (first.getDay() + 6) % 7;
  const start = new Date(year, month, 1 - offset);

  const days: GridDay[] = [];
  for (let i = 0; i < 42; i += 1) {
    const d = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
    days.push({ iso: toISODate(d), day: d.getDate(), inMonth: d.getMonth() === month });
  }
  return days;
}

/** Whether ISO date `d` falls within the inclusive [start, end] range (string compare is safe for Y-m-d). */
export function isWithin(d: string, start: string, end: string): boolean {
  return d >= start && d <= end;
}

/** ISO date `n` days after `iso`. */
export function addDays(iso: string, n: number): string {
  const [y, m, d] = iso.split("-").map(Number);
  return toISODate(new Date(y, m - 1, d + n));
}

/** Short French date for compact UI copy, e.g. "2026-12-19" → "19 déc. 2026". */
export function frDateShort(iso: string): string {
  const [y, m, d] = iso.split("-").map(Number);
  return new Date(y, m - 1, d).toLocaleDateString("fr-FR", { day: "numeric", month: "short", year: "numeric" });
}

/** Whole days from `from` to `to` (ISO), floored, negative if `to` is before `from`. */
export function daysUntil(from: string, to: string): number {
  const a = Date.parse(`${from}T00:00:00`);
  const b = Date.parse(`${to}T00:00:00`);
  return Math.round((b - a) / 86_400_000);
}

/**
 * Intersect an ISO [start, end] range with the season window; null when disjoint.
 * Une période de calendrier vit DANS sa saison (un planning couvre une saison) :
 * les vacances d'été chevauchent la frontière — on n'écrit jamais leurs jours
 * hors-saison dans le calendrier de la saison courante (revue #260 round 1).
 */
export function clampRangeToSeason(
  start: string,
  end: string,
  season: { startDate: string; endDate: string },
): { startDate: string; endDate: string } | null {
  const s = start > season.startDate ? start : season.startDate;
  const e = end < season.endDate ? end : season.endDate;
  return s <= e ? { startDate: s, endDate: e } : null;
}
