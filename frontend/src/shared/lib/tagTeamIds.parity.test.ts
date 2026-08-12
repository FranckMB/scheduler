import { describe, expect, it } from "vitest";

import { buildTagTeamIds } from "./tagTeamIds";
import cases from "./tagTeamIds.parity.json";

/**
 * P4-88 — CÔTÉ FRONT de la parité mécanique du groupement tag → équipes. Le MÊME fichier
 * de cas alimente `TagTeamIdsMirrorParityTest.php` (backend,
 * `TeamTagResolver::teamIdsByTagName`). Changer le groupement d'un seul côté rougit ce
 * côté-là. Le foyer est partagé par `applicableConstraints` et `PeriodStructure`.
 */
describe("buildTagTeamIds — parité mécanique avec TeamTagResolver::teamIdsByTagName (PHP)", () => {
  for (const c of cases.cases) {
    it(c.name, () => {
      const map = buildTagTeamIds(c.tags, c.assignments);
      const actual: Record<string, string[]> = {};
      for (const [name, ids] of map) {
        actual[name] = [...ids].sort();
      }
      const expected: Record<string, string[]> = {};
      for (const [name, ids] of Object.entries(c.expected)) {
        expected[name] = [...(ids as string[])].sort();
      }
      expect(actual).toEqual(expected);
    });
  }
});
