import type { Schedule } from "@/features/planning/api";
import { isSeasonPlanType, planRepresentative, visibleOverlayVersions, visibleSeasonPlans } from "@/features/planning/lib/versions";

import type { SchedulePlan } from "./api";

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
export function seasonPlannings(schedules: Schedule[], seasonPlanName: string | null = null, plans: SchedulePlan[] = []): PlanningRow[] {
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
  // Plans de période SANS AUCUNE version générée (retour fondateur 2026-07-19) :
  // un planning créé au picker mais pas encore généré doit rester visible — il est
  // « en cours », le gestionnaire a une action (Reprendre). `seasonPlannings` étant
  // piloté par les versions, ces plans n'avaient aucune ligne. `id` = plan.id (pas
  // de scheduleId : aucune version à consulter/exporter — la CTA sera « Reprendre »).
  const planIdsWithVersions = new Set(overlayPlanIds);
  for (const plan of plans) {
    // Seuls les plans de PÉRIODE overlayables (CLOSURE/HOLIDAY) portent un planning
    // secondaire ; un plan sans entrée ou déjà couvert par une version est ignoré.
    if (("CLOSURE" !== plan.type && "HOLIDAY" !== plan.type) || null === plan.calendarEntryId || planIdsWithVersions.has(plan.id)) {
      continue;
    }
    periods.push({
      id: plan.id,
      label: plan.name,
      status: "DRAFT",
      isChosen: false,
      isOverlay: true,
      isOpen: true,
      schedulePlanId: plan.id,
    });
  }
  periods.sort((a, b) => a.label.localeCompare(b.label));

  return [...rows, ...periods];
}

/** `openOverlays` : plannings secondaires SANS version terminée (en cours) — le
 *  sous-titre de la bannière les distingue pour ne pas laisser croire à un
 *  planning prêt (revue #260 round 2). */
export function seasonPlanCounts(schedules: Schedule[], plans: SchedulePlan[] = []): { total: number; overlays: number; openOverlays: number } {
  const rows = seasonPlannings(schedules, null, plans);
  const overlays = rows.filter((row) => row.isOverlay);
  return { total: rows.length, overlays: overlays.length, openOverlays: overlays.filter((row) => row.isOpen).length };
}
