/**
 * tag NAME → les équipes qui le portent dans la saison de travail. Miroir CLIENT de
 * `TeamTagResolver::tagTeamIds` (backend) : on résout un `name` (via `TeamTag`) en la
 * liste des `TeamTagAssignment.teamId`. Les assignations lues par le front sont DÉJÀ
 * filtrées à la saison courante côté serveur (filtre Doctrine de saison — cf. entité
 * `TeamTagAssignment`).
 *
 * ⚠ Un tag SANS aucune assignation n'apparaît PAS dans la map (pas de clé) : la résolution
 * est alors « aucune équipe », comme le NO-OP du backend (`ScheduleConstraintBuilder` saute
 * la contrainte quand `resolveTagToTeamIds` rend une liste vide).
 *
 * FOYER UNIQUE (P4-88) : cette résolution existait en DEUX exemplaires côté front —
 * `applicableConstraints.buildTagTeamIds` (panneau planning) et une copie inline dans
 * `wizard/steps/PeriodStructure.tsx` (masquage d'une CLUB+tag sans équipe active),
 * commentée « mirrored server-side » sans garde. Deux copies du même calcul = la dérive
 * garantie ; elle ne vit plus qu'ici. `PeriodStructure` compose PAR-DESSUS un filtre
 * « équipes actives cette période » — un raffinement d'overlay, pas une seconde résolution.
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
