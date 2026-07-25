import { describe, expect, it } from "vitest";

import { canOpenWishes, wishesMotherId, wishesWeekFilter } from "./wishesTarget";

const mother = { id: "m", parentEntryId: null, startDate: "2026-02-16", periodType: "holiday" };
const child = { id: "c", parentEntryId: "m", startDate: "2026-02-23", periodType: "holiday" };

describe("wishesTarget", () => {
  it("cible la MÈRE elle-même quand l'entrée est une racine", () => {
    expect(wishesMotherId(mother)).toBe("m");
    expect(wishesWeekFilter(mother)).toBeNull(); // plan de bloc → toutes les semaines
  });

  it("remonte à la mère et filtre sur la semaine quand l'entrée est un enfant", () => {
    // Le wizard porte souvent la SEMAINE enfant : la todo-list s'ouvre sur la mère,
    // filtrée sur le lundi de cette semaine (ancrage aux vacances, pas au plan).
    expect(wishesMotherId(child)).toBe("m");
    expect(wishesWeekFilter(child)).toBe("2026-02-23"); // déjà un lundi
  });

  it("n'ouvre les doléances que pour les vacances (holiday)", () => {
    expect(canOpenWishes(mother)).toBe(true);
    expect(canOpenWishes({ ...mother, periodType: "closure" })).toBe(false);
    expect(canOpenWishes(null)).toBe(false);
  });

  it("null/undefined ne cible rien", () => {
    expect(wishesMotherId(null)).toBeNull();
    expect(wishesWeekFilter(undefined)).toBeNull();
  });
});
