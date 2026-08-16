import type { MoveViolation, Slot } from "../api";

/**
 * Les créneaux à SURLIGNER après un déplacement REFUSÉ (F2b) : ceux de l'équipe DÉJÀ en place
 * que le moteur a nommée comme la cause du conflit (`conflictingTeamId`).
 *
 * PRÉSENTATION pure — pas une redérivation de règle : le backend a dit « non » et fourni les
 * ids ; on se contente de retrouver, dans le cache affiché, où siège l'équipe fautive pour
 * attirer l'œil dessus. Une équipe absente du cache (id inconnu du planning courant) n'ajoute
 * RIEN : pas de surlignage fantôme sur un créneau qu'on ne montre pas.
 */
export function violationHighlightSlotIds(violations: MoveViolation[], slots: Slot[]): Set<string> {
  const conflictingTeamIds = new Set<string>();
  for (const violation of violations) {
    const teamId = violation.conflictingTeamId;
    if (null != teamId && "" !== teamId) {
      conflictingTeamIds.add(teamId);
    }
  }

  const slotIds = new Set<string>();
  for (const slot of slots) {
    if (conflictingTeamIds.has(slot.teamId)) {
      slotIds.add(slot.id);
    }
  }
  return slotIds;
}
