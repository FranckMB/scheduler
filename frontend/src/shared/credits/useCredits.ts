import { useMe } from "@/features/auth/queries";
import type { ClubEntitlements } from "@/features/auth/api";

/**
 * Le solde de crédits de sortie, prêt à afficher — ou `null` quand il n'y a rien
 * à montrer (offre payante/bêta/démo, ou entitlements pas encore résolus).
 *
 * ⚠ Le front ne DÉCIDE rien (règle P2-8) : `canGenerate/canPlaceMatches/
 * canExportPdf` sont les verdicts SERVEUR, repris tels quels ; `remaining` n'est
 * qu'un affichage dérivé (`creditsMax - creditsUsed`, borné à 0). C'est le serveur,
 * pas ce calcul, qui autorise ou refuse une sortie.
 */
export interface CreditsView {
  max: number;
  used: number;
  remaining: number;
  canGenerate: boolean;
  canPlaceMatches: boolean;
  canExportPdf: boolean;
}

/**
 * Règle pure (testable hors React) : `creditsMax === null` = régime NON bridé
 * (payant/bêta/démo) → pas de compteur du tout. Sinon on projette le solde.
 */
export function deriveCredits(entitlements: ClubEntitlements | undefined): CreditsView | null {
  if (undefined === entitlements || null === entitlements.creditsMax) {
    return null;
  }
  return {
    max: entitlements.creditsMax,
    used: entitlements.creditsUsed,
    remaining: Math.max(0, entitlements.creditsMax - entitlements.creditsUsed),
    canGenerate: entitlements.canGenerate,
    canPlaceMatches: entitlements.canPlaceMatches,
    canExportPdf: entitlements.canExportPdf,
  };
}

/** Solde de crédits du club Découverte bridé (null en payant/bêta/démo). */
export function useCredits(): CreditsView | null {
  const { data: me } = useMe();
  return deriveCredits(me?.club?.entitlements);
}
