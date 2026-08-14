/**
 * Helpers PURS pour AFFICHER les fermetures de gymnase d'une période (P2-22 PR 2).
 *
 * 🔴 Le front n'invente RIEN ici (régime 1, `.claude/rules/frontend.md`) : `weekdays` est
 * calculé SERVEUR (jours ISO fermés ∩ fenêtre, même sémantique que le retrait des créneaux
 * du payload solveur). `isSlotClosed` LIT cette donnée — c'est une appartenance, pas une
 * dérivation de dates. Ces fonctions ne décident d'aucun comportement métier : elles
 * regroupent, testent l'appartenance et fabriquent des libellés.
 */
import type { Closure } from "@/features/cockpit/api";

/** Abréviations fr des jours ISO (index 0 vide EXPRÈS — le jour 0 n'existe pas). */
const DAY_ABBR: readonly string[] = ["", "lun", "mar", "mer", "jeu", "ven", "sam", "dim"];

/** Date « j/m » sans zéro ni année : "2026-05-01" → "1/5". */
function frDayMonth(iso: string): string {
  const [, m, d] = iso.split("-").map(Number);
  return `${d}/${m}`;
}

/** Les fermetures indexées par gymnase. Une même fermeture ne vise qu'un gymnase (venueId). */
export function closuresByVenue(closures: Closure[]): Map<string, Closure[]> {
  const byVenue = new Map<string, Closure[]>();
  for (const closure of closures) {
    byVenue.set(closure.venueId, [...(byVenue.get(closure.venueId) ?? []), closure]);
  }
  return byVenue;
}

/** La fermeture qui touche ce créneau (son jour ISO ∈ weekdays), ou null. Grain JOUR strict. */
export function closureForSlot(slot: { dayOfWeek: number }, closuresOfVenue: Closure[]): Closure | null {
  return closuresOfVenue.find((c) => c.weekdays.includes(slot.dayOfWeek)) ?? null;
}

/** Ce créneau tombe-t-il un jour fermé ? Simple lecture de `weekdays`, jamais un calcul de dates. */
export function isSlotClosed(slot: { dayOfWeek: number }, closuresOfVenue: Closure[]): boolean {
  return null !== closureForSlot(slot, closuresOfVenue);
}

/** Libellé compact d'une fermeture : « Indispo du 1/5 au 10/5 — Travaux » (grilles, D1). */
export function closureLabel(closure: Closure): string {
  return `Indispo du ${frDayMonth(closure.startDate)} au ${frDayMonth(closure.endDate)} — ${closure.title}`;
}

/** Les jours fermés abrégés : « ven–dim » (plage contiguë) ou « lun, mer, ven ». */
function abbrevDays(weekdays: number[]): string {
  const sorted = [...weekdays].sort((a, b) => a - b);
  const contiguous = sorted.every((d, i) => 0 === i || d === sorted[i - 1] + 1);
  if (sorted.length > 1 && contiguous) {
    return `${DAY_ABBR[sorted[0]]}–${DAY_ABBR[sorted[sorted.length - 1]]}`;
  }
  return sorted.map((d) => DAY_ABBR[d] ?? `jour ${d}`).join(", ");
}

/**
 * Libellé au grain JOUR pour le panneau de période (D3) : « Indispo ven–dim du 1/5 au 10/5
 * — Travaux ». Quand les 7 jours de la semaine sont fermés → « Indispo toute la période du
 * X au Y — titre ».
 */
export function closurePeriodLabel(closure: Closure): string {
  const span = 7 === closure.weekdays.length ? "toute la période" : abbrevDays(closure.weekdays);
  return `Indispo ${span} du ${frDayMonth(closure.startDate)} au ${frDayMonth(closure.endDate)} — ${closure.title}`;
}
