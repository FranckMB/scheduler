import { describe, expect, it } from "vitest";

import { addDays, mondayOf, periodAdjustWeeks, weeksCovering, buildMonthGrid, daysUntil, isWithin, monthWindow, toISODate } from "./date";

describe("cockpit date utils", () => {
  it("builds a 42-cell Monday-first grid", () => {
    // May 2026: 1 May is a Friday.
    const grid = buildMonthGrid(2026, 4);
    expect(grid).toHaveLength(42);
    // First cell is the Monday on/before 1 May 2026 → Mon 27 Apr 2026.
    expect(grid[0].iso).toBe("2026-04-27");
    expect(grid[0].inMonth).toBe(false);
    // 1 May sits at index 4 (Fri).
    const may1 = grid.find((c) => c.iso === "2026-05-01");
    expect(may1?.inMonth).toBe(true);
  });

  it("handles a leap February", () => {
    const grid = buildMonthGrid(2028, 1); // Feb 2028 (leap)
    const feb29 = grid.find((c) => c.iso === "2028-02-29");
    expect(feb29?.inMonth).toBe(true);
  });

  it("does not shift dates across timezones", () => {
    expect(toISODate(new Date(2026, 0, 1))).toBe("2026-01-01");
    expect(toISODate(new Date(2026, 11, 31))).toBe("2026-12-31");
  });

  it("computes the month window from grid bounds", () => {
    const { from, to } = monthWindow(2026, 4);
    expect(from).toBe("2026-04-27");
    expect(to <= "2026-06-08").toBe(true);
    expect(from < to).toBe(true);
  });

  it("checks inclusive range membership", () => {
    expect(isWithin("2026-05-04", "2026-05-04", "2026-05-10")).toBe(true);
    expect(isWithin("2026-05-10", "2026-05-04", "2026-05-10")).toBe(true);
    expect(isWithin("2026-05-11", "2026-05-04", "2026-05-10")).toBe(false);
  });

  it("adds days across month/year boundaries", () => {
    expect(addDays("2026-05-30", 5)).toBe("2026-06-04");
    expect(addDays("2026-12-30", 3)).toBe("2027-01-02");
    expect(addDays("2026-05-04", 300)).toBe("2027-02-28");
  });

  it("counts whole days until", () => {
    expect(daysUntil("2026-05-01", "2026-05-25")).toBe(24);
    expect(daysUntil("2026-05-25", "2026-05-01")).toBe(-24);
    expect(daysUntil("2026-05-01", "2026-05-01")).toBe(0);
  });
});

describe("weeksCovering (P2-5 E1 — la semaine est l'unité hors socle)", () => {
  const season = { startDate: "2026-08-01", endDate: "2027-07-14" };

  it("finds the Monday of any date", () => {
    expect(mondayOf("2026-11-12")).toBe("2026-11-09"); // jeudi → lundi
    expect(mondayOf("2026-11-09")).toBe("2026-11-09"); // lundi → lui-même
    expect(mondayOf("2026-11-15")).toBe("2026-11-09"); // dimanche → lundi d'avant
  });

  it("covers a two-week incident with two full Mon→Sun weeks", () => {
    // L'exemple fondateur : incident 12-18 nov → semaines du 9 et du 16.
    expect(weeksCovering("2026-11-12", "2026-11-18", season)).toEqual([
      { startDate: "2026-11-09", endDate: "2026-11-15", monday: "2026-11-09" },
      { startDate: "2026-11-16", endDate: "2026-11-22", monday: "2026-11-16" },
    ]);
  });

  it("clamps the edge weeks to the season window", () => {
    // Vacances d'été à cheval sur la fin de saison (14 juil) : la dernière
    // semaine est rognée, les semaines entièrement hors saison sont omises.
    const weeks = weeksCovering("2027-07-08", "2027-07-25", season);
    expect(weeks).toEqual([
      { startDate: "2027-07-05", endDate: "2027-07-11", monday: "2027-07-05" },
      { startDate: "2027-07-12", endDate: "2027-07-14", monday: "2027-07-12" },
    ]);
  });

  it("a single-week window yields exactly one week", () => {
    expect(weeksCovering("2026-10-20", "2026-10-22", season)).toHaveLength(1);
  });
});

describe("periodAdjustWeeks — vacances démarrant Ven/Sam/Dim (PR C)", () => {
  const season = { startDate: "2026-08-01", endDate: "2027-07-14" };

  it("écarte la semaine partielle de début d'une VACANCE démarrant vendredi", () => {
    // Toussaint : vendredi 16 oct → 1er nov. weeksCovering = 12–18 / 19–25 / 26–01.
    // L'impact réel est sur les semaines suivantes → on propose 19–25 et 26–01.
    expect(periodAdjustWeeks("2026-10-16", "2026-11-01", season, "holiday")).toEqual([
      { startDate: "2026-10-19", endDate: "2026-10-25", monday: "2026-10-19" },
      { startDate: "2026-10-26", endDate: "2026-11-01", monday: "2026-10-26" },
    ]);
  });

  it("ne change rien pour une vacance démarrant lundi", () => {
    expect(periodAdjustWeeks("2026-10-19", "2026-11-01", season, "holiday")).toEqual(
      weeksCovering("2026-10-19", "2026-11-01", season),
    );
  });

  it("ne s'applique QU'aux vacances : une fermeture démarrant vendredi est inchangée", () => {
    expect(periodAdjustWeeks("2026-10-16", "2026-11-01", season, "closure")).toEqual(
      weeksCovering("2026-10-16", "2026-11-01", season),
    );
  });

  it("garde la semaine unique d'une vacance week-end (jamais vide)", () => {
    // Vendredi 16 → dimanche 18 : une seule semaine calendaire → conservée.
    expect(periodAdjustWeeks("2026-10-16", "2026-10-18", season, "holiday")).toHaveLength(1);
  });

  it("n'écarte PAS la 1ʳᵉ semaine si elle est rognée par le début de saison (revue C F3)", () => {
    // Saison démarrant un vendredi (2026-08-07) ; vacance clampée à ce vendredi : la
    // 1ʳᵉ semaine en-saison est partielle par CLAMP, pas parce que la vacance
    // commence en fin de semaine → on la garde.
    const boundarySeason = { startDate: "2026-08-07", endDate: "2027-07-14" };
    expect(periodAdjustWeeks("2026-08-07", "2026-08-25", boundarySeason, "holiday")).toEqual(
      weeksCovering("2026-08-07", "2026-08-25", boundarySeason),
    );
  });
});
