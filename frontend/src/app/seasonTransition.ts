/** Season year of an ISO date, using the July-15 pivot. */
export function seasonYearOf(iso: string): number {
  const year = Number(iso.slice(0, 4));

  return iso.slice(5, 10) >= "07-15" ? year : year - 1;
}

/** Local Y-m-d (never toISOString — the UTC shift can flip the day). Partagé par la
 *  bannière de transition et le sélecteur de saison (gating date). */
export function localIso(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
}
