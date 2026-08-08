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
 * D-30 — l'inventaire annonçait trois stratégies de fuseau « qui divergent hors France ».
 * MESURÉ SOUS UTC+14 (Pacific/Kiritimati) : les trois concordent, et c'est logique — chacune
 * est cohérente avec elle-même (`T00:00:00` local lu par `getDay()`, `T…Z` lu par
 * `getUTCDay()`). Le risque annoncé n'existait pas ; **le finding est réfuté**.
 *
 * Ce qui restait vrai : trois implémentations de la même question, dont deux dans la même
 * feature. La consolidation vaut pour la lisibilité, pas pour un bug. Ce test épingle donc
 * ce qui compte réellement — dimanche vaut 7 et jamais 0, la source d'erreur classique.
 */
describe("jour ISO d'une date civile (foyer unique, D-30)", () => {
  it("rend 1..7 avec dimanche = 7, jamais 0", () => {
    expect(isoDayOf("2026-08-03")).toBe(1); // lundi
    expect(isoDayOf("2026-08-08")).toBe(6); // samedi
    expect(isoDayOf("2026-08-09")).toBe(7); // dimanche — 0 en JS, 7 en ISO
    expect(isoDayOf("2026-01-01")).toBe(4); // jeudi
  });
});
