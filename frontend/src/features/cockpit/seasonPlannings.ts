import type { Schedule } from "@/features/planning/api";
import { isSeasonPlanType, planRepresentative, visibleOverlayVersions, visibleSeasonPlans } from "@/features/planning/lib/versions";

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
  const seasonMain = planRepresentative(visibleSeasonPlans(schedules));
  if (null !== seasonMain) {
    rows.push({ id: seasonMain.id, label: "Planning principal", status: seasonMain.status, isChosen: true === seasonMain.isChosen, isOverlay: false });
  }
  // ADR-0002 C4 : un planning secondaire = les versions d'un plan de période (schedulePlanId).
  const overlayPlanIds = [...new Set(schedules.filter((s) => !isSeasonPlanType(s.planType)).map((s) => s.schedulePlanId as string))];
  const periods: PlanningRow[] = [];
  for (const planId of overlayPlanIds) {
    const version = planRepresentative(visibleOverlayVersions(schedules, planId));
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
