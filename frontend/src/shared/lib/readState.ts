/**
 * « Cette lecture est-elle exploitable ? » — une seule réponse pour tout l'app.
 *
 * react-query expose des drapeaux TRANSITOIRES qu'on lit spontanément comme des
 * vérités établies, et c'est la source d'une famille entière de bugs (revues
 * #302/#303) :
 *
 *  - `isError` est vrai même quand un REFETCH D'ARRIÈRE-PLAN échoue alors que la
 *    donnée en cache est intacte. Le traiter comme fatal détruit un écran qui
 *    fonctionne, ou bloque une génération sur un incident réseau passager.
 *  - `data ?? []` pendant le premier chargement fabrique un VIDE CRÉDIBLE :
 *    « aucun créneau », « aucun réglage » — et pousse le gestionnaire à re-saisir
 *    (doublons) ou à valider une période qu'il croit vide.
 *
 * D'où trois états, et un seul critère : **ce qui compte est d'AVOIR une donnée**.
 *
 *  - `loading` — rien à montrer encore (premier chargement) ;
 *  - `failed`  — la lecture a échoué ET il n'y a rien en cache : le seul cas où
 *                un écran doit céder la place à un message d'erreur ;
 *  - `ready`   — on a une donnée, même périmée, même après un refetch raté.
 */
export type ReadState = "loading" | "failed" | "ready";

export function readState(query: { data: unknown; isError: boolean }): ReadState {
  if (undefined !== query.data && null !== query.data) {
    return "ready";
  }

  return query.isError ? "failed" : "loading";
}

/** Raccourci : la lecture a échoué sans rien à montrer (le seul échec qui doit s'afficher). */
export function readFailed(query: { data: unknown; isError: boolean }): boolean {
  return "failed" === readState(query);
}

/** Raccourci : premier chargement — ne JAMAIS rendre un vide dérivé de cet état. */
export function readLoading(query: { data: unknown; isError: boolean }): boolean {
  return "loading" === readState(query);
}
