/**
 * L'ordre d'affichage des NOMS — alphabétique, à la FRANÇAISE. Gymnases, équipes, coachs :
 * un seul comparateur pour tout l'écran, pour qu'un nouvel écran hérite du bon ordre sans
 * y penser (décision fondateur 2026-08-18 : « le même comportement partout, comme ça pas de
 * surprise si un autre écran l'utilise »). Son jumeau serveur est `App\Support\FrenchNameOrder`,
 * qui trie les documents sortants (PDF, tableur) exactement pareil.
 *
 * Le serveur trie déjà la collection (`VenueStateProvider`, `LOWER(name)`), ce qui rend la
 * pagination déterministe. Mais ce tri-là compare des octets : « École » et « Étoile »
 * atterrissent APRÈS « Zola », parce qu'un caractère accentué vaut plus qu'un « z » en UTF-8.
 * Vérifié sur la base du projet — et un club français a des gymnases accentués.
 *
 * `localeCompare("fr")` range les accents à leur place. On l'applique ici, après que
 * `collectionAll` a ramené TOUTES les pages : le tri porte donc sur la liste complète, il ne
 * peut pas mentir sur une page partielle.
 *
 * C'est de la PRÉSENTATION, pas une règle métier : l'ordre n'autorise ni n'interdit rien.
 */
export function compareNamesFr(a: string, b: string): number {
  return a.localeCompare(b, "fr", { sensitivity: "base" });
}

/** Le même ordre, appliqué à une liste d'objets nommés. Ne mute pas la liste reçue (donnée de cache). */
export function sortByName<T extends { name: string }>(items: T[]): T[] {
  return [...items].sort((a, b) => compareNamesFr(a.name, b.name));
}
