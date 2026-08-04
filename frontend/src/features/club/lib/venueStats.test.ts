import { describe, expect, it } from "vitest";

import { computeVenueStats, formatHours, seasonWeeks } from "./venueStats";

describe("computeVenueStats", () => {
  const venues = [
    { id: "v1", name: "Mateo" },
    { id: "v2", name: "Barros" },
  ];

  it("somme les heures et compte les équipes DISTINCTES par gymnase, trié par usage", () => {
    const slots = [
      { venueId: "v1", teamId: "t1", durationMinutes: 90 },
      { venueId: "v1", teamId: "t1", durationMinutes: 90 }, // 2ᵉ séance, MÊME équipe
      { venueId: "v1", teamId: "t2", durationMinutes: 60 },
      { venueId: "v2", teamId: "t3", durationMinutes: 120 },
    ];
    expect(computeVenueStats(slots, venues)).toEqual([
      { venueId: "v1", name: "Mateo", hoursPerWeek: 4, teamCount: 2 },
      { venueId: "v2", name: "Barros", hoursPerWeek: 2, teamCount: 1 },
    ]);
  });

  it("un gymnase sans séance n'apparaît pas ; un gymnase inconnu s'affiche « ? » plutôt que de disparaître", () => {
    expect(computeVenueStats([], venues)).toEqual([]);
    // NOMMER ≠ CHOISIR : une séance sur un gymnase absent de la liste reste comptée.
    expect(computeVenueStats([{ venueId: "ghost", teamId: "t1", durationMinutes: 60 }], venues)).toEqual([
      { venueId: "ghost", name: "?", hoursPerWeek: 1, teamCount: 1 },
    ]);
  });
});

describe("seasonWeeks", () => {
  it("compte les semaines de la fenêtre de saison (arrondi), plancher à 1", () => {
    expect(seasonWeeks("2026-08-01", "2027-06-30")).toBe(48);
    expect(seasonWeeks("2026-08-01", "2026-08-03")).toBe(1);
  });
});

describe("formatHours", () => {
  it("une décimale seulement quand elle existe, virgule française", () => {
    expect(formatHours(4)).toBe("4 h");
    expect(formatHours(7.5)).toBe("7,5 h");
    expect(formatHours(7.54)).toBe("7,5 h");
  });
});
