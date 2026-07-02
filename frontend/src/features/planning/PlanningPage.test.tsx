import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import { PlanningPage } from "./PlanningPage";
import { usePlanningStore } from "./store";

// The api layer is mocked (not HTTP): ky + jsdom + MSW disagree on AbortSignal,
// so we exercise the screen from the api boundary down — queries, PlanningPage
// logic, the grid + all its panels — with fixture data.
const SID = "sched-1";

vi.mock("./api", () => ({
  listSchedules: vi.fn(() => Promise.resolve([{ id: SID, name: "Planning A", status: "COMPLETED", score: 9051, createdAt: "2026-01-01T00:00:00Z", updatedAt: "2026-01-01T00:00:00Z" }])),
  getSlots: vi.fn(() =>
    Promise.resolve([
      { id: "slot-1", scheduleId: SID, teamId: "team-1", venueId: "venue-1", coachId: null, dayOfWeek: 1, startTime: "18:00:00", durationMinutes: 90, lockLevel: "NONE", temporaryLock: false },
    ]),
  ),
  getDiagnostics: vi.fn(() =>
    Promise.resolve([{ id: "diag-1", scheduleId: SID, type: "conflict", severity: "ERROR", teamId: null, venueId: "venue-1", coachId: null, message: "Conflit de gymnase.", suggestions: [] }]),
  ),
  getTeams: vi.fn(() => Promise.resolve([{ id: "team-1", name: "U11", sportCategoryId: "cat-1" }])),
  getVenues: vi.fn(() => Promise.resolve([{ id: "venue-1", name: "Gymnase Alpha", color: "#00aa00" }])),
  getCoaches: vi.fn(() => Promise.resolve([{ id: "coach-1", firstName: "Jean", lastName: "Dupont" }])),
  getCategories: vi.fn(() => Promise.resolve([{ id: "cat-1", name: "U11" }])),
  getTeamCoaches: vi.fn(() => Promise.resolve([{ id: "tc-1", teamId: "team-1", coachId: "coach-1", role: "MAIN" }])),
  getCoachPlayers: vi.fn(() => Promise.resolve([])),
  lockSlot: vi.fn(),
  moveSlot: vi.fn(),
  generateSchedule: vi.fn(),
  validateSchedule: vi.fn(),
}));

vi.mock("@/features/auth/queries", () => ({
  useMe: () => ({ data: { id: "u1", membershipStatus: "active", role: "admin", club: { id: "c", name: "C" }, baselineScheduleId: SID } }),
}));

beforeEach(() => {
  usePlanningStore.setState({ viewMode: "gymnase", selectedScheduleId: null, selectedSlotId: null, resourceFilter: [] });
});

describe("PlanningPage (integration)", () => {
  it("renders the base planning grid: team + coach on the slot, main-plan badge", async () => {
    renderWithProviders(<PlanningPage />);

    expect(await screen.findByText("U11")).toBeInTheDocument();
    expect(await screen.findByText("Jean Dupont")).toBeInTheDocument();
    expect(screen.getByText("Planning principal")).toBeInTheDocument();
    expect(screen.getByText(/score 9051/i)).toBeInTheDocument();
    // A COMPLETED schedule offers validation (→ VALIDATED, read-only).
    expect(screen.getByRole("button", { name: /valider/i })).toBeInTheDocument();
  });

  it("switches to the coach view (coach resolved from the team)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<PlanningPage />);
    await screen.findByText("U11");

    await user.click(screen.getByRole("button", { name: "Par coach" }));

    // The coach becomes a column header; the slot's secondary line is now the venue.
    expect(await screen.findAllByText("Jean Dupont")).not.toHaveLength(0);
    expect(await screen.findByText("Gymnase Alpha")).toBeInTheDocument();
  });

  it("opens the slot detail on click", async () => {
    const user = userEvent.setup();
    renderWithProviders(<PlanningPage />);
    await user.click(await screen.findByText("U11"));

    expect(await screen.findByText("Catégorie")).toBeInTheDocument();
    expect(screen.getByText("90 min")).toBeInTheDocument();
  });

  it("groups diagnostics by severity", async () => {
    renderWithProviders(<PlanningPage />);
    const group = await screen.findByRole("button", { name: /Erreurs/ });
    expect(within(group).getByText("1")).toBeInTheDocument();
  });
});
