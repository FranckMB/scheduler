import { describe, expect, it } from "vitest";

import { defaultPlanningName } from "./planningName";

describe("defaultPlanningName (E6 — conforming defaults)", () => {
  it("socle → Planning de la saison {saison}", () => {
    expect(defaultPlanningName({ periodMode: false, seasonName: "2025-2026" })).toBe("Planning de la saison 2025-2026");
  });

  it("closure → Ajustement {gym} du…au…", () => {
    expect(
      defaultPlanningName({ periodMode: true, periodType: "closure", gymName: "Barros", startDate: "2026-10-21", endDate: "2026-10-27" }),
    ).toBe("Ajustement Barros du 21/10 au 27/10");
  });

  it("closure sans gym → Ajustement du…au… (fallback)", () => {
    expect(
      defaultPlanningName({ periodMode: true, periodType: "closure", gymName: null, startDate: "2026-10-21", endDate: "2026-10-27" }),
    ).toBe("Ajustement du 21/10 au 27/10");
  });

  it("holiday → Planning de vacances de {nom} du…au… (préfixe Vacances retiré)", () => {
    expect(
      defaultPlanningName({ periodMode: true, periodType: "holiday", entryTitle: "Vacances Toussaint", startDate: "2026-10-21", endDate: "2026-10-27" }),
    ).toBe("Planning de vacances de Toussaint du 21/10 au 27/10");
  });
});
