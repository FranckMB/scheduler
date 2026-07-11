import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import { listSchedules, OverlaysExistError, reopenSchedule } from "./api";
import { PlanningPage } from "./PlanningPage";
import { usePlanningStore } from "./store";

// The api layer is mocked (not HTTP): ky + jsdom + MSW disagree on AbortSignal,
// so we exercise the screen from the api boundary down — queries, PlanningPage
// logic, the grid + all its panels — with fixture data.
const SID = "sched-1";

vi.mock("./api", () => {
  // A real error class so PlanningPage's `error instanceof OverlaysExistError`
  // escalation branch fires from the mocked reopen/validate rejections.
  class OverlaysExistError extends Error {
    constructor(public count: number, public overlays: unknown[]) {
      super("overlays");
      this.name = "OverlaysExistError";
    }
  }
  return {
  OverlaysExistError,
  reopenSchedule: vi.fn(),
  listSchedules: vi.fn(() => Promise.resolve([{ id: SID, name: "Planning A", status: "COMPLETED", score: 9051, createdAt: "2026-01-01T00:00:00Z", updatedAt: "2026-01-01T00:00:00Z", calendarEntryId: null }])),
  getSlots: vi.fn(() =>
    Promise.resolve([
      { id: "slot-1", scheduleId: SID, teamId: "team-1", venueId: "venue-1", coachId: null, dayOfWeek: 1, startTime: "18:00:00", durationMinutes: 90, lockLevel: "NONE", temporaryLock: false },
    ]),
  ),
  getDiagnostics: vi.fn(() =>
    Promise.resolve([
      { id: "diag-1", scheduleId: SID, type: "conflict", severity: "ERROR", teamId: null, venueId: "venue-1", coachId: null, message: "Conflit de gymnase.", suggestions: [] },
      // The solver's own "unused_slot" warning for the ts-2 empty window.
      { id: "diag-unused-slot-venue-1-2-19:00", scheduleId: SID, type: "unused_slot", severity: "WARNING", teamId: null, venueId: "venue-1", coachId: null, message: "Créneau disponible non utilisé : Gymnase Alpha (mardi de 19:00 à 20:30).", suggestions: [] },
    ]),
  ),
  getTeams: vi.fn(() => Promise.resolve([{ id: "team-1", name: "U11", sportCategoryId: "cat-1", priorityTierId: 1, tierOrder: 0 }])),
  getVenues: vi.fn(() => Promise.resolve([{ id: "venue-1", name: "Gymnase Alpha", color: "#00aa00" }])),
  // ts-1 matches slot-1 (filled) ; ts-2 is a defined-but-unfilled window → "vide".
  getTrainingSlots: vi.fn(() =>
    Promise.resolve([
      { id: "ts-1", venueId: "venue-1", dayOfWeek: 1, startTime: "18:00:00", durationMinutes: 90, capacity: 1 },
      { id: "ts-2", venueId: "venue-1", dayOfWeek: 2, startTime: "19:00:00", durationMinutes: 90, capacity: 1 },
    ]),
  ),
  getCoaches: vi.fn(() => Promise.resolve([{ id: "coach-1", firstName: "Jean", lastName: "Dupont" }])),
  getCategories: vi.fn(() => Promise.resolve([{ id: "cat-1", name: "U11" }])),
  getTeamCoaches: vi.fn(() => Promise.resolve([{ id: "tc-1", teamId: "team-1", coachId: "coach-1", role: "MAIN" }])),
  getCoachPlayers: vi.fn(() => Promise.resolve([])),
  lockSlot: vi.fn(),
  moveSlot: vi.fn(),
  generateSchedule: vi.fn(),
  validateSchedule: vi.fn(),
  STATUS_LABELS: { DRAFT: "Brouillon", PENDING: "En attente", GENERATING: "Génération…", COMPLETED: "Terminé", FAILED: "Échec", VALIDATED: "Validé" },
  };
});

const navigate = vi.fn();
vi.mock("react-router-dom", async (orig) => ({ ...(await orig<typeof import("react-router-dom")>()), useNavigate: () => navigate }));

const { meState } = vi.hoisted(() => ({ meState: { socleValidatedAt: null as string | null } }));

vi.mock("@/features/auth/queries", () => ({
  useMe: () => ({
    data: {
      id: "u1", membershipStatus: "active", role: "admin", club: { id: "c", name: "C" },
      baselineScheduleId: SID, socleValidatedAt: meState.socleValidatedAt,
      planningName: null, seasons: [{ id: "sn1", name: "2025-2026", startDate: "2025-09-01", endDate: "2026-06-30", isCurrent: true, isReadonly: false }], currentSeasonId: "sn1",
    },
  }),
  useRenamePlanning: () => ({ mutate: vi.fn(), isPending: false }),
}));

