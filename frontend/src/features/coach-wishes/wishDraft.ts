import { cloneSections, type SectionState } from "./wishSections";

/**
 * Filet LOCAL (sessionStorage) du brouillon de doléances — jamais serveur. La décision
 * « pas de sauvegarde partielle » reste intacte : sessionStorage meurt avec l'onglet.
 * Restauré au montage, PURGÉ au succès de l'envoi. Clé par token.
 */
interface SerializedDraft {
  sections: Record<string, { slotsWanted: number; days: number[]; comment: string }>;
  stepIndex: number;
}

export interface WishDraft {
  sections: Map<string, SectionState>;
  stepIndex: number;
}

const key = (token: string): string => `amateo:wish-draft:${token}`;

/**
 * L'ancienne clé, du temps où le produit s'appelait autrement (P5-15). Elle n'est plus ÉCRITE,
 * seulement LUE en repli : un coach qui remplissait ses vœux au moment du déploiement garde son
 * brouillon. La fenêtre est étroite (sessionStorage meurt avec l'onglet), mais perdre la saisie
 * d'un bénévole pour une question de nom serait un mauvais échange. À retirer après une saison.
 */
const legacyKey = (token: string): string => `clubscheduler:wish-draft:${token}`;

export function loadDraft(token: string): WishDraft | null {
  try {
    const raw = sessionStorage.getItem(key(token)) ?? sessionStorage.getItem(legacyKey(token));
    if (null === raw) {
      return null;
    }
    const parsed = JSON.parse(raw) as SerializedDraft;
    const sections = new Map<string, SectionState>();
    for (const [k, v] of Object.entries(parsed.sections)) {
      sections.set(k, { slotsWanted: v.slotsWanted, days: new Set(v.days), comment: v.comment });
    }
    return { sections, stepIndex: parsed.stepIndex };
  } catch {
    return null;
  }
}

export function saveDraft(token: string, sections: Map<string, SectionState>, stepIndex: number): void {
  try {
    const snapshot = cloneSections(sections);
    const serialized: SerializedDraft = { sections: {}, stepIndex };
    for (const [k, v] of snapshot) {
      serialized.sections[k] = { slotsWanted: v.slotsWanted, days: [...v.days], comment: v.comment };
    }
    sessionStorage.setItem(key(token), JSON.stringify(serialized));
  } catch {
    // sessionStorage indisponible (mode privé strict) : le filet est optionnel.
  }
}

export function clearDraft(token: string): void {
  try {
    sessionStorage.removeItem(key(token));
    sessionStorage.removeItem(legacyKey(token));
  } catch {
    // Idem : best-effort.
  }
}
