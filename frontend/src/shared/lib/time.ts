/**
 * Minutes depuis minuit → « HH:MM ». Foyer UNIQUE (audit D-20, 2026-08-08).
 *
 * Il en existait **trois** implémentations, et une seule clampait :
 *  - `matches/lib/weekendGrid.ts` clampait modulo 1440 ;
 *  - `planning/lib/grid.ts` et `wizard/lib/days.ts` ne clampaient pas.
 *
 * Conséquence : un horaire dépassant minuit s'affichait « 01:15 » côté matchs et
 * **« 25:15 »** côté planning et wizard. Ce n'est pas une hypothèse — c'est l'incident
 * nommément décrit dans `wizard/lib/slotOverlap.ts` (« ressortait en 25:15 partout où une
 * fin d'horaire s'affiche »), corrigé à l'époque par une garde de SAISIE ; les formateurs,
 * eux, sont restés discordants.
 *
 * ⚑ Le clamp n'est pas cosmétique : au-delà de minuit, « 25:15 » n'est pas une heure. Dans
 * le régime normal (< 1440) il ne change strictement rien — la garde de saisie empêche déjà
 * un créneau de franchir minuit — il ne se voit donc que là où quelque chose a déjà dérapé,
 * et il choisit alors d'afficher une heure lisible plutôt qu'un nombre impossible.
 */
export function formatMinutes(total: number): string {
  // Double modulo : `%` garde le signe en JS, donc -30 rendrait « -1:-30 » sans le second tour.
  const clamped = ((total % 1440) + 1440) % 1440;

  return `${String(Math.floor(clamped / 60)).padStart(2, "0")}:${String(clamped % 60).padStart(2, "0")}`;
}
