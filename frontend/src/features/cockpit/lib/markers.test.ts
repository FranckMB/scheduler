import { describe, expect, it } from "vitest";

import type { CalendarEntry } from "../api";
import { entryIcon } from "./markers";

/**
 * Retour fondateur 2026-07-24, étendu à la MÈRE le 2026-08-19 : une VACANCE ne porte jamais ⛔
 * — le surlignage amber (et l'emoji de saison) la marque déjà, un ⛔ en plus la ferait passer
 * pour une interdiction. La doctrine masquait les ENFANTS ; la mère holiday (« Vacances d'été »,
 * racine, `periodType=holiday`) recevait encore ⛔ par le défaut « toute période non-cutoff → ⛔ ».
 * Les FERMETURES gardent leur ⛔ (sans amber, seule trace au calendrier), les COUPURES leur 🛑.
 */
const period = (over: Partial<CalendarEntry>): CalendarEntry => ({
  id: "e",
  kind: "period",
  title: "",
  startDate: "2026-01-01",
  endDate: "2026-01-07",
  isDisruptive: false,
  periodType: "closure",
  schoolHolidayId: null,
  parentEntryId: null,
  status: "active",
  createdBy: null,
  ...over,
});

describe("entryIcon — la vacance ne porte jamais ⛔", () => {
  it("une MÈRE holiday (racine) ne produit AUCUN marqueur ⛔", () => {
    // RED avant le fix : `entryIcon` renvoyait ⛔ pour toute période non-cutoff, mère vacances
    // comprise — elle s'affichait comme une interdiction sur la grille d'accueil.
    const mother = period({ periodType: "holiday", schoolHolidayId: "sh-1", parentEntryId: null, title: "Vacances d'été" });
    expect(entryIcon(mother)).not.toBe("⛔");
    // Et la mère holiday SANS schoolHolidayId — celle qui échappe à `isHolidayAnchor` et
    // atteint donc réellement `entryIcon` sur la grille — pas de ⛔ non plus.
    expect(entryIcon(period({ periodType: "holiday", schoolHolidayId: null }))).not.toBe("⛔");
  });

  it("une FERMETURE garde son ⛔, une COUPURE son 🛑 (NR)", () => {
    expect(entryIcon(period({ periodType: "closure" }))).toBe("⛔");
    expect(entryIcon(period({ periodType: "cutoff" }))).toBe("🛑");
  });
});
