/**
 * Shift an ISO date (Y-m-d) by a number of days, in local time (never
 * toISOString — the UTC shift can flip the day).
 */
export function shiftIsoDays(iso: string, days: number): string {
  const date = new Date(`${iso}T00:00:00`);
  date.setDate(date.getDate() + days);

  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
}

/**
 * Default suggestion for a re-dated event: one year later, SAME WEEKDAY —
 * 364 days = 52 weeks (a Saturday tournament stays on a Saturday).
 */
export const NEXT_SEASON_SHIFT_DAYS = 364;
