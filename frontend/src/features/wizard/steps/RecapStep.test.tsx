import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

const h = { reservations: [] as Array<Record<string, unknown>>, resDelete: vi.fn() };

vi.mock("../queries", () => ({
  useWizardTeams: () => ({
    data: [
      { id: "t1", name: "SM1", sportCategoryId: "c", priorityTierId: 3, tierOrder: 0, gender: null, level: null, sessionsPerWeek: 2, isActive: true },
      { id: "t2", name: "Fanion", sportCategoryId: "c", priorityTierId: 1, tierOrder: 0, gender: null, level: null, sessionsPerWeek: 2, isActive: true },
    ],
  }),
  useWizardVenues: () => ({ data: [{ id: "v1", name: "Gymnase A", color: null, isActive: true }] }),
  useVenueSlots: () => ({ data: [] }),
  useWizardCoaches: () => ({ data: [] }),
  useWizardCoachPlayers: () => ({ data: [] }),
  useWizardTeamCoaches: () => ({ data: [] }),
  useWizardConstraints: () => ({ data: [] }),
  useReservations: () => ({ data: h.reservations }),
  usePriorityTiers: () => ({
    data: [
      { id: 1, label: "S", name: "Fanion", color: null },
      { id: 3, label: "B", name: "Moyenne", color: null },
    ],
  }),
  useDeleteReservation: () => ({ mutate: h.resDelete }),
}));
vi.mock("../lib/useStepValidation", () => ({ useStepValidation: () => ({ errors: [] }) }));
vi.mock("../store", () => ({ useWizardStore: (sel: (s: { mode: string; calendarEntryId: string | null }) => unknown) => sel({ mode: "season", calendarEntryId: null }) }));

import { RecapStep } from "./RecapStep";

/**
 * The Réservations accordion is the ONLY UI that lists EVERY reservation, so it
 * must rank-sort them (fanion S → D) and let a manager remove an orphaned one
 * (whose availability slot was deleted, hence absent from the Réserver grid).
 */
describe("RecapStep — Réservations accordion", () => {
  beforeEach(() => {
    h.resDelete.mockClear();
    h.reservations = [];
  });

  it("lists reservations by team rank (fanion before B) and removes one → useDeleteReservation", async () => {
    // Server order puts the rank-B team first; the accordion must show rank-S first.
    h.reservations = [
      { id: "rB", calendarEntryId: null, teamId: "t1", venueId: "v1", dayOfWeek: 2, startTime: "20:30", durationMinutes: 120 },
      { id: "rS", calendarEntryId: null, teamId: "t2", venueId: "v1", dayOfWeek: 3, startTime: "18:00", durationMinutes: 90 },
    ];
    const user = userEvent.setup();
    renderWithProviders(<RecapStep />);

    await user.click(screen.getByRole("button", { name: /Réservations/ }));

    const removeLabels = screen.getAllByRole("button", { name: /Retirer la réservation de/ }).map((b) => b.getAttribute("aria-label") ?? "");
    const iFanion = removeLabels.findIndex((l) => l.includes("Fanion"));
    const iSM1 = removeLabels.findIndex((l) => l.includes("SM1"));
    expect(iFanion).toBeGreaterThanOrEqual(0);
    expect(iFanion).toBeLessThan(iSM1); // rank S (Fanion) before rank B (SM1)

    await user.click(screen.getByRole("button", { name: /Retirer la réservation de Fanion/ }));
    expect(h.resDelete).toHaveBeenCalledWith("rS");
  });
});
