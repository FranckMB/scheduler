import { describe, expect, it } from "vitest";

import { DURATIONS } from "./days";

/**
 * P4-37 — les durées proposées allaient de 1h à 2h, alors qu'un club pose aussi bien des
 * séances courtes de jeunes (45 min) que des créneaux longs de senior (2h30). Retour
 * terrain 2026-07-31. Trois sélecteurs lisent cette liste : la borner ici les couvre tous.
 */
describe("DURATIONS — les durées de créneau offertes", () => {
  it("va de 45 min à 2h30 par pas de 15", () => {
    expect(DURATIONS[0]).toBe(45);
    expect(DURATIONS.at(-1)).toBe(150);
    expect(DURATIONS).toEqual([45, 60, 75, 90, 105, 120, 135, 150]);
  });

  it("ne saute aucun quart d'heure", () => {
    const gaps = DURATIONS.slice(1).map((d, i) => d - DURATIONS[i]);
    expect(new Set(gaps)).toEqual(new Set([15]));
  });
});
