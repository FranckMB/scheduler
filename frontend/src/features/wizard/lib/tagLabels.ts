import type { TeamTag, TeamTagAxis } from "../api";

/**
 * Human labels for the system tags — the option VALUE stays the raw tag name
 * (`tag:${name}`, the emitted constraint targetTag is unchanged); only the shown
 * text changes. Names absent here (U9…U21, or a future custom tag) fall back to
 * the raw name.
 */
const TAG_LABELS: Record<string, string> = {
  // GENRE
  MASCULINE: "Homme",
  FEMININE: "Femme",
  MIXTE: "Mixte",
  // ÂGE
  SENIOR: "Adulte",
  JEUNE: "Jeune (U13-U21)",
  EMB: "EMB (U9-U11)",
  // NIVEAU
  ELITE: "Élite",
  REGIONAL: "Régional",
  NATIONAL: "National",
  DEPARTEMENTAL: "Départemental",
  LOISIR_ADULTE: "Loisir adulte",
  LOISIR_JEUNE: "Loisir jeune",
  HONNEUR: "Honneur",
  PROMOTION: "Promotion",
  PRE_REGION: "Pré-région",
};

export function tagLabel(name: string): string {
  return TAG_LABELS[name] ?? name;
}

const AXIS_LABEL: Record<TeamTagAxis, string> = { GENRE: "Genre", NIVEAU: "Niveau", AGE: "Âge" };

export interface TagAxisGroup {
  label: string;
  tags: TeamTag[];
}

/**
 * Ordered axis sections for the constraint target picker: Genre, Niveau, Âge,
 * then a fallback « Autres » for any null-axis tag (none today). Each section is
 * sorted by display label; empty sections are dropped.
 */
export function groupTagsByAxis(tags: TeamTag[]): TagAxisGroup[] {
  const order: (TeamTagAxis | null)[] = ["GENRE", "NIVEAU", "AGE", null];
  return order
    .map((axis) => ({
      label: null === axis ? "Autres" : AXIS_LABEL[axis],
      tags: tags.filter((t) => t.axis === axis).sort((a, b) => tagLabel(a.name).localeCompare(tagLabel(b.name), "fr")),
    }))
    .filter((group) => group.tags.length > 0);
}
