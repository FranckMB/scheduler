import type { TeamLevel } from "../api";

/** French label for each competition level — single source of truth (recap + constraint target picker). */
export const LEVEL_LABEL: Record<TeamLevel, string> = {
  ELITE: "Élite",
  NATIONAL: "National",
  REGIONAL: "Régional",
  PRE_REGION: "Pré-région",
  DEPARTEMENTAL: "Départemental",
  HONNEUR: "Honneur",
  PROMOTION: "Promotion",
  LOISIR_ADULTE: "Loisir adulte",
  LOISIR_JEUNE: "Loisir jeune",
};
