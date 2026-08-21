/**
 * Lot C PR-2 — le registre d'abandon des TRAITEMENTS LONGS (rail de retouche move/place/dry-run).
 *
 * Le voile bloquant (`app/ActionVeil`) ne relâche JAMAIS un traitement long au chrono (relâcher
 * autoriserait un second déplacement concurrent) : sa seule échappatoire est un bouton
 * « Abandonner ». Le bouton vit dans le voile, la mutation vit ailleurs (react-query) — ce module
 * est le fil entre les deux. Chaque `mutationFn` longue enregistre son `AbortController` à son
 * départ et le retire au settle ; le bouton abort TOUT ce qui est enregistré.
 *
 * Registre module (pas un store) volontairement : il n'a pas d'état à rendre, seulement des
 * contrôleurs à aborter ; un abort est une opération, pas une valeur observée.
 */
const controllers = new Set<AbortController>();

/** Enregistre un nouveau contrôleur (au départ d'une mutationFn longue). Retourne-le pour passer
 *  son `signal` à la requête ky et le retirer au settle. */
export function registerLongAction(): AbortController {
  const controller = new AbortController();
  controllers.add(controller);
  return controller;
}

/** Retire un contrôleur du registre (au settle : succès, refus, ou abandon). Idempotent. */
export function unregisterLongAction(controller: AbortController): void {
  controllers.delete(controller);
}

/** Abandonne TOUS les traitements longs en vol (bouton « Abandonner » du voile). */
export function abortLongActions(): void {
  for (const controller of controllers) {
    controller.abort();
  }
}
