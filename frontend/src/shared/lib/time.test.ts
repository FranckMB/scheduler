import { describe, expect, it } from "vitest";

import { formatMinutes as sharedFormat } from "./time";
import { fmtMinutes } from "@/features/wizard/lib/days";
import { formatMinutes as matchesFormat } from "@/features/matches/lib/weekendGrid";
import { formatMinutes as planningFormat } from "@/features/planning/lib/grid";

/**
 * D-20 — il existait TROIS formateurs « minutes depuis minuit → HH:MM », et un seul clampait.
 * Un horaire franchissant minuit s'affichait « 01:15 » côté matchs et « 25:15 » côté planning
 * et wizard : l'incident décrit dans `wizard/lib/slotOverlap.ts`, corrigé à l'époque par une
 * garde de SAISIE, les formateurs restant discordants.
 */
describe("formatMinutes (foyer unique, D-20)", () => {
  it("formate une heure normale", () => {
    expect(sharedFormat(0)).toBe("00:00");
    expect(sharedFormat(9 * 60 + 5)).toBe("09:05");
    expect(sharedFormat(23 * 60 + 59)).toBe("23:59");
  });

  it("ramène un dépassement de minuit à une heure lisible, jamais « 25:15 »", () => {
    expect(sharedFormat(25 * 60 + 15)).toBe("01:15");
    expect(sharedFormat(24 * 60)).toBe("00:00");
  });

  it("survit à une valeur négative (le modulo JS garde le signe)", () => {
    expect(sharedFormat(-30)).toBe("23:30");
  });

  /**
   * Le cœur du finding : les trois points d'entrée historiques doivent rendre EXACTEMENT la
   * même chose. Réintroduire une implémentation locale non clampée fait rougir ici.
   */
  it("les trois points d'entrée historiques donnent le même résultat", () => {
    for (const minutes of [0, 615, 1080, 1439, 1440, 25 * 60 + 15, -30]) {
      const expected = sharedFormat(minutes);
      expect(planningFormat(minutes), `planning diverge à ${minutes}`).toBe(expected);
      expect(matchesFormat(minutes), `matchs diverge à ${minutes}`).toBe(expected);
      expect(fmtMinutes(minutes), `wizard diverge à ${minutes}`).toBe(expected);
    }
  });
});
