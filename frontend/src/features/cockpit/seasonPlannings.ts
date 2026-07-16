import type { Schedule } from "@/features/planning/api";
import { representativeVersion, visibleOverlayVersions, visibleSeasonPlans } from "@/features/planning/lib/versions";

export interface PlanningRow {
  id: string;
  label: string;
  status: Schedule["status"];
  /** Le plan de ce planning pointe cette version : il est en vigueur (≠ « principal »). */
  isChosen: boolean;
  /** Planning secondaire (plan d'une période) ; sinon c'est LE planning principal de la saison. */
  isOverlay: boolean;
}

export function seasonPlannings(schedules: Schedule[]): PlanningRow[] {
  const rows: PlanningRow[] = [];
  const seasonMain = representativeVersion(visibleSeasonPlans(schedules));
  if (null !== seasonMain) {
    rows.push({ id: seasonMain.id, label: "Planning principal", status: seasonMain.status, isChosen: true === seasonMain.isChosen, isOverlay: false });
  }
  const periodIds = [...new Set(schedules.filter((s) => null !== s.calendarEntryId).map((s) => s.calendarEntryId as string))];
  const periods: PlanningRow[] = [];
  for (const entryId of periodIds) {
    const version = representativeVersion(visibleOverlayVersions(schedules, entryId));
    if (null !== version) {
      periods.push({ id: version.id, label: version.name, status: version.status, isChosen: true === version.isChosen, isOverlay: true });
    }
  }
  periods.sort((a, b) => a.label.localeCompare(b.label));

  return [...rows, ...periods];
}

export function seasonPlanCounts(schedules: Schedule[]): { total: number; overlays: number } {
  const rows = seasonPlannings(schedules);
  return { total: rows.length, overlays: rows.filter((row) => row.isOverlay).length };
}
