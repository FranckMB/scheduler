import type { Constraint, Slot } from "../api";

/**
 * tag NAME → les équipes qui le portent dans la saison de travail. Miroir CLIENT de
 * `TeamTagResolver::tagTeamIds` (backend) : on résout un `name` (via `TeamTag`) en la
 * liste des `TeamTagAssignment.teamId`. Les assignations lues par le front sont DÉJÀ
 * filtrées à la saison courante côté serveur (filtre Doctrine de saison — cf. entité
 * `TeamTagAssignment`), exactement comme le suppose `ConstraintsStep`.
 *
 * ⚠ Un tag SANS aucune assignation n'apparaît PAS dans la map (pas de clé) : la résolution
 * est alors « aucune équipe », comme le NO-OP du backend (`ScheduleConstraintBuilder` saute
 * la contrainte quand `resolveTagToTeamIds` rend une liste vide).
 */
export function buildTagTeamIds(
  tags: readonly { id: string; name: string }[],
  assignments: readonly { teamId: string; tagId: string }[],
): Map<string, Set<string>> {
  const nameByTagId = new Map(tags.map((t) => [t.id, t.name]));
  const map = new Map<string, Set<string>>();
  for (const assignment of assignments) {
    const name = nameByTagId.get(assignment.tagId);
    if (undefined === name) {
      continue;
    }
    const set = map.get(name) ?? new Set<string>();
    set.add(assignment.teamId);
    map.set(name, set);
  }

  return map;
}

/**
 * Le tag ciblé par une contrainte CLUB, s'il y en a un — `config.targetTag` non vide.
 * Une chaîne vide compte pour « pas de tag » (même garde que le backend : `'' !== $targetTag`).
 */
export function targetTagOf(constraint: Constraint): string | null {
  const raw = constraint.config?.targetTag;

  return "string" === typeof raw && "" !== raw ? raw : null;
}

/**
 * La contrainte concerne-t-elle le club ENTIER (et non une équipe en particulier) ? Vrai
 * seulement pour une CLUB SANS `targetTag` : dès qu'un tag est posé, le backend l'éclate en
 * contraintes TEAM — elle concerne alors les équipes taguées, pas tout le club.
 */
export function isClubWide(constraint: Constraint): boolean {
  return "CLUB" === constraint.scope && null === targetTagOf(constraint);
}

/**
 * Les contraintes du club qui S'APPLIQUENT à un créneau, composées côté client depuis
 * `GET /api/constraints` (F1) — aucun champ calculé serveur. Une contrainte s'applique selon
 * son scope : CLUB partout SAUF si elle cible un tag (alors seulement les équipes taguées,
 * miroir de l'éclatement backend en contraintes TEAM), TEAM à son équipe, FACILITY à son
 * gymnase, COACH à son coach (et jamais si le créneau n'a pas de coach). Les contraintes
 * inactives sont écartées.
 *
 * `tagTeamIds` = la résolution tag→équipes (cf. `buildTagTeamIds`). Absente ou incomplète
 * (données pas encore lues, tag introuvable, tag sans équipe) → une CLUB+tag ne s'affiche
 * NULLE PART : sur-afficher serait re-mentir sur ce que le solveur applique.
 */
export function applicableConstraints(
  slot: Slot,
  constraints: Constraint[],
  tagTeamIds: ReadonlyMap<string, ReadonlySet<string>> = new Map(),
): Constraint[] {
  return constraints.filter((c) => c.isActive && applies(c, slot, tagTeamIds));
}

function applies(constraint: Constraint, slot: Slot, tagTeamIds: ReadonlyMap<string, ReadonlySet<string>>): boolean {
  switch (constraint.scope) {
    case "CLUB": {
      const tag = targetTagOf(constraint);
      if (null === tag) {
        return true;
      }

      return true === tagTeamIds.get(tag)?.has(slot.teamId);
    }
    case "TEAM":
      return constraint.scopeTargetId === slot.teamId;
    case "FACILITY":
      return constraint.scopeTargetId === slot.venueId;
    case "COACH":
      return null !== slot.coachId && constraint.scopeTargetId === slot.coachId;
    default:
      return false;
  }
}
