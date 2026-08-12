import { WIZARD_STEPS, type WizardStepId } from "./steps";

/**
 * P2-25 — le wizard devient ADRESSABLE. Un problème (créneau plein, contrainte à corriger)
 * désigne un objet ET son lieu de correction : on y va par une URL `/wizard?step=<id>&<cible>`.
 * Ce module est le SEUL foyer du contrat d'URL — parsing, origines de retour, et la règle du
 * mode guidé (une étape verrouillée ne saute pas). Pur (aucun hook, aucun store) : testable
 * en isolation, c'est là que se prouve le « positionnement » et l'« atterrissage propre ».
 */

export const WIZARD_STEP_IDS: readonly WizardStepId[] = WIZARD_STEPS.map((s) => s.id);

export function isWizardStepId(value: string | null): value is WizardStepId {
  return null !== value && WIZARD_STEP_IDS.includes(value as WizardStepId);
}

export function wizardStepIndex(id: WizardStepId): number {
  return WIZARD_STEPS.findIndex((s) => s.id === id);
}

/**
 * L'origine d'un lien porte le RETOUR NOMMÉ (règle C fondateur) : on ne montre « ← Retour à … »
 * QUE si on est arrivé par un lien, et il nomme d'où l'on vient. Jeton court dans l'URL
 * (`?from=`) plutôt qu'une URL encodée dans l'URL — signet lisible, pas de contexte périmé.
 */
export type DeepLinkOriginToken = "reservation" | "planning";

export interface DeepLinkOrigin {
  token: DeepLinkOriginToken;
  /** Libellé du bouton de retour (contient déjà « Retour à … »). */
  label: string;
  /** Où le retour ramène. */
  returnTo: string;
}

const ORIGINS: Record<DeepLinkOriginToken, DeepLinkOrigin> = {
  // Lien A (wizard→wizard) : depuis la réservation vers la capacité du créneau, retour à l'onglet Réserver.
  reservation: { token: "reservation", label: "Retour à la réservation", returnTo: "/wizard?step=constraints&tab=reserve" },
  // Lien B (planning→wizard) : depuis un créneau du planning vers l'éditeur de contrainte, retour au planning.
  planning: { token: "planning", label: "Retour au planning", returnTo: "/planning" },
};

export function resolveOrigin(token: string | null): DeepLinkOrigin | null {
  return null !== token && Object.prototype.hasOwnProperty.call(ORIGINS, token) ? ORIGINS[token as DeepLinkOriginToken] : null;
}

export interface WizardDeepLink {
  /** Étape ciblée, ou null si le paramètre est absent/inconnu (→ atterrissage propre, aucun saut). */
  step: WizardStepId | null;
  origin: DeepLinkOrigin | null;
}

export function parseWizardDeepLink(params: URLSearchParams): WizardDeepLink {
  const rawStep = params.get("step");
  return {
    step: isWizardStepId(rawStep) ? rawStep : null,
    origin: resolveOrigin(params.get("from")),
  };
}

/**
 * Mode guidé : `WizardLayout` verrouille les étapes au-delà de `maxIndex` (l'invariant de
 * l'onboarding — on avance par « Suivant »). Décision fondateur : un lien vers une étape
 * verrouillée s'affiche DÉSACTIVÉ avec sa raison, jamais un saut qui casse l'invariant.
 * Retourne cette raison, ou null si l'étape est atteignable maintenant.
 */
export function stepLockReason(step: WizardStepId, opts: { guided: boolean; maxIndex: number }): string | null {
  if (!opts.guided) {
    return null;
  }
  if (wizardStepIndex(step) <= opts.maxIndex) {
    return null;
  }
  const blocker = WIZARD_STEPS[Math.min(opts.maxIndex, WIZARD_STEPS.length - 1)];
  return `Terminez d'abord l'étape « ${blocker.label} »`;
}

/** Construit une URL de deep-link vers une étape du wizard (cible + origine optionnelles). */
export function wizardDeepLinkHref(step: WizardStepId, params?: Record<string, string>, from?: DeepLinkOriginToken): string {
  const sp = new URLSearchParams();
  sp.set("step", step);
  for (const [key, value] of Object.entries(params ?? {})) {
    sp.set(key, value);
  }
  if (undefined !== from) {
    sp.set("from", from);
  }
  return `/wizard?${sp.toString()}`;
}
