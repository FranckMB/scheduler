import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, describe, expect, it, vi } from "vitest";

const createOverride = vi.fn();
const updateOverride = vi.fn();
const deleteOverride = vi.fn();
const createSlot = vi.fn();
const deleteSlot = vi.fn();
const overridesState: { data: Array<{ id: string; teamId: string; isActive: boolean; sessionsPerWeek: number | null; calendarEntryId: string }> } = { data: [] };
const conflictState: { venueIds: string[] } = { venueIds: [] };

vi.mock("../queries", () => ({
  useWizardTeams: () => ({ data: [
    { id: "t1", name: "SM1", sportCategoryId: "c", priorityTierId: 1, tierOrder: 0, gender: null, level: null, sessionsPerWeek: 2, isActive: true },
    { id: "t2", name: "U13", sportCategoryId: "c", priorityTierId: 2, tierOrder: 0, gender: null, level: null, sessionsPerWeek: 1, isActive: true },
  ] }),
  usePriorityTiers: () => ({ data: [{ id: 1, label: "S", name: "Fanion", color: null }, { id: 2, label: "A", name: "Importante", color: null }] }),
  useTeamPeriodOverrides: () => ({ data: overridesState.data, isLoading: false }),
  useCreateTeamPeriodOverride: () => ({ mutate: createOverride, mutateAsync: createOverride, isPending: false }),
  useUpdateTeamPeriodOverride: () => ({ mutate: updateOverride, mutateAsync: updateOverride, isPending: false }),
  useDeleteTeamPeriodOverride: () => ({ mutate: deleteOverride, mutateAsync: deleteOverride, isPending: false }),
  useWizardVenues: () => ({ data: [{ id: "v1", name: "Gymnase A", color: "#ff0000", canSplit: false, isActive: true }] }),
  useVenueSlots: () => ({ data: [] }),
  usePeriodSlots: () => ({ data: [{ id: "ps1", venueId: "v1", dayOfWeek: 3, startTime: "20:00:00", durationMinutes: 90, capacity: 1, calendarEntryId: "e1" }] }),
  useCreatePeriodSlot: () => ({ mutate: createSlot, isPending: false }),
  useDeletePeriodSlot: () => ({ mutate: deleteSlot, isPending: false }),
}));
vi.mock("@/features/cockpit/queries", () => ({ useEntryConflicts: () => ({ data: { venueIds: conflictState.venueIds } }) }));
vi.mock("@/shared/stores/toastStore", () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

import { __resetPeriodSeed, PeriodTeams, PeriodVenues } from "./PeriodStructure";

afterEach(() => {
  __resetPeriodSeed();
  overridesState.data = [];
  conflictState.venueIds = [];
  createOverride.mockClear();
  updateOverride.mockClear();
  deleteOverride.mockClear();
  createSlot.mockClear();
  deleteSlot.mockClear();
});

describe("PeriodTeams — Fanion-only default + toggles", () => {
  it("seeds a fresh period with only the top tier active (deactivates the rest)", () => {
    // Unique id: the module-level "already seeded" set persists across tests.
    render(<PeriodTeams calendarEntryId="fresh-seed" />);
    // U13 (tier 2) is deactivated by default; SM1 (Fanion) is not touched.
    expect(createOverride).toHaveBeenCalledWith({ calendarEntryId: "fresh-seed", teamId: "t2", isActive: false });
    expect(createOverride).toHaveBeenCalledTimes(1);
  });

  it("« Tout le club » activates every team", async () => {
    overridesState.data = [{ id: "o2", teamId: "t2", isActive: false, sessionsPerWeek: null, calendarEntryId: "e1" }];
    render(<PeriodTeams calendarEntryId="e1" />);
    await userEvent.click(screen.getByRole("button", { name: "Tout le club" }));
    // The deactivated U13 override is removed → back to seasonal (active).
    expect(deleteOverride).toHaveBeenCalledWith("o2");
  });
});

describe("PeriodTeams — session guard", () => {
  it("ignores an emptied sessions field instead of persisting 0", async () => {
    overridesState.data = [{ id: "o2", teamId: "t2", isActive: false, sessionsPerWeek: null, calendarEntryId: "e1" }]; // suppress the seed
    render(<PeriodTeams calendarEntryId="e1" />);
    const input = screen.getByLabelText("Séances de SM1 cette période");
    await userEvent.clear(input); // → Number("") === 0
    expect(createOverride).not.toHaveBeenCalled();
    expect(updateOverride).not.toHaveBeenCalled();
  });
});

describe("PeriodVenues — borrowed period slots", () => {
  it("lists the period's borrowed slots and adds a new one", async () => {
    render(<PeriodVenues calendarEntryId="e1" />);
    expect(screen.getByText(/Gymnase A — Mer 20:00/)).toBeInTheDocument();

    await userEvent.selectOptions(screen.getByLabelText("Gymnase"), "v1");
    await userEvent.click(screen.getByRole("button", { name: "Ajouter" }));
    expect(createSlot).toHaveBeenCalledWith(expect.objectContaining({ venueId: "v1", dayOfWeek: 1, capacity: 1 }), expect.anything());
  });

  it("marks a gym closed for the period as INTERDIT", () => {
    conflictState.venueIds = ["v1"];
    render(<PeriodVenues calendarEntryId="e1" />);
    expect(screen.getByText(/INTERDIT cette période/)).toBeInTheDocument();
  });
});
