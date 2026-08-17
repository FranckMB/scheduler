import type { Gender, SharedTrainingGroup, Team, TeamPeriodOverride } from "../api";

/**
 * P2-27 PR B — helpers PURS de la saisie de mutualisation au wizard (N équipes ensemble,
 * K séances communes).
 *
 * 🔴 RIEN ICI N'AUTORISE NI N'INTERDIT — le serveur reste seul juge. La pré-validation de ce
 * module (K plafonné, équipe déjà mutualisée) est FAIL-SAFE : elle guide la saisie sans jamais
 * remplacer le verdict serveur `App\State\Processor\SharedTrainingGroupStateProcessor::assertDeclarationValid`
 * (2..10 équipes, doublon, K > min sessionsPerWeek effectif, équipe d'un autre groupe du même
 * plan). Un 422 doit rester géré et affiché côté écran, jamais supposé impossible.
 *
 * ⚠ Le CLASSEMENT des candidats (`rankCandidates`) est un ORDRE D'AFFICHAGE, jamais une
 * permission : « équipes proches » remonte des candidats plausibles, rien n'est interdit, tout
 * reste cochable en un clic. C'est de la présentation (cf. `matches/lib/diagnostic.ts`), pas une
 * décision de comportement sur un enum de contrainte : ce module n'est donc PAS un miroir
 * déclaré, et il ne doit pas se déclarer comme tel (le registre des miroirs se repère au NOM de
 * son test de garde écrit en source — le citer ici enrôlerait ce module par accident).
 *
 * ⚠ AUCUN ordre d'âge entre catégories : `SportCategory` n'a pas de champ d'ordre, le déduire du
 * nom serait faux dès qu'un club nomme ses catégories autrement. Deux équipes « proches » le sont
 * par MÊME catégorie exacte + genre compatible, jamais par « catégorie voisine ».
 */

/**
 * Séances/semaine EFFECTIVES d'une équipe — l'override de période est PRIORITAIRE. Miroir de
 * `SharedTrainingGroupStateProcessor::effectiveSessionsPerWeek` : un override qui porte un
 * `sessionsPerWeek` non nul l'emporte, sinon on retombe sur la valeur de saison. En portée socle
 * il n'y a pas d'override (la carte est vide) → toujours la valeur de saison.
 */
export function effectiveSessionsPerWeek(team: Team, override: TeamPeriodOverride | undefined): number {
  if (undefined !== override && null !== override.sessionsPerWeek) {
    return override.sessionsPerWeek;
  }

  return team.sessionsPerWeek;
}

/**
 * Le plus petit `sessionsPerWeek` effectif de la sélection = le plafond de K (au-delà, la
 * mutualisation exige plus de séances communes qu'une équipe n'en a → jamais satisfiable, le
 * serveur rend 422). 0 pour une sélection vide (aucun plafond signifiant).
 */
export function maxCommonSessions(teams: Team[], overrideByTeamId: ReadonlyMap<string, TeamPeriodOverride>): number {
  if (0 === teams.length) {
    return 0;
  }

  return Math.min(...teams.map((t) => effectiveSessionsPerWeek(t, overrideByTeamId.get(t.id))));
}

/**
 * Les ids d'équipe déjà pris dans un groupe de la PORTÉE COURANTE, hormis le groupe en cours
 * d'édition (`excludeGroupId`) — dont les membres doivent rester libres de leur propre formulaire.
 */
export function alreadyGroupedTeamIds(groups: SharedTrainingGroup[], excludeGroupId: string | null): Set<string> {
  const taken = new Set<string>();
  for (const group of groups) {
    if (group.id === excludeGroupId) {
      continue;
    }
    for (const teamId of group.teamIds) {
      taken.add(teamId);
    }
  }

  return taken;
}

/** Le groupe (AUTRE que celui édité) auquel une équipe appartient — pour nommer la raison « déjà mutualisée avec … ». */
export function groupContainingTeam(groups: SharedTrainingGroup[], teamId: string, excludeGroupId: string | null): SharedTrainingGroup | undefined {
  return groups.find((group) => group.id !== excludeGroupId && group.teamIds.includes(teamId));
}

/** Libellé d'un groupe : « SM1 + SM2 — 1 séance commune » (pluriel géré). */
export function sharedGroupLabel(teamIds: string[], commonSessions: number, teamName: (id: string) => string): string {
  const names = teamIds.map(teamName).join(" + ");
  const sessions = `${commonSessions} séance${commonSessions > 1 ? "s" : ""} commune${commonSessions > 1 ? "s" : ""}`;

  return `${names} — ${sessions}`;
}

/**
 * Genres compatibles pour le classement d'affichage : MIXTE va avec tout genre, un genre absent
 * (null) n'est jamais une raison de reléguer un candidat (on ne cache pas sur une donnée manquante).
 */
export function gendersCompatible(a: Gender | null, b: Gender | null): boolean {
  if (null === a || null === b) {
    return true;
  }
  if ("MIXTE" === a || "MIXTE" === b) {
    return true;
  }

  return a === b;
}

/**
 * Classe les candidats en deux blocs D'AFFICHAGE relativement à l'équipe d'ancrage (la première
 * cochée) : « proches » = MÊME catégorie exacte + genre compatible, OU déjà cochés (un candidat
 * coché doit toujours rester atteignable pour être décoché) ; le reste va dans « far », déplié en
 * un clic. Sans ancre (rien de coché) : tout reste en `far` (liste plate, pas de bloc « proches »).
 * L'ORDRE des candidats est préservé dans chaque bloc.
 */
export function rankCandidates(
  candidates: Team[],
  anchor: Team | null,
  checkedIds: ReadonlySet<string>,
): { near: Team[]; far: Team[] } {
  const near: Team[] = [];
  const far: Team[] = [];
  for (const candidate of candidates) {
    const isNear =
      null !== anchor &&
      (checkedIds.has(candidate.id) ||
        (candidate.sportCategoryId === anchor.sportCategoryId && gendersCompatible(candidate.gender, anchor.gender)));
    (isNear ? near : far).push(candidate);
  }

  return { near, far };
}
