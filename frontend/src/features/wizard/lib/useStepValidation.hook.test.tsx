import { renderHook } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

const q = <T,>(data: T, isLoading = false) => ({ data, isLoading });

// Mutable per-test query stubs.
let teams: ReturnType<typeof q>;
let venues: ReturnType<typeof q>;
let slots: ReturnType<typeof q>;
let coaches: ReturnType<typeof q>;
let constraintValidation: ReturnType<typeof q>;

// Le plan de la période : ancre des réservations depuis le lot C3 (inv. 5).
vi.mock("@/features/cockpit/queries", () => ({
  useSchedulePlanForEntry: () => ({ data: { id: "plan-1" }, isLoading: false }),
  usePeriodAnchor: () => ({ state: "period", planId: "plan-1" }),
  anchorIsWritable: (a: { state: string }) => "period" === a.state || "base" === a.state,
}));
vi.mock("../queries", () => ({
  useWizardTeams: () => teams,
  useWizardVenues: () => venues,
  useVenueSlots: () => slots,
  useWizardCoaches: () => coaches,
  useWizardTeamCoaches: () => q([]),
  useWizardCoachPlayers: () => q([]),
  useConstraintValidation: () => constraintValidation,
  useReservations: () => q([]),
  useTeamPeriodOverrides: () => ({ data: [] }),
  usePeriodSlots: () => q([]),
  useVenuePeriodOverrides: () => q([]),
}));
vi.mock("../store", () => ({
  useWizardStore: (selector: (s: unknown) => unknown) => selector({ reservations: [], mode: "season", calendarEntryId: null, stepId: "recap" }),
}));

import { useStepValidation } from "./useStepValidation";

describe("useStepValidation — no false blocking error during load", () => {
  beforeEach(() => {
    teams = q([]);
    venues = q([]);
    slots = q([]);
    coaches = q([]);
    constraintValidation = q(undefined);
  });

  it("stays neutral while a query is still loading (no 'add a team' flash)", () => {
    teams = q([], true); // first load, data defaulted to []
    const { result } = renderHook(() => useStepValidation("teams"));
    expect(result.current.errors).toEqual([]);
  });

  it("reports the empty-teams error only once the query has settled", () => {
    teams = q([]); // loaded, genuinely empty
    const { result } = renderHook(() => useStepValidation("teams"));
    expect(result.current.errors).toEqual(["Ajoutez au moins une équipe."]);
  });

  it("passes when teams exist", () => {
    teams = q([{ id: "t1" }]);
    const { result } = renderHook(() => useStepValidation("teams"));
    expect(result.current.errors).toEqual([]);
  });
});

describe("useStepValidation — les avertissements du serveur remontent au récap", () => {
  beforeEach(() => {
    teams = q([{ id: "t1" }]);
    venues = q([{ id: "v1" }]);
    slots = q([{ venueId: "v1" }]);
    coaches = q([{ id: "c1" }]);
    constraintValidation = q(undefined);
  });

  it("remonte les warnings alors que la validation est VALIDE", () => {
    // ⚠ Le cœur de la règle : un avertissement n'invalide rien (#8), il arrive donc
    // avec `valid: true`. Le lire dans la branche `if (!valid)` ne remonterait jamais rien.
    constraintValidation = q({
      valid: true,
      errors: {},
      conflicts: [],
      warnings: ["« Pas le lundi » vise le gymnase Debarros, désactivé pour cette période : elle ne sera pas appliquée."],
    });
    const { result } = renderHook(() => useStepValidation("recap"));

    expect(result.current.warnings).toEqual(["« Pas le lundi » vise le gymnase Debarros, désactivé pour cette période : elle ne sera pas appliquée."]);
  });

  it("n'invalide pas le récap : un avertissement ne produit aucune erreur", () => {
    constraintValidation = q({ valid: true, errors: {}, conflicts: [], warnings: ["un avertissement"] });
    const { result } = renderHook(() => useStepValidation("recap"));

    expect(result.current.errors).toEqual([]);
  });

  it("reste vide quand le serveur n'envoie pas la clé (serveur antérieur)", () => {
    constraintValidation = q({ valid: true, errors: {}, conflicts: [] });
    const { result } = renderHook(() => useStepValidation("recap"));

    expect(result.current.warnings).toEqual([]);
  });
});
