/**
 * Human-friendly slot duration: hours are more readable than raw minutes for a
 * manager. Under an hour stays in minutes ("30 min", "45 min"); from an hour up
 * it reads "1h", "1h15", "1h30", "2h", "2h15"… (minutes zero-padded).
 */
export function formatDuration(minutes: number): string {
  if (minutes < 60) {
    return `${minutes} min`;
  }
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  return 0 === m ? `${h}h` : `${h}h${String(m).padStart(2, "0")}`;
}
