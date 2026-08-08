import { describe, expect, it } from "vitest";

import { DAY_LABEL_LONG, DAYS, dayLabelLong } from "./days";
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
