import { describe, expect, it } from "vitest";

import type { EntryConflictsResponse } from "@/features/cockpit/api";

import { computeClosedWindows } from "./closedWindows";

const base: EntryConflictsResponse = {
  entryId: "e",
  venueIds: [],
  conflicts: [],
  closures: [],
  fullyClosedVenueIds: [],
  effectiveClosedWeekdays: {},
  disabledVenueIds: [],
  seasonPlanChosen: true,
};

const venueName = (id: string): string => ({ "v1": "Gymnase Alpha", "v2": "Gymnase Beta" })[id] ?? "Gymnase";

describe("computeClosedWindows", () => {
  it("conflits absents → map VIDE (fail-open sur l'affichage)", () => {
    expect(computeClosedWindows(undefined, venueName).size).toBe(0);
  });

  it("un gymnase ENTIÈREMENT fermé → ses 7 jours marqués « indisponible toute la période »", () => {
    const map = computeClosedWindows({ ...base, fullyClosedVenueIds: ["v1"] }, venueName);
    for (let day = 1; day <= 7; day += 1) {
      expect(map.get(`v1|${day}`)).toBe("Gymnase Alpha indisponible toute la période");
    }
    expect(map.size).toBe(7);
  });

  it("grain JOUR : seul le jour fermé est marqué, les autres restent offerts", () => {
    const map = computeClosedWindows({ ...base, effectiveClosedWeekdays: { v1: { "3": "default-incident" } } }, venueName);
    expect(map.has("v1|3")).toBe(true);
    expect(map.has("v1|1")).toBe(false);
    expect(map.has("v1|2")).toBe(false);
    expect(map.size).toBe(1);
  });

  it("la PROVENANCE choisit le libellé (décoché à la main vs indisponibilité déclarée)", () => {
    const map = computeClosedWindows(
      { ...base, effectiveClosedWeekdays: { v1: { "1": "manual" }, v2: { "5": "default-incident" } } },
      venueName,
    );
    expect(map.get("v1|1")).toBe("le lundi est fermé (décoché à la main)");
    expect(map.get("v2|5")).toBe("le vendredi est fermé (indisponibilité déclarée)");
  });

  it("l'indisponibilité TOTALE prime sur le grain jour (pas de double résumé)", () => {
    const map = computeClosedWindows(
      { ...base, fullyClosedVenueIds: ["v1"], effectiveClosedWeekdays: { v1: { "3": "manual" } } },
      venueName,
    );
    expect(map.get("v1|3")).toBe("Gymnase Alpha indisponible toute la période");
  });
});
