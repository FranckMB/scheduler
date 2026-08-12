import type { Constraint, Slot } from "../api";

/**
 * Les contraintes du club qui S'APPLIQUENT à un créneau, composées côté client depuis
 * `GET /api/constraints` (F1) — aucun champ calculé serveur. Une contrainte s'applique selon
 * son scope : CLUB partout, TEAM à son équipe, FACILITY à son gymnase, COACH à son coach (et
 * jamais si le créneau n'a pas de coach). Les contraintes inactives sont écartées.
 */
export function applicableConstraints(slot: Slot, constraints: Constraint[]): Constraint[] {
  return constraints.filter((c) => c.isActive && applies(c, slot));
}

function applies(constraint: Constraint, slot: Slot): boolean {
  switch (constraint.scope) {
    case "CLUB":
      return true;
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
