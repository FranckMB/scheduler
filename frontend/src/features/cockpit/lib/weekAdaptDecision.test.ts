import { describe, expect, it } from "vitest";

import { decideWeekAdapt, pickerStateOf, type WeekAdaptInput } from "./useWeekAdapt";

// P2-36 — la maison UNIQUE de la décision d'ouverture. Le radar et le DayDialog la
// dupliquaient avec des entrées différentes : corriger un seul côté garantissait un écart. Ce
// test fige les quatre causes NOMMÉES et leurs deux issues, pour que « déjà découpée », « déjà
// générée d'un bloc », « en chargement » et « une seule semaine » ne se confondent jamais.
const input = (over: Partial<WeekAdaptInput>): WeekAdaptInput => ({ multiWeek: true, alreadySplit: false, resolved: true, blockGenerated: false, ...over });

describe("decideWeekAdapt (P2-36)", () => {
  it("une seule semaine calendaire → single-week (aucun choix à offrir : bloc direct)", () => {
    expect(decideWeekAdapt(input({ multiWeek: false }))).toEqual({ kind: "single-week" });
    // …et rien d'autre ne peut renverser ça (une seule semaine gagne toujours).
    expect(decideWeekAdapt(input({ multiWeek: false, blockGenerated: true, resolved: false }))).toEqual({ kind: "single-week" });
  });

  it("mère déjà découpée → already-split (sa carte de couverture gouverne : bloc direct)", () => {
    expect(decideWeekAdapt(input({ alreadySplit: true }))).toEqual({ kind: "already-split" });
  });

  it("plans/plannings/enfants pas résolus → loading (le picker s'ouvre et le dit)", () => {
    expect(decideWeekAdapt(input({ resolved: false }))).toEqual({ kind: "loading" });
  });

  it("le plan de bloc porte déjà des versions → block-generated", () => {
    expect(decideWeekAdapt(input({ blockGenerated: true }))).toEqual({ kind: "block-generated" });
  });

  it("cas nominal → weeks (choix des semaines)", () => {
    expect(decideWeekAdapt(input({}))).toEqual({ kind: "weeks" });
  });

  it("pickerStateOf : seuls weeks/loading/block ouvrent le picker ; les deux directs rendent null", () => {
    expect(pickerStateOf({ kind: "weeks" })).toBe("weeks");
    expect(pickerStateOf({ kind: "loading" })).toBe("loading");
    expect(pickerStateOf({ kind: "block-generated" })).toBe("block");
    expect(pickerStateOf({ kind: "single-week" })).toBeNull();
    expect(pickerStateOf({ kind: "already-split" })).toBeNull();
  });
});