beforeEach(() => {
  meState.socleValidatedAt = null;
  navigate.mockClear();
  usePlanningStore.setState({ viewMode: "gymnase", selectedScheduleId: null, selectedSlotId: null, resourceFilter: [] });
});

describe("PlanningPage (integration)", () => {
  // The "validate to unlock the cockpit" banner moved to the cockpit itself
  // (state 2) — PlanningPage no longer carries it (see CockpitPage.test).

  it("renders the base planning grid: team + coach on the slot, main-plan badge", async () => {
    renderWithProviders(<PlanningPage />);

    expect(await screen.findByText("U11")).toBeInTheDocument();
    expect(await screen.findByText("Jean Dupont")).toBeInTheDocument();
    expect(screen.getByText("principal")).toBeInTheDocument();
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
    const user = userEvent.setup();
    renderWithProviders(<PlanningPage />);
    // Diagnostics collapsed by default → open the panel first (user request).
    await user.click(await screen.findByRole("button", { name: /Diagnostics du solveur/ }));
    const group = await screen.findByRole("button", { name: /Erreurs/ });
    expect(within(group).getByText("1")).toBeInTheDocument();
  });

  it("renders defined-but-unfilled windows as 'vide' cells alongside the solver's unused_slot warning", async () => {
    const user = userEvent.setup();
    renderWithProviders(<PlanningPage />);
    // ts-2 (Gymnase Alpha, Mardi 19:00) has no placement → a `vide` cell in the grid.
    expect(await screen.findByText("vide")).toBeInTheDocument();
    // The solver's own unused_slot warning is listed under "Alertes" (panel opened).
    await user.click(await screen.findByRole("button", { name: /Diagnostics du solveur/ }));
    const warnGroup = await screen.findByRole("button", { name: /Alertes/ });
    expect(within(warnGroup).getByText("1")).toBeInTheDocument();
  });

  // planning lifecycle (§7.1): reopening a VALIDATED plan that has period overlays
  // 409s; the UI escalates to a proportional confirm, then re-sends with the flag.
  describe("reopen escalation (validated plan with overlays)", () => {
    const validated = [{ id: SID, name: "Planning A", status: "VALIDATED", score: 9051, createdAt: "2026-01-01T00:00:00Z", updatedAt: "2026-01-01T00:00:00Z", calendarEntryId: null }];

    beforeEach(() => {
      vi.mocked(reopenSchedule).mockReset(); // per-test call count + queued once-values
    });

    afterEach(() => {
      vi.mocked(listSchedules).mockResolvedValue([{ id: SID, name: "Planning A", status: "COMPLETED", score: 9051, createdAt: "2026-01-01T00:00:00Z", updatedAt: "2026-01-01T00:00:00Z", calendarEntryId: null }]);
    });

    it("409 → confirm naming the overlay count → re-sends confirmDeleteOverlays", async () => {
      const user = userEvent.setup();
      vi.mocked(listSchedules).mockResolvedValue(validated);
      vi.mocked(reopenSchedule).mockRejectedValueOnce(new OverlaysExistError(2, [])).mockResolvedValueOnce({});
      renderWithProviders(<PlanningPage />);
      await screen.findByText("U11");

      await user.click(screen.getByRole("button", { name: /rouvrir/i }));
      // First reopen (no flag) → 409 → proportional confirm dialog.
      expect(await screen.findByText(/supprimera 2 plannings secondaires/i)).toBeInTheDocument();

      await user.click(screen.getByRole("button", { name: "Rouvrir et supprimer" }));
      expect(vi.mocked(reopenSchedule)).toHaveBeenCalledTimes(2);
      expect(vi.mocked(reopenSchedule).mock.calls[1]).toEqual([SID, { confirmDeleteOverlays: true }]);
      // Reopened → back to the wizard's generation step.
      expect(navigate).toHaveBeenCalledWith("/wizard");
    });

    it("« Rouvrir » (no overlays) → wizard generation step", async () => {
      const user = userEvent.setup();
      vi.mocked(listSchedules).mockResolvedValue(validated);
      vi.mocked(reopenSchedule).mockResolvedValueOnce({});
      renderWithProviders(<PlanningPage />);
      await screen.findByText("U11");

      await user.click(screen.getByRole("button", { name: /rouvrir/i }));
      expect(vi.mocked(reopenSchedule)).toHaveBeenCalledTimes(1);
      expect(navigate).toHaveBeenCalledWith("/wizard");
    });
  });

  it("« Valider » → lands on the planning view", async () => {
    const user = userEvent.setup();
    renderWithProviders(<PlanningPage />);
    await screen.findByText("U11");

    // Toolbar "Valider" opens the confirm dialog; confirm inside it.
    await user.click(screen.getByRole("button", { name: /valider/i }));
    await user.click(within(screen.getByRole("dialog")).getByRole("button", { name: "Valider" }));
    expect(navigate).toHaveBeenCalledWith("/planning");
  });
});
