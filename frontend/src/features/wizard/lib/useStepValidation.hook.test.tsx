import { renderHook } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

const q = <T,>(data: T, isLoading = false) => ({ data, isLoading });

// Mutable per-test query stubs.
let teams: ReturnType<typeof q>;
let venues: ReturnType<typeof q>;
let slots: ReturnType<typeof q>;
let coaches: ReturnType<typeof q>;

// Le plan de la période : ancre des réservations depuis le lot C3 (inv. 5).
vi.mock("@/features/cockpit/queries", () => ({
  useSchedulePlanForEntry: () => ({ data: { id: "plan-1" }, isLoading: false }),
  usePeriodAnchor: () => ({ planId: "plan-1", ready: true, isLoading: false }),
}));
vi.mock("../queries", () => ({
  useWizardTeams: () => teams,
  useWizardVenues: () => venues,
  useVenueSlots: () => slots,
  useWizardCoaches: () => coaches,
  useWizardTeamCoaches: () => q([]),
  useWizardCoachPlayers: () => q([]),
  useConstraintValidation: () => q(undefined),
  useReservations: () => q([]),
}));
vi.mock("../store", () => ({
  useWizardStore: (selector: (s: unknown) => unknown) => selector({ reservations: [], mode: "season", calendarEntryId: null }),
}));

import { useStepValidation } from "./useStepValidation";

describe("useStepValidation — no false blocking error during load", () => {
  beforeEach(() => {
    teams = q([]);
    venues = q([]);
    slots = q([]);
    coaches = q([]);
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
