/**
 * P2-44 (PR-2) — une séance du socle NON reprise par la transcription d'une période (« à
 * replacer »). SERVIE telle quelle par la route `POST /schedule_plans/{id}/transcribe-from-socle`
 * (réponse `PeriodTranscriptionResult`) : le front ne redérive RIEN — équipe, jour, heure, gymnase
 * d'origine et RAISON viennent tous du backend.
 */
export interface ToReplaceEntry {
  teamId: string;
  dayOfWeek: number;
  startTime: string;
  venueId: string;
  /** `venue_closed` | `venue_disabled` | `team_reduced` (ADR-0004) — servie par le backend. */
  reason: string;
}

/**
 * PRÉSENTATION pure (régime « libellé », cf. `matches/lib/diagnostic.ts`) : on TRADUIT une raison
 * déjà DÉCIDÉE par le serveur, on ne décide d'AUCUN comportement. `reason` n'est pas un enum de
 * contrainte (scope / ruleType / family) : ce switch est un choix de mot, pas une redérivation de
 * règle — il n'est donc pas un miroir à déclarer.
 */
export function toReplaceReasonLabel(reason: string): string {
  switch (reason) {
    case "venue_closed":
      return "Fermeture du gymnase";
    case "venue_disabled":
      return "Gymnase désactivé";
    case "team_reduced":
      return "Séances réduites";
    default:
      return "Non reprise";
  }
}
