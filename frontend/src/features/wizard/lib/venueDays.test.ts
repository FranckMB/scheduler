import { describe, expect, it } from "vitest";

import { computeDayMaskToggle, manualClosedWeekdays, reactivatedWeekdays } from "./venueDays";

describe("computeDayMaskToggle — écrire OPEN/CLOSED, ou retirer l'entrée au retour au défaut", () => {
  it("jour ouvert par défaut (aucune entrée) → décocher force CLOSED", () => {
    expect(computeDayMaskToggle({}, 6, false)).toEqual({ 6: "CLOSED" });
  });

  it("jour fermé par l'incident (aucune entrée) → cocher force OPEN (réactivation)", () => {
    expect(computeDayMaskToggle({}, 6, true)).toEqual({ 6: "OPEN" });
  });

  it("une entrée manuelle CLOSED → recliquer RETIRE l'entrée (retour au défaut ouvert)", () => {
    expect(computeDayMaskToggle({ 6: "CLOSED" }, 6, true)).toEqual({});
  });

  it("une entrée OPEN (réactivé) → recliquer RETIRE l'entrée (retour au défaut fermé)", () => {
    expect(computeDayMaskToggle({ 6: "OPEN" }, 6, false)).toEqual({});
  });

  it("ne touche pas les autres jours du masque", () => {
    expect(computeDayMaskToggle({ 1: "CLOSED" }, 6, false)).toEqual({ 1: "CLOSED", 6: "CLOSED" });
    expect(computeDayMaskToggle({ 1: "CLOSED", 6: "OPEN" }, 6, false)).toEqual({ 1: "CLOSED" });
  });
});

describe("manualClosedWeekdays — les jours décochés À LA MAIN, lus de l'état effectif servi", () => {
  it("ne retient que la provenance 'manual'", () => {
    const effective = { v1: { "2": "manual" as const, "3": "default-incident" as const } };
    expect(manualClosedWeekdays(effective, "v1")).toEqual([2]);
  });

  it("gymnase absent → aucun jour", () => {
    expect(manualClosedWeekdays({}, "v1")).toEqual([]);
    expect(manualClosedWeekdays(undefined, "v1")).toEqual([]);
  });
});

describe("reactivatedWeekdays — les jours rouverts malgré l'indisponibilité (masque OPEN)", () => {
  it("ne retient que les entrées OPEN", () => {
    expect(reactivatedWeekdays({ 5: "OPEN", 6: "CLOSED" })).toEqual([5]);
  });

  it("masque nul/vide → aucun jour", () => {
    expect(reactivatedWeekdays(null)).toEqual([]);
    expect(reactivatedWeekdays({})).toEqual([]);
  });
});
