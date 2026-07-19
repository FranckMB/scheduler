/** Season year of an ISO date, using the July-15 pivot. */
export function seasonYearOf(iso: string): number {
  const year = Number(iso.slice(0, 4));

  return iso.slice(5, 10) >= "07-15" ? year : year - 1;
}

/** Local Y-m-d (never toISOString — the UTC shift can flip the day). Partagé par la
 *  bannière de transition et le sélecteur de saison (gating date). */
export function localIso(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
}

interface SeasonBounds {
  startDate: string;
  endDate: string;
  isCurrent: boolean;
}

/**
 * Fenêtre UNIQUE « préparer la saison suivante » — source partagée par la bannière
 * d'anticipation ET l'item du sélecteur (revue D : les deux divergeaient). ANCRÉE
 * SUR AUJOURD'HUI (nudge même un club dormant avant CHAQUE pivot du 15 juillet).
 *
 * Ouvre à `fromMonthDay` de l'année du pivot à venir ; ferme à la FIN RÉELLE de la
 * saison courante (retour fondateur 2026-07-19) — ou au pivot du 15 juillet si la
 * saison courante n'est pas celle qui précède ce pivot (club dormant). Masquée si un
 * successeur existe déjà. Clamp anti-fenêtre-inversée : une saison qui finit AVANT
 * l'ouverture retombe sur le pivot (sinon aucune fenêtre → aucun nudge).
 *
 * `inWindow` = dans la plage de dates ; `successorExists` = un N+1 est déjà préparé.
 * Le MENU d'action reste cliquable sur `inWindow` seul (préparer 2× réutilise
 * gracieusement le brouillon existant via un 409 serveur — flux conçu, e2e) ; la
 * BANNIÈRE (nag) se masque en plus quand `successorExists`.
 *
 * @param fromMonthDay borne basse « MM-JJ » (menu = "05-01", bannière = "05-15").
 */
export function seasonPrepWindow(
  todayIso: string,
  seasons: SeasonBounds[],
  fromMonthDay: string,
): { inWindow: boolean; successorExists: boolean; deadline: string } {
  const anchorYear = seasonYearOf(todayIso);
  const pivotYear = anchorYear + 1;
  const pivotEnd = `${pivotYear}-07-15`;
  const successorExists = seasons.some((s) => seasonYearOf(s.startDate) > anchorYear);
  const current = seasons.find((s) => s.isCurrent) ?? null;
  const seasonEnd = null !== current && seasonYearOf(current.startDate) === anchorYear ? current.endDate : pivotEnd;
  const opensAt = `${pivotYear}-${fromMonthDay}`;
  const deadline = seasonEnd < opensAt ? pivotEnd : seasonEnd;
  const inWindow = todayIso >= opensAt && todayIso <= deadline;
  return { inWindow, successorExists, deadline };
}

/** « 15 juillet » — jour + mois FR d'une date ISO, pour le libellé de deadline. */
export function frDayMonth(iso: string): string {
  return new Date(`${iso}T12:00:00`).toLocaleDateString("fr-FR", { day: "numeric", month: "long" });
}
