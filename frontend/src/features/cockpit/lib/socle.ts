import { useMe } from "@/features/auth/queries";

/**
 * « Socle validé » = le plan de la SAISON pointe une version (chosenScheduleId).
 * Tant qu'il ne l'est pas, on ne peut pas créer/générer un planning secondaire
 * (#5). Source UNIQUE partagée par le cockpit (radar, modale jour, bannière) —
 * évite de retripler la dérivation et le libellé (revue B1 F6).
 */
export function useSocleValidated(): boolean {
  const { data: me } = useMe();
  return null != me?.seasonPlan?.chosenScheduleId;
}

/** Bulle d'info d'un bouton d'ajustement bloqué faute de socle validé. */
export const SEASON_LOCK_TITLE = "Le planning de la saison n'est pas encore validé — validez-le pour ajuster.";

/** `undefined` si le socle est validé (pas de bulle), sinon le message de blocage. */
export function seasonLockTitle(socleValidated: boolean): string | undefined {
  return socleValidated ? undefined : SEASON_LOCK_TITLE;
}
