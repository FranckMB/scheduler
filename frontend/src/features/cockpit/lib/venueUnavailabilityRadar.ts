import type { CalendarEntry } from "../api";
import { daysUntil } from "./date";

/**
 * P4-68 (recadré par le fondateur, 2026-08-06) — quelles indisponibilités de
 * gymnase méritent une carte du radar.
 *
 * Le modèle du fondateur : « on donne une indispo, le gestionnaire crée un
 * planning overlay ; s'il ne le fait pas c'est que l'indispo n'influe en rien ou
 * qu'on ne veut pas la traiter. Le gestionnaire est responsable et on fait le
 * nécessaire pour qu'il soit ALERTÉ. » D'où le périmètre : on ALERTE au bon
 * moment et on ouvre le chemin (créer la fermeture → adapter) — on ne décore ni
 * les exports ni la grille, et on ne masque aucune séance.
 *
 * Trois bornes, chacune pour une raison :
 *  - **pas révolue** (`endDate >= today`) : une fermeture passée n'est plus une
 *    action, l'afficher userait le panneau jusqu'à le rendre invisible ;
 *  - **dans l'horizon** : au-delà, « c'est trop loin pour que je m'en occupe de
 *    suite » — même doctrine que les fériés et les vacances (P3-13) ;
 *  - **pas déjà couverte par une période** : si une fermeture/vacances couvre
 *    TOUTE la plage, elle porte déjà sa carte avec « Adapter »/« Voir » — deux
 *    cartes pour un seul geste, c'est le radar qui radote. Couverture PARTIELLE :
 *    on garde l'alerte, les jours hors période ne sont traités par personne.
 */
export interface RadarUnavailability {
  id: string;
  venueId: string;
  startDate: string;
  endDate: string;
  label: string | null;
}

export function unavailabilitiesToAlert(
  unavailabilities: RadarUnavailability[],
  entries: CalendarEntry[],
  today: string,
  horizonDays: number,
): RadarUnavailability[] {
  const periods = entries.filter((e) => "period" === e.kind);

  return unavailabilities
    .filter((u) => u.endDate >= today)
    .filter((u) => daysUntil(today, u.startDate) <= horizonDays)
    .filter((u) => !periods.some((p) => p.startDate <= u.startDate && p.endDate >= u.endDate))
    .sort((a, b) => (a.startDate === b.startDate ? a.venueId.localeCompare(b.venueId) : a.startDate.localeCompare(b.startDate)));
}
