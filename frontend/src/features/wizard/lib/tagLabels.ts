import type { TeamTag, TeamTagAxis } from "../api";
import { LEVEL_LABEL } from "./labels";

/**
 * GENRE + ÂGE display labels. The NIVEAU tags reuse LEVEL_LABEL (single source of
 * truth). The option VALUE stays the raw tag name (`tag:${name}`, the emitted
 * constraint targetTag is unchanged); only the shown text changes. Names absent
 * everywhere (U9…U21, a future custom tag) fall back to the raw name.
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
};

const LEVEL_LABELS: Record<string, string | undefined> = LEVEL_LABEL;

export function tagLabel(name: string): string {
  return TAG_LABELS[name] ?? LEVEL_LABELS[name] ?? name;
}

const AXIS_LABEL: Record<TeamTagAxis, string> = { GENRE: "Genre", NIVEAU: "Niveau", AGE: "Âge" };
const KNOWN_AXES: TeamTagAxis[] = ["GENRE", "NIVEAU", "AGE"];

export interface TagAxisGroup {
  label: string;
  tags: TeamTag[];
}

/**
 * Ordered axis sections for the constraint target picker: Genre, Niveau, Âge,
 * then a fallback « Autres » for any tag outside the three axes — null OR
 * undefined (API Platform omits null fields, and a stale cache may lack it), so
 * no tag is ever silently dropped. Each section is sorted by display label;
 * empty sections are removed.
 */
export function groupTagsByAxis(tags: TeamTag[]): TagAxisGroup[] {
  const byLabel = (a: TeamTag, b: TeamTag): number => tagLabel(a.name).localeCompare(tagLabel(b.name), "fr");
  const sections: TagAxisGroup[] = KNOWN_AXES.map((axis) => ({
    label: AXIS_LABEL[axis],
    tags: tags.filter((t) => t.axis === axis).sort(byLabel),
  }));
  sections.push({
    label: "Autres",
    tags: tags.filter((t) => null == t.axis || !KNOWN_AXES.includes(t.axis)).sort(byLabel),
  });
  return sections.filter((group) => group.tags.length > 0);
}
