import type { EntryConflictsResponse } from "@/features/cockpit/api";
import { dayLabelLong } from "@/shared/lib/days";

/**
 * P2-43 volet (v) — les couples (gymnase, jour) FERMÉS dans l'état effectif SERVI par
 * `/calendar-entries/{id}/conflicts`. Prêts à MARQUER (jamais masquer, doctrine « on annonce, on
 * ne cache pas ») les fenêtres vides de la grille d'une période, au grain JOUR. Clé
 * `${venueId}|${jour ISO}` → résumé de fermeture (le texte affiché après « Fermé — »).
 *
 * 🔴 Règle d'or (`.claude/rules/frontend.md`) : le front LIT l'état servi
 * (`effectiveClosedWeekdays` + `fullyClosedVenueIds`), il ne recompose JAMAIS incident × masque —
 * la provenance (`manual` / `default-incident`) ne choisit qu'un LIBELLÉ, la décision « ce jour
 * est fermé » vient de la présence de la clé côté serveur. Grain JOUR : un gymnase fermé le seul
 * mercredi garde ses autres jours OFFERTS.
 *
 * `conflicts` absent (socle sans entrée de période, ou pas encore résolu) → map VIDE : rien de
 * marqué fermé (fail-OPEN sur l'AFFICHAGE — le fail-CLOSED sur l'OFFRE vit côté page/grille).
 */
export function computeClosedWindows(
  conflicts: EntryConflictsResponse | undefined,
  venueName: (venueId: string) => string,
): Map<string, string> {
  const map = new Map<string, string>();
  if (undefined === conflicts) {
    return map;
  }
  // Indisponibilité TOTALE : les 7 jours du gymnase sont fermés (indisponibilité déclarée).
  for (const venueId of conflicts.fullyClosedVenueIds) {
    for (let day = 1; day <= 7; day += 1) {
      map.set(`${venueId}|${day}`, `${venueName(venueId)} indisponible toute la période`);
    }
  }
  // Fermeture au grain JOUR, avec la provenance SERVIE (décoché à la main / indisponibilité déclarée).
  for (const [venueId, days] of Object.entries(conflicts.effectiveClosedWeekdays)) {
    for (const [dayStr, provenance] of Object.entries(days)) {
      const key = `${venueId}|${dayStr}`;
      if (map.has(key)) {
        continue; // l'indisponibilité totale prime — pas de double résumé sur le même couple
      }
      const cause = "manual" === provenance ? "décoché à la main" : "indisponibilité déclarée";
      map.set(key, `le ${dayLabelLong(Number(dayStr))} est fermé (${cause})`);
    }
  }
  return map;
}
