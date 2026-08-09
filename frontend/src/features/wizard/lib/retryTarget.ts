/**
 * AUD-FRT-09 — « quelle version relancer ? », en une règle pure et testable.
 *
 * Le mode période réutilisait déjà l'overlay en vol ; le mode saison créait une version
 * neuve à CHAQUE clic sur « Réessayer ». Pas de corruption — l'unicité du socle est tenue
 * par un 409 serveur — mais un historique de versions FAILED que le gestionnaire doit
 * trier, et qui grossit précisément quand ça va mal.
 *
 * ⚑ Extraite du composant à dessein : montée dans `GenerateStep`, la règle n'était
 * observable que par les deux cas que l'écran sait produire, et « réutiliser TOUJOURS »
 * passait le test sans être vu (falsification F2). Une règle qu'on ne peut pas falsifier
 * n'est pas gardée.
 */
export function scheduleIdToReuse(options: {
  periodMode: boolean;
  status: string | null;
  scheduleId: string | null;
}): string | undefined {
  const { periodMode, status, scheduleId } = options;

  // Le mode période a son propre chemin (l'overlay de l'entrée), résolu par l'appelant.
  if (periodMode || null === scheduleId) {
    return undefined;
  }

  // ⚠ FAILED AVÉRÉ uniquement. Sur un timeout la génération peut ENCORE tourner côté
  // serveur : relancer le même identifiant buterait sur le verrou de club et
  // transformerait une attente en erreur. Un statut inconnu se traite comme un timeout.
  return "FAILED" === status ? scheduleId : undefined;
}
