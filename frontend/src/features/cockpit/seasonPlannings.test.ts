import { describe, expect, it } from "vitest";

import type { Schedule } from "@/features/planning/api";

import type { SchedulePlan } from "./api";
import { seasonPlannings } from "./seasonPlannings";

const s = (over: Partial<Schedule>): Schedule => ({ id: "id", name: "Plan", status: "COMPLETED", score: null, createdAt: "2026-07-01T10:00:00+00:00", updatedAt: "", planType: "SEASON", schedulePlanId: "season-plan", ...over });

const sp = (over: Partial<SchedulePlan>): SchedulePlan => ({ id: "pl", type: "HOLIDAY", name: "Plan", calendarEntryId: "e1", chosenScheduleId: null, teamSelectionInitialized: false, ...over });

describe("seasonPlannings — open plannings & plan name (founder feedback 2026-07-18)", () => {
  it("labels the season row with the plan's real name when provided", () => {
    const rows = seasonPlannings([s({ id: "v1" })], "Planning de la saison 2026-2027");
    expect(rows[0].label).toBe("Planning de la saison 2026-2027");
  });

  it("falls back to « Planning principal » without a plan name", () => {
    expect(seasonPlannings([s({ id: "v1" })])[0].label).toBe("Planning principal");
  });

  it("lists an overlay with NO finished version as an OPEN row on its latest version", () => {
    const rows = seasonPlannings([
      s({ id: "o1", name: "Vacances Noël", status: "PENDING", planType: "CLOSURE", schedulePlanId: "p2", createdAt: "2026-07-03T10:00:00+00:00" }),
      s({ id: "o2", name: "Vacances Noël", status: "GENERATING", planType: "CLOSURE", schedulePlanId: "p2", createdAt: "2026-07-04T10:00:00+00:00" }),
    ]);
    expect(rows).toHaveLength(1);
    expect(rows[0]).toMatchObject({ id: "o2", isOpen: true, isOverlay: true, schedulePlanId: "p2" });
  });

  // P4-41 (retour fondateur 2026-07-31) : une ligne de cette liste EST un plan, donc
  // elle porte le nom du PLAN — exactement comme la ligne du socle juste au-dessus.
  // Elle lisait le nom de la VERSION affichée, or toute version de période créée hors
  // wizard naissait « Version de période » (nom inventé par le client, retiré depuis —
  // les lignes créées avant gardent le leur) : un planning renommé
  // « Reprise d'été S1 » se relisait sous ce libellé technique. PREUVE DE CHUTE : sans
  // le correctif, `label` vaut "Version de période".
  it("labels a period row with its PLAN's name, not the displayed version's", () => {
    const rows = seasonPlannings(
      [s({ id: "o1", name: "Version de période", status: "COMPLETED", planType: "HOLIDAY", schedulePlanId: "pl-ete" })],
      null,
      [sp({ id: "pl-ete", name: "Reprise d'été S1", calendarEntryId: "e-ete" })],
    );
    const periodRow = rows.find((r) => r.schedulePlanId === "pl-ete");
    expect(periodRow?.label).toBe("Reprise d'été S1");
  });

  // Les plans ne sont pas toujours passés (appelants qui n'en ont pas besoin) : on
  // dégrade sur le nom de la version plutôt que de rendre une ligne sans libellé.
  it("falls back to the version name when the plans are not loaded yet", () => {
    const rows = seasonPlannings([s({ id: "o1", name: "Vacances Noël", status: "COMPLETED", planType: "HOLIDAY", schedulePlanId: "pl-noel" })]);
    expect(rows.find((r) => r.schedulePlanId === "pl-noel")?.label).toBe("Vacances Noël");
  });

  it("a planning with a finished version stays a closed row (isOpen false), even with newer in-flight versions", () => {
    const rows = seasonPlannings([
      s({ id: "v1", status: "COMPLETED" }),
      s({ id: "v2", status: "GENERATING", createdAt: "2026-07-05T10:00:00+00:00" }),
    ]);
    expect(rows).toHaveLength(1);
    expect(rows[0]).toMatchObject({ id: "v1", isOpen: false });
  });

  // B1 (retour fondateur 2026-07-19) : un plan de période créé mais SANS aucune
  // version générée doit rester visible (« en cours », à reprendre).
  it("lists a period plan with ZERO generated version as an OPEN row keyed on the plan id", () => {
    const rows = seasonPlannings(
      [s({ id: "v1", status: "COMPLETED" })], // seulement le socle a des versions
      null,
      [sp({ id: "pl-tou", name: "Vacances Toussaint — semaine du 20 oct.", calendarEntryId: "e-tou", type: "HOLIDAY" })],
    );
    const openRow = rows.find((r) => r.schedulePlanId === "pl-tou");
    expect(openRow).toMatchObject({ id: "pl-tou", label: "Vacances Toussaint — semaine du 20 oct.", isOpen: true, isOverlay: true, isChosen: false });
  });

  // Revue B1 F3 : une mère de vacances DÉCOUPÉE porte encore un plan à 0 version,
  // mais ses semaines-enfants tiennent l'affichage → pas de ligne fantôme.
  it("does not emit a phantom row for a split holiday mother (its children carry the plannings)", () => {
    const rows = seasonPlannings(
      [s({ id: "v1", status: "COMPLETED" })],
      null,
      [sp({ id: "pl-mother", type: "HOLIDAY", name: "Vacances Toussaint", calendarEntryId: "m1" })],
      [{ id: "w1", kind: "period", title: "S1", startDate: "", endDate: "", isDisruptive: false, periodType: "holiday", schoolHolidayId: null, parentEntryId: "m1", status: "active", createdBy: null }],
    );
    expect(rows.some((r) => r.schedulePlanId === "pl-mother")).toBe(false);
  });

  // Revue B1 F4 : sans schedules résolus, aucune ligne DRAFT (un overlay validé
  // serait montré « en cours » à tort) — fail-closed.
  it("emits no zero-version row while schedules are unresolved (fail-closed)", () => {
    const rows = seasonPlannings(
      [s({ id: "v1", status: "COMPLETED" })],
      null,
      [sp({ id: "pl-tou", type: "HOLIDAY", name: "Toussaint", calendarEntryId: "e-tou" })],
      [],
      false, // schedules pas résolus
    );
    expect(rows.some((r) => r.schedulePlanId === "pl-tou")).toBe(false);
  });

  it("does not duplicate a period plan that already has a version, nor list the SEASON plan or an entry-less plan", () => {
    const rows = seasonPlannings(
      [s({ id: "o1", name: "Toussaint", planType: "CLOSURE", schedulePlanId: "pl-a", status: "COMPLETED", createdAt: "2026-07-03T10:00:00+00:00" })],
      null,
      [
        sp({ id: "pl-a", type: "HOLIDAY", calendarEntryId: "e-a" }), // a déjà une version → pas de doublon
        sp({ id: "pl-season", type: "SEASON", calendarEntryId: "e-s" }), // socle → ignoré
        sp({ id: "pl-orphan", type: "CLOSURE", calendarEntryId: null }), // sans entrée → ignoré
      ],
    );
    expect(rows.filter((r) => r.schedulePlanId === "pl-a")).toHaveLength(1);
    expect(rows.some((r) => r.schedulePlanId === "pl-season")).toBe(false);
    expect(rows.some((r) => r.schedulePlanId === "pl-orphan")).toBe(false);
  });
});
