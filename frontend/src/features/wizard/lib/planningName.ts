/**
 * Conforming default name for a planning (types-de-planning.md E6) — one source of truth,
 * used at generation. Only a FRESH create uses it; a regenerate keeps the edited name.
 *   - socle   → "Planning de la saison {saison}"
 *   - closure → "Ajustement {gym} du {jj/mm} au {jj/mm}"
 *   - holiday → "Planning de vacances de {nom} du {jj/mm} au {jj/mm}"
 */
export interface PlanningNameInput {
  periodMode: boolean;
  periodType?: string | null;
  entryTitle?: string | null;
  startDate?: string | null;
  endDate?: string | null;
  gymName?: string | null;
  seasonName?: string | null;
}

const day = (iso?: string | null): string => (iso ? new Date(iso).toLocaleDateString("fr-FR", { day: "2-digit", month: "2-digit" }) : "");

export function defaultPlanningName(input: PlanningNameInput): string {
  if (!input.periodMode) {
    return input.seasonName ? `Planning de la saison ${input.seasonName}` : `Planning ${new Date().toLocaleDateString("fr-FR")}`;
  }
  const window = input.startDate && input.endDate ? ` du ${day(input.startDate)} au ${day(input.endDate)}` : "";
  if ("closure" === input.periodType) {
    return input.gymName ? `Ajustement ${input.gymName}${window}` : `Ajustement${window}`;
  }
  if ("holiday" === input.periodType) {
    // Strip a leading "Vacances " from the entry title so the label isn't doubled.
    const label = (input.entryTitle ?? "").replace(/^vacances\s+/i, "").trim();
    return label ? `Planning de vacances de ${label}${window}` : `Planning de vacances${window}`;
  }
  return input.entryTitle ?? "Plan de période";
}
