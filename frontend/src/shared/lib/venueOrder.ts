/**
 * L'ordre d'affichage des gymnases — alphabétique, à la FRANÇAISE.
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
export function sortVenuesByName<T extends { name: string }>(venues: T[]): T[] {
  return [...venues].sort((a, b) => a.name.localeCompare(b.name, "fr", { sensitivity: "base" }));
}
