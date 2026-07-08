import { render, screen, within } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

vi.mock("../queries", () => ({
  usePriorityTiers: () => ({ data: [{ id: 1, label: "S", name: "Fanion", color: null }, { id: 2, label: "A", name: "Importante", color: null }] }),
  useVenueSlots: () => ({ data: [{ id: "s1", venueId: "v1", dayOfWeek: 1, startTime: "18:00", durationMinutes: 90, capacity: 1 }, { id: "s2", venueId: "v1", dayOfWeek: 3, startTime: "18:00", durationMinutes: 90, capacity: 1 }] }),
  useWizardCoachPlayers: () => ({ data: [{ id: "cp1", teamId: "t1", coachId: "player-bob", isActive: true }] }),
  useWizardCoaches: () => ({
    data: [
      { id: "other-zoe", firstName: "Zoe", lastName: "Z", email: null, isEmployee: false, isActive: true },
      { id: "player-bob", firstName: "Bob", lastName: "B", email: null, isEmployee: false, isActive: true },
      { id: "salaried-ana", firstName: "Ana", lastName: "A", email: null, isEmployee: true, isActive: true },
    ],
  }),
  useWizardTeamCoaches: () => ({ data: [] }),
  useWizardTeams: () => ({
    data: [
      { id: "t1", name: "SM1", sportCategoryId: "c", priorityTierId: 1, tierOrder: 0, gender: null, level: null, sessionsPerWeek: 2, isActive: true },
      { id: "t2", name: "U13", sportCategoryId: "c", priorityTierId: 2, tierOrder: 0, gender: null, level: null, sessionsPerWeek: 1, isActive: true },
    ],
  }),
  useWizardVenues: () => ({
    data: [
      { id: "v1", name: "Gymnase A", color: "#ff0000", canSplit: false, isActive: true },
      { id: "v2", name: "Gymnase B", color: "#00ff00", canSplit: false, isActive: true },
    ],
  }),
}));

vi.mock("@/features/cockpit/queries", () => ({
  useEntryConflicts: () => ({ data: { venueIds: ["v2"] } }),
}));

import { ReadonlyCoaches, ReadonlyTeams, ReadonlyVenues } from "./StructureSummary";

describe("ReadonlyVenues (period, read-only)", () => {
  it("marks a closed venue INTERDIT and shows slot counts for open ones (no 'sans créneau' error)", () => {
    render(<ReadonlyVenues calendarEntryId="entry-1" />);

    // Open venue → slot count, no forbidden marker.
    const open = screen.getByText("Gymnase A").closest("li")!;
    expect(within(open).getByText("2 créneau(x)")).toBeInTheDocument();
    expect(within(open).queryByText(/INTERDIT/)).not.toBeInTheDocument();

    // Closed venue → INTERDIT, no slot-count / no error.
    const closed = screen.getByText("Gymnase B").closest("li")!;
    expect(within(closed).getByText(/INTERDIT cette période/)).toBeInTheDocument();

    // The bug: a "sans créneau" style error must NOT appear anywhere.
    expect(screen.queryByText(/sans créneau/i)).not.toBeInTheDocument();
  });
});

describe("ReadonlyCoaches (period, read-only)", () => {
  it("orders salaried → coach-player → other", () => {
    render(<ReadonlyCoaches />);
    const rows = screen.getAllByRole("listitem").map((li) => li.textContent ?? "");
    const anaIdx = rows.findIndex((t) => t.includes("Ana"));
    const bobIdx = rows.findIndex((t) => t.includes("Bob"));
    const zoeIdx = rows.findIndex((t) => t.includes("Zoe"));
    expect(anaIdx).toBeLessThan(bobIdx);
    expect(bobIdx).toBeLessThan(zoeIdx);
    // Tags surface the staffing type.
    expect(screen.getByText("salarié")).toBeInTheDocument();
    expect(screen.getByText("coach-joueur")).toBeInTheDocument();
  });
});

describe("ReadonlyTeams (period, read-only)", () => {
  it("groups teams under their tier headers", () => {
    render(<ReadonlyTeams />);
    expect(screen.getByText(/S · Fanion/)).toBeInTheDocument();
    expect(screen.getByText(/A · Importante/)).toBeInTheDocument();
    expect(screen.getByText("SM1")).toBeInTheDocument();
    expect(screen.getByText("U13")).toBeInTheDocument();
  });
});
