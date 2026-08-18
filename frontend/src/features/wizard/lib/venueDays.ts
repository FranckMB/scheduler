/**
 * Helpers PURS pour l'ÉTAT JOUR d'un gymnase en période — indisponibilité INFORMATIVE
 * (décision fondateur 2026-08-18). La coche du plan fait foi, jour par jour.
 *
 * 🔴 Le front n'invente RIEN ici (régime 1, `.claude/rules/frontend.md`) : la COMPOSITION
 * (incident × masque manuel) est calculée SERVEUR (`PlanVenueClosures::effectiveStateForPlan`)
 * et servie telle quelle par `/calendar-entries/{id}/conflicts` → `effectiveClosedWeekdays`
 * (jour ISO fermé → provenance). Ces fonctions LISENT cet état et le masque STOCKÉ
 * (`VenuePeriodOverride.dayOverrides`, la ressource que l'écran édite) — jamais elles ne
 * recomposent l'état effectif.
 *
 * {@see computeDayMaskToggle} n'est pas une redérivation métier : c'est l'orchestration CRUD
 * d'une ressource SPARSE (même patron que l'upsert des `TeamPeriodOverride`), pas une décision
 * « ce jour est-il fermé ». La décision vient du serveur ; ici on écrit l'intention de l'utilisateur.
 */
import type { EntryConflictsResponse } from "@/features/cockpit/api";

export type DayProvenance = "manual" | "default-incident";
/** Masque manuel SPARSE : jour ISO (1..7) → OPEN|CLOSED. Clés string (JSON) ou number. */
export type DayMask = Record<string | number, "OPEN" | "CLOSED">;

/**
 * Bascule la coche d'un jour dans le masque manuel, en le gardant SPARSE :
 *  - le jour porte déjà une entrée manuelle → on la RETIRE (retour au défaut de l'incident) ;
 *  - sinon → on force l'OPPOSÉ de l'état effectif courant (fermé → OPEN, ouvert → CLOSED).
 *
 * `currentlyClosed` = l'état EFFECTIF servi (le jour ∈ `effectiveClosedWeekdays` du gymnase).
 * Rend un NOUVEAU masque (jamais muté en place).
 */
export function computeDayMaskToggle(mask: DayMask | null | undefined, weekday: number, currentlyClosed: boolean): DayMask {
  const next: DayMask = {};
  for (const [day, state] of Object.entries(mask ?? {})) {
    next[Number(day)] = state;
  }
  if (undefined !== next[weekday]) {
    delete next[weekday];

    return next;
  }
  next[weekday] = currentlyClosed ? "OPEN" : "CLOSED";

  return next;
}

/**
 * Les jours ISO fermés À LA MAIN pour un gymnase (provenance `manual`), lus de l'état effectif
 * servi. Le bandeau rappel les liste EN PLUS des indisponibilités déclarées (décision C).
 */
export function manualClosedWeekdays(effectiveClosedWeekdays: EntryConflictsResponse["effectiveClosedWeekdays"] | undefined, venueId: string): number[] {
  const map = effectiveClosedWeekdays?.[venueId] ?? {};

  return Object.entries(map)
    .filter(([, prov]) => "manual" === prov)
    .map(([day]) => Number(day))
    .sort((a, b) => a - b);
}

/** Les jours ISO ROUVERTS malgré l'indisponibilité (masque OPEN), lus du masque STOCKÉ. */
export function reactivatedWeekdays(mask: DayMask | null | undefined): number[] {
  return Object.entries(mask ?? {})
    .filter(([, state]) => "OPEN" === state)
    .map(([day]) => Number(day))
    .sort((a, b) => a - b);
}
