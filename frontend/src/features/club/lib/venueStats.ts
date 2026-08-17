/**
 * Présentation des heures d'utilisation des gymnases. Le CALCUL vit désormais
 * côté serveur (P3-22, endpoint `GET /api/venue-usage-stats`) : ne reste ici que
 * le formatage d'affichage, seul morceau qui appartient au front.
 */

/** « 12 h » / « 7,5 h » — une décimale seulement quand elle existe, virgule française. */
export function formatHours(hours: number): string {
  const rounded = Math.round(hours * 10) / 10;

  return `${rounded.toLocaleString("fr-FR")} h`;
}
