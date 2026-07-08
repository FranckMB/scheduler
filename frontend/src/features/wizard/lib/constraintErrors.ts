/**
 * The pre-solve constraint validator (backend App\Service\ConstraintValidationService)
 * returns terse English, config-oriented messages ("TIME family requires
 * maxStartTime…"). Those are meaningless to a club manager, so we map the known
 * ones to plain French here — the backend stays the authority on WHAT is wrong,
 * the frontend owns HOW it reads. Unknown strings fall through unchanged rather
 * than being hidden.
 */
const EXACT: Record<string, string> = {
  "TIME family requires maxStartTime or minStartTime in config.": "Une contrainte d'horaire doit préciser une heure de début minimale ou maximale.",
  "DAY family requires allowedDays, forbiddenDays or forcedDays in config.": "Une contrainte de jour doit préciser au moins un jour (autorisé, interdit ou imposé).",
  "FACILITY family requires venueId or targetTag in config.": "Une contrainte de gymnase doit cibler un gymnase.",
  "COACH_AVAILABILITY family requires coachId or targetTag in config.": "Une contrainte de disponibilité doit cibler un coach.",
  "FACILITY_CAPACITY family requires maxTeams in config.": "Une contrainte de capacité doit préciser un nombre maximum d'équipes.",
  "LOCK rule type is only valid for TIME or DAY family.": "Le verrouillage n'est possible que sur une contrainte d'horaire ou de jour.",
  "Scope CLUB should not have a scope_target_id.": "Une contrainte à l'échelle du club ne doit pas cibler une équipe précise.",
  "Contradictory day constraints: allowed days overlap with forbidden days.": "Contraintes de jour contradictoires : un même jour est à la fois autorisé et interdit.",
  "Contradictory time constraints: maxStartTime is less than minStartTime.": "Contraintes d'horaire contradictoires : l'heure de fin est avant l'heure de début.",
};

/** Turn a raw validator message into user-facing French; leave unknowns as-is. */
export function humanizeConstraintError(raw: string): string {
  const exact = EXACT[raw];
  if (undefined !== exact) {
    return exact;
  }
  // "Scope TEAM requires a scope_target_id." — the family varies, match the shape.
  if (/^Scope \w+ requires a scope_target_id\.$/.test(raw)) {
    return "Cette contrainte doit cibler une équipe ou un groupe.";
  }
  return raw;
}
