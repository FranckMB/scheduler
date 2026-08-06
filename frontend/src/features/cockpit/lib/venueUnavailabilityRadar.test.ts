import { describe, expect, it } from "vitest";

import type { CalendarEntry } from "../api";
import { unavailabilitiesToAlert, type RadarUnavailability } from "./venueUnavailabilityRadar";

const TODAY = "2026-02-01";

const unavailability = (over: Partial<RadarUnavailability> = {}): RadarUnavailability => ({
  id: "u1",
  venueId: "v1",
  startDate: "2026-02-10",
  endDate: "2026-02-20",
  label: "travaux",
  ...over,
});

const period = (startDate: string, endDate: string): CalendarEntry =>
  ({ id: `p-${startDate}`, kind: "period", periodType: "closure", title: "Fermeture", startDate, endDate }) as CalendarEntry;

describe("unavailabilitiesToAlert", () => {
  it("alerte sur une indisponibilité à venir dans l'horizon", () => {
    expect(unavailabilitiesToAlert([unavailability()], [], TODAY, 30)).toHaveLength(1);
  });

  it("garde une indisponibilité DÉJÀ commencée : elle demande toujours un geste", () => {
    const started = unavailability({ startDate: "2026-01-20", endDate: "2026-02-15" });

    expect(unavailabilitiesToAlert([started], [], TODAY, 30)).toHaveLength(1);
  });

  it("écarte une indisponibilité révolue — elle n'appelle plus aucune action", () => {
    const past = unavailability({ startDate: "2026-01-05", endDate: "2026-01-20" });

    expect(unavailabilitiesToAlert([past], [], TODAY, 30)).toEqual([]);
  });

  it("écarte une indisponibilité au-delà de l'horizon", () => {
    const far = unavailability({ startDate: "2026-06-01", endDate: "2026-06-20" });

    expect(unavailabilitiesToAlert([far], [], TODAY, 30)).toEqual([]);
    // Témoin : le même cas sous un horizon large ressort — c'est bien l'horizon qui tranche.
    expect(unavailabilitiesToAlert([far], [], TODAY, 200)).toHaveLength(1);
  });

  it("se tait quand une période couvre TOUTE la plage : cette période porte déjà son « Adapter »", () => {
    const covering = [period("2026-02-05", "2026-02-28")];

    expect(unavailabilitiesToAlert([unavailability()], covering, TODAY, 30)).toEqual([]);
  });

  it("alerte encore quand la période ne couvre la plage QUE partiellement", () => {
    // Les jours hors période ne sont traités par personne : les taire les perdrait.
    const partial = [period("2026-02-05", "2026-02-15")];

    expect(unavailabilitiesToAlert([unavailability()], partial, TODAY, 30)).toHaveLength(1);
  });

  it("trie par date de début pour un ordre stable", () => {
    const later = unavailability({ id: "u2", venueId: "v2", startDate: "2026-02-18", endDate: "2026-02-25" });
    const sooner = unavailability({ id: "u3", venueId: "v3", startDate: "2026-02-03", endDate: "2026-02-06" });

    expect(unavailabilitiesToAlert([later, sooner], [], TODAY, 30).map((u) => u.id)).toEqual(["u3", "u2"]);
  });
});
