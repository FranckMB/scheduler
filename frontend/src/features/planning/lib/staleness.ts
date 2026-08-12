/**
 * Un planning « périmé » : le message UNIFIÉ de sa (ses) cause(s).
 *
 * Deux déclencheurs distincts rendent un planning obsolète sans le rendre FAUX :
 *  - il a été retouché à la main depuis sa génération (le score ne décrit plus le placement) ;
 *  - une contrainte a changé depuis sa génération (il décrit un état antérieur des règles).
 *
 * ⚠ Une SEULE bannière, jamais deux empilées : le gestionnaire finirait par les ignorer
 * toutes. Elle NOMME sa ou ses causes, et l'action proposée dépend de l'état du planning :
 *  - un planning modifiable → « Régénérez » ;
 *  - un planning VALIDÉ / en vigueur est en lecture seule (le rail refuse l'écriture) →
 *    proposer « Régénérer » l'enverrait dans un mur. On dit alors « Rouvrez… puis régénérez »,
 *    l'enchaînement réel du cycle de vie (ADR-0002 : valider / rouvrir).
 *
 * Le mot est choisi : périmé, PAS faux. Le planning décrit un état antérieur des données ;
 * on régénère pour SAVOIR s'il tient encore, pas parce qu'il est invalide.
 *
 * Retourne `null` quand rien n'est périmé (aucune bannière).
 */
export function stalenessMessage(opts: {
  manuallyEdited: boolean;
  constraintsChanged: boolean;
  readOnly: boolean;
}): string | null {
  const { manuallyEdited, constraintsChanged, readOnly } = opts;
  if (!manuallyEdited && !constraintsChanged) {
    return null;
  }

  const action = readOnly ? "Rouvrez ce planning, puis régénérez" : "Régénérez";

  if (manuallyEdited && constraintsChanged) {
    return `Ce planning a été modifié à la main et une contrainte a changé depuis sa génération : il est périmé. ${action} pour le remettre à jour.`;
  }
  if (constraintsChanged) {
    return `Une contrainte a changé depuis la génération de ce planning : il décrit un état antérieur de vos règles — pas forcément faux, mais périmé. ${action} pour savoir s'il les respecte encore.`;
  }
  return `Ce planning a été modifié à la main depuis sa génération : le score affiché est périmé. ${action} pour un score à jour.`;
}
