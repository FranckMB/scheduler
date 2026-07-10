import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

const h = { reservations: [] as Array<Record<string, unknown>> };

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
}));
vi.mock("../lib/useStepValidation", () => ({ useStepValidation: () => ({ errors: [] }) }));
vi.mock("../store", () => ({ useWizardStore: (sel: (s: { mode: string; calendarEntryId: string | null }) => unknown) => sel({ mode: "season", calendarEntryId: null }) }));

import { RecapStep } from "./RecapStep";

describe("RecapStep — read-only summary", () => {
  beforeEach(() => {
    h.reservations = [];
  });

  it("lists reservations by team rank (fanion before B) with NO delete button (read-only)", async () => {
    // Server order puts the rank-B team first; the accordion must show rank-S first.
    h.reservations = [
      { id: "rB", calendarEntryId: null, teamId: "t1", venueId: "v1", dayOfWeek: 2, startTime: "20:30", durationMinutes: 120 },
      { id: "rS", calendarEntryId: null, teamId: "t2", venueId: "v1", dayOfWeek: 3, startTime: "18:00", durationMinutes: 90 },
    ];
    const user = userEvent.setup();
    renderWithProviders(<RecapStep />);

    await user.click(screen.getByRole("button", { name: /Réservations/ }));

    // Rank order: the Fanion (S) row precedes the SM1 (B) row in the DOM.
    const rows = screen.getAllByText(/^(Fanion|SM1)$/).map((el) => el.textContent);
    expect(rows.indexOf("Fanion")).toBeLessThan(rows.indexOf("SM1"));

    // Read-only strict: the recap exposes no destructive action.
    expect(screen.queryByRole("button", { name: /Retirer la réservation/ })).not.toBeInTheDocument();
  });

  it("shows the team tiers open by default (ranks visible at first glance)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<RecapStep />);

    // Open the outer "Équipes" accordion; the tier groups inside must be OPEN
    // (their team rows visible) with their rank labels shown, S before B.
    const equipesHeaders = screen.getAllByRole("button", { name: /Équipes/ });
    await user.click(equipesHeaders[0]);

    const sHeader = screen.getByRole("button", { name: /S · Fanion/ });
    expect(sHeader).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /B · Moyenne/ })).toBeInTheDocument();
    // defaultOpen: the S tier's team row is already visible without a click.
    expect(within(sHeader.parentElement as HTMLElement).getByText("Fanion")).toBeInTheDocument();
  });
});
