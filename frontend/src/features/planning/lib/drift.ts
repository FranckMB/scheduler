/**
 * Les équipes « à la dérive » d'un planning : celles qui ont MOINS de séances placées que
 * ce qu'on attend d'elles. PRÉSENTATION pure (régime 1 des règles frontend) — le seuil et
 * le placement viennent du backend (`Team.sessionsPerWeek`, overrides de période, slots
 * générés) ; on ne fait que compter l'écart pour l'afficher, jamais décider d'une règle.
 *
 * Deux régimes de seuil, selon la COUCHE du planning affiché (ADR-0002) :
 *  - **plan SAISON** (`overrides === null`) : le seuil est `team.sessionsPerWeek`.
 *  - **plan de PÉRIODE** (`overrides` fournis) : le seuil vient de l'override du plan —
 *    équipe désactivée (`isActive === false`) → jamais en dérive (elle ne joue pas la
 *    période) ; `sessionsPerWeek` overridé (non nul) → c'est LUI le seuil ; sinon on
 *    retombe sur le seuil de saison.
 */
export interface DriftTeam {
  id: string;
  sessionsPerWeek: number;
}

export interface DriftOverride {
  teamId: string;
  isActive: boolean;
  sessionsPerWeek: number | null;
}

export interface DriftEntry {
  teamId: string;
  /** Attendu − placés, strictement positif (une équipe complète/surnuméraire n'apparaît pas). */
  missing: number;
}

export function computeDrift(
  teams: readonly DriftTeam[],
  slots: readonly { teamId: string }[],
  overrides: readonly DriftOverride[] | null,
): DriftEntry[] {
  const placedByTeam = new Map<string, number>();
  for (const slot of slots) {
    placedByTeam.set(slot.teamId, (placedByTeam.get(slot.teamId) ?? 0) + 1);
  }

  const overrideByTeam = null === overrides ? null : new Map(overrides.map((o) => [o.teamId, o]));

  const entries: DriftEntry[] = [];
  for (const team of teams) {
    const override = overrideByTeam?.get(team.id);
    // Désactivée pour la période : elle ne joue pas, aucune séance attendue.
    if (undefined !== override && !override.isActive) {
      continue;
    }
    // Seuil : override de période s'il porte un compte, sinon le seuil de saison.
    const expected = undefined !== override && null !== override.sessionsPerWeek ? override.sessionsPerWeek : team.sessionsPerWeek;
    const placed = placedByTeam.get(team.id) ?? 0;
    const missing = expected - placed;
    if (missing > 0) {
      entries.push({ teamId: team.id, missing });
    }
  }
  return entries;
}
