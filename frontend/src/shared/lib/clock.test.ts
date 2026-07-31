import { afterEach, describe, expect, it } from "vitest";

import { setTodayOverride, toISODate, todayISO } from "./clock";

afterEach(() => setTodayOverride(null));

describe("clock — le « aujourd'hui » du front", () => {
  it("sans override, rend la date réelle du jour", () => {
    expect(todayISO()).toBe(toISODate(new Date()));
  });

  it("l'override fixe la date, et null la relâche", () => {
    setTodayOverride("2026-12-20");
    expect(todayISO()).toBe("2026-12-20");

    setTodayOverride(null);
    expect(todayISO()).toBe(toISODate(new Date()));
  });

  // Le point qui compte : les filtres du cockpit comparent des CHAÎNES ISO. Une valeur
  // malformée qui passerait ferait des comparaisons lexicographiques silencieusement
  // fausses (« hier » > « 2026-… » est VRAI) — un écran qui ment vaut moins qu'un
  // paramètre sans effet.
  it("ignore une valeur malformée plutôt que de la propager", () => {
    setTodayOverride("hier");
    expect(todayISO()).toBe(toISODate(new Date()));

    setTodayOverride("2026-13");
    expect(todayISO()).toBe(toISODate(new Date()));
  });

  it("toISODate rend la date LOCALE (pas le décalage UTC de toISOString)", () => {
    // 1er janvier 00:30 heure locale : toISOString basculerait au 31 décembre sur un
    // fuseau à l'est de Greenwich.
    expect(toISODate(new Date(2026, 0, 1, 0, 30))).toBe("2026-01-01");
  });
});
