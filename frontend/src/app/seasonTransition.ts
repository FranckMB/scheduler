/** Season year of an ISO date, using the July-15 pivot. */
export function seasonYearOf(iso: string): number {
  const year = Number(iso.slice(0, 4));

  return iso.slice(5, 10) >= "07-15" ? year : year - 1;
}
