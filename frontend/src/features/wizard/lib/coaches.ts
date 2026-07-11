import type { Coach, CoachPlayerMembership } from "../api";

/** A coach's kind, for grouping the coach lists (batch item 1). */
export type CoachCategory = "salarie" | "coach_joueur" | "benevole";

/**
 * Salarié wins over coach-joueur (an employed coach is shown among the Salariés
 * even if they also play), else a coach with any player membership is a
 * coach-joueur, else a bénévole.
 */
export function coachCategory(coach: Coach, coachPlayers: CoachPlayerMembership[]): CoachCategory {
  if (coach.isEmployee) {
    return "salarie";
  }
  if (coachPlayers.some((m) => m.coachId === coach.id)) {
    return "coach_joueur";
  }
  return "benevole";
}

/** Alphabetical by first name (French collation, case/accent-insensitive). */
export function compareByFirstName(a: Coach, b: Coach): number {
  return a.firstName.localeCompare(b.firstName, "fr", { sensitivity: "base" });
}

export interface GroupedCoaches {
  salaries: Coach[];
  coachJoueurs: Coach[];
  benevoles: Coach[];
}

/** Split coaches into Salariés / Coachs-joueurs / Bénévoles, each sorted by first name. */
export function groupCoaches(coaches: Coach[], coachPlayers: CoachPlayerMembership[]): GroupedCoaches {
  const groups: GroupedCoaches = { salaries: [], coachJoueurs: [], benevoles: [] };
  for (const coach of coaches) {
    const category = coachCategory(coach, coachPlayers);
    if ("salarie" === category) {
      groups.salaries.push(coach);
    } else if ("coach_joueur" === category) {
      groups.coachJoueurs.push(coach);
    } else {
      groups.benevoles.push(coach);
    }
  }
  groups.salaries.sort(compareByFirstName);
  groups.coachJoueurs.sort(compareByFirstName);
  groups.benevoles.sort(compareByFirstName);
  return groups;
}
