import { describe, expect, it } from "vitest";

import { computeDrift } from "./drift";

const team = (id: string, sessionsPerWeek: number) => ({ id, sessionsPerWeek });
const placed = (teamId: string) => ({ teamId });

describe("computeDrift — séances à replacer (régime 1 : présentation d'un compte)", () => {
  it("PLAN SAISON : le seuil est team.sessionsPerWeek ; missing = attendu − placés", () => {
    // U11 attend 2, en a 1 → 1 manquante ; U13 attend 2, en a 2 → aucune.
    const teams = [team("u11", 2), team("u13", 2)];
    const slots = [placed("u11"), placed("u13"), placed("u13")];

    const drift = computeDrift(teams, slots, null);

    expect(drift).toEqual([{ teamId: "u11", missing: 1 }]);
  });

  it("ne signale JAMAIS une équipe complète ou surnuméraire (missing ≤ 0 exclu)", () => {
    const teams = [team("u11", 1)];
    const slots = [placed("u11"), placed("u11")]; // 2 placés pour 1 attendu

    expect(computeDrift(teams, slots, null)).toEqual([]);
  });

  it("PLAN PÉRIODE : un override sessionsPerWeek devient le seuil", () => {
    // La saison veut 2, mais la période n'en veut qu'une → 1 placée suffit, pas de dérive.
    const teams = [team("u11", 2)];
    const slots = [placed("u11")];
    const overrides = [{ teamId: "u11", isActive: true, sessionsPerWeek: 1 }];

    expect(computeDrift(teams, slots, overrides)).toEqual([]);
  });

  it("PLAN PÉRIODE : un override sessionsPerWeek RELEVÉ fait apparaître une dérive", () => {
    const teams = [team("u11", 1)];
    const slots = [placed("u11")];
    const overrides = [{ teamId: "u11", isActive: true, sessionsPerWeek: 3 }];

    expect(computeDrift(teams, slots, overrides)).toEqual([{ teamId: "u11", missing: 2 }]);
  });

  it("PLAN PÉRIODE : une équipe DÉSACTIVÉE pour la période n'est jamais en dérive", () => {
    // isActive=false → l'équipe ne joue pas cette période, aucune séance attendue.
    const teams = [team("u11", 2)];
    const slots: { teamId: string }[] = []; // aucune séance placée
    const overrides = [{ teamId: "u11", isActive: false, sessionsPerWeek: null }];

    expect(computeDrift(teams, slots, overrides)).toEqual([]);
  });

  it("PLAN PÉRIODE : un override isActive sans sessionsPerWeek retombe sur le seuil de saison", () => {
    const teams = [team("u11", 2)];
    const slots = [placed("u11")];
    const overrides = [{ teamId: "u11", isActive: true, sessionsPerWeek: null }];

    expect(computeDrift(teams, slots, overrides)).toEqual([{ teamId: "u11", missing: 1 }]);
  });

  it("PLAN PÉRIODE : une équipe SANS override garde le seuil de saison", () => {
    const teams = [team("u11", 2)];
    const slots = [placed("u11")];

    expect(computeDrift(teams, slots, [])).toEqual([{ teamId: "u11", missing: 1 }]);
  });
});
