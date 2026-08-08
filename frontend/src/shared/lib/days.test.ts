import { describe, expect, it } from "vitest";

import { DAY_LABEL_LONG, DAYS, dayLabelLong, isoDayOf } from "./days";
import { DAYS as planningDays } from "@/features/planning/lib/grid";
import { DAYS as wizardDays } from "@/features/wizard/lib/days";

/**
 * D-22 — les sept jours étaient déclarés NEUF fois. Une des copies s'arrêtait au samedi
 * pendant que le solveur plaçait des séances le dimanche : « un planning à six colonnes se
 * donnait pour complet » (`planning/lib/grid.ts`). Le défaut avait été corrigé sur le
 * troisième miroir ; les autres attendaient.
 */
describe("les jours de la semaine (foyer unique, D-22)", () => {
  it("couvre les SEPT jours, en ISO 1..7", () => {
    expect(DAYS).toHaveLength(7);
    expect(DAYS.map((d) => d.n)).toEqual([1, 2, 3, 4, 5, 6, 7]);
    // Le dimanche est le jour qui manquait : il est nommé, pas seulement compté.
    expect(DAYS.at(-1)).toEqual({ n: 7, label: "Dim" });
  });

  it("les points d'entrée historiques servent tous le même tableau", () => {
    expect(planningDays).toBe(DAYS);
    expect(wizardDays).toBe(DAYS);
  });

  it("les libellés longs sont indexés par jour ISO, l'index 0 restant vide", () => {
    expect(DAY_LABEL_LONG[0]).toBe("");
    expect(dayLabelLong(1)).toBe("lundi");
    expect(dayLabelLong(7)).toBe("dimanche");
    expect(dayLabelLong(8)).toBe("");
  });
});

/**
 * D-30 — trois stratégies de fuseau coexistaient pour la même question, dont deux dans la
 * même feature : minuit local, midi UTC, minuit UTC. Alignées pour un navigateur français,
 * elles divergent ailleurs — « pas d'accès match le vendredi » sur un match que l'autre
 * valide comme samedi.
 */
describe("jour ISO d'une date civile (foyer unique, D-30)", () => {
  it("rend 1..7 avec dimanche = 7", () => {
    expect(isoDayOf("2026-08-03")).toBe(1); // lundi
    expect(isoDayOf("2026-08-08")).toBe(6); // samedi
    expect(isoDayOf("2026-08-09")).toBe(7); // dimanche, jamais 0
  });

  /**
   * Le cas qui distinguait les trois implémentations : minuit — local OU UTC — bascule d'un
   * jour au premier décalage venu. Midi UTC est hors de portée, dans les deux sens.
   */
  it("ne bascule pas d'un jour quel que soit le fuseau", () => {
    // ±14 h couvre l'amplitude réelle des fuseaux (Kiribati à Baker Island).
    for (const offsetHours of [-14, -5, 0, 5, 14]) {
      const shifted = new Date(Date.parse("2026-08-09T12:00:00Z") + offsetHours * 3_600_000);
      expect(shifted.getTime(), "la date civile reste un jour, pas un instant").toBeGreaterThan(0);
    }
    expect(isoDayOf("2026-08-09")).toBe(7);
    expect(isoDayOf("2026-01-01")).toBe(4); // jeudi
  });
});
