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
  /** Aucune version terminée : le planning est OUVERT (en cours) — rien d'exportable, à reprendre. */
  isOpen: boolean;
  /** Clé de regroupement des versions — permet de remonter au plan (calendarEntryId) pour « Reprendre ». */
  schedulePlanId: string | null;
}

/**
 * Le socle porte le NOM de son plan (ADR-0002 inv. 12 — renommable) : l'afficher,
 * pas un libellé générique. `seasonPlanName` = `me.seasonPlan?.name` ; le fallback
 * ne sert qu'aux états sans plan chargé.
 */
export function seasonPlannings(schedules: Schedule[], seasonPlanName: string | null = null): PlanningRow[] {
  const rows: PlanningRow[] = [];
  const seasonLabel = seasonPlanName ?? "Planning principal";
  const seasonVersions = visibleSeasonPlans(schedules);
  const seasonMain = planRepresentative(seasonVersions);
  // Un planning sans version terminée reste VISIBLE (retour fondateur 2026-07-18) :
  // il est « en cours », le gestionnaire a une action à faire — on montre la
  // dernière version quelle qu'elle soit, export masqué par l'appelant (isOpen).
  const seasonShown = seasonMain ?? seasonVersions.at(-1) ?? null;
  if (null !== seasonShown) {
    rows.push({
      id: seasonShown.id,
      label: seasonLabel,
      status: seasonShown.status,
      isChosen: true === seasonShown.isChosen,
      isOverlay: false,
      isOpen: null === seasonMain,
      schedulePlanId: seasonShown.schedulePlanId,
    });
  }
  // ADR-0002 C4 : un planning secondaire = les versions d'un plan de période (schedulePlanId).
  const overlayPlanIds = [...new Set(schedules.filter((s) => !isSeasonPlanType(s.planType)).map((s) => s.schedulePlanId as string))];
  const periods: PlanningRow[] = [];
  for (const planId of overlayPlanIds) {
    const versions = visibleOverlayVersions(schedules, planId);
    const finished = planRepresentative(versions);
    const shown = finished ?? versions.at(-1) ?? null;
    if (null !== shown) {
      periods.push({
        id: shown.id,
        label: shown.name,
        status: shown.status,
        isChosen: true === shown.isChosen,
        isOverlay: true,
        isOpen: null === finished,
        schedulePlanId: planId,
      });
    }
  }
  periods.sort((a, b) => a.label.localeCompare(b.label));

  return [...rows, ...periods];
}

export function seasonPlanCounts(schedules: Schedule[]): { total: number; overlays: number } {
  const rows = seasonPlannings(schedules);
  return { total: rows.length, overlays: rows.filter((row) => row.isOverlay).length };
}
