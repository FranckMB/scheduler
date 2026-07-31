import { describe, expect, it } from "vitest";

import type { Team, TeamPeriodOverride, Venue, VenuePeriodOverride } from "../api";
import { activeTeams, activeVenues, pausedTeamIds } from "./activeLayer";

/**
 * P2-15 — LA RÈGLE, épinglée sans React.
 *
 * ⚠ Ce fichier existe pour une raison mesurée : tant que la règle vivait dans les hooks,
 * les tests d'écran la MOCKAIENT. Neutraliser le filtrage laissait les 697 tests VERTS —
 * ils gardaient le câblage, jamais la règle. Un test qui alimente lui-même la valeur
 * qu'il prétend vérifier ne garde rien.
 */
const team = (id: string): Team => ({ id, name: id, sportCategoryId: "c", priorityTierId: 1, tierOrder: 0, gender: null, level: null, sessionsPerWeek: 2, isActive: true }) as Team;
const venue = (id: string): Venue => ({ id, name: id, color: null, canSplit: false, isActive: true }) as Venue;
const teamOverride = (teamId: string, isActive: boolean): TeamPeriodOverride => ({ id: `o-${teamId}`, teamId, isActive, sessionsPerWeek: null }) as TeamPeriodOverride;
const venueOverride = (venueId: string, mode: string): VenuePeriodOverride => ({ id: `o-${venueId}`, venueId, mode }) as VenuePeriodOverride;

describe("activeLayer — ce que la période contient", () => {
  it("retire le gymnase DISABLED", () => {
    expect(activeVenues([venue("v1"), venue("v2")], [venueOverride("v1", "DISABLED")]).map((v) => v.id)).toEqual(["v2"]);
  });

  // Vidé n'est pas désactivé : un gymnase BLANK sert encore la période, le retirer de
  // l'écran le ferait disparaître alors que le solveur le voit.
  it("garde le gymnase BLANK", () => {
    expect(activeVenues([venue("v1")], [venueOverride("v1", "BLANK")]).map((v) => v.id)).toEqual(["v1"]);
  });

  it("sans override, la liste est rendue telle quelle", () => {
    const venues = [venue("v1"), venue("v2")];
    expect(activeVenues(venues, [])).toBe(venues);
  });

  it("retire l'équipe mise en pause et la nomme", () => {
    const paused = pausedTeamIds([teamOverride("t2", false), teamOverride("t3", true)]);
    expect([...paused]).toEqual(["t2"]);
    expect(activeTeams([team("t1"), team("t2"), team("t3")], paused).map((t) => t.id)).toEqual(["t1", "t3"]);
  });

  // Un override qui REMET l'équipe active (isActive: true) ne doit rien retirer — c'est
  // le geste « je la remets pour cette période », pas un marqueur de pause.
  it("un override actif ne met personne en pause", () => {
    expect([...pausedTeamIds([teamOverride("t1", true)])]).toEqual([]);
  });
});
