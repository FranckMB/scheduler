import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import * as api from "./api";
import { useWizardStore } from "./store";
import { WizardPage } from "./WizardLayout";

vi.mock("./api", () => ({
  listTeams: vi.fn(() => Promise.resolve([{ id: "t1", name: "SF1", sportCategoryId: "cat1", priorityTierId: 1, tierOrder: 0, gender: null, sessionsPerWeek: 2, isActive: true }])),
  listSportCategories: vi.fn(() => Promise.resolve([{ id: "cat1", name: "U11", sortOrder: 0 }])),
  listPriorityTiers: vi.fn(() => Promise.resolve([{ id: 1, label: "S", name: "Elite", color: null }, { id: 2, label: "A", name: "Régional", color: null }])),
  createTeam: vi.fn(() => Promise.resolve({})),
  updateTeam: vi.fn(() => Promise.resolve({})),
  deleteTeam: vi.fn(() => Promise.resolve()),
  listVenues: vi.fn(() => Promise.resolve([])),
  listVenueSlots: vi.fn(() => Promise.resolve([])),
  createVenue: vi.fn(() => Promise.resolve({})),
  updateVenue: vi.fn(() => Promise.resolve({})),
  deleteVenue: vi.fn(() => Promise.resolve()),
  createSlot: vi.fn(() => Promise.resolve({})),
  deleteSlot: vi.fn(() => Promise.resolve()),
  listCoaches: vi.fn(() => Promise.resolve([])),
  listTeamCoaches: vi.fn(() => Promise.resolve([])),
  listCoachPlayers: vi.fn(() => Promise.resolve([])),
  createCoach: vi.fn(() => Promise.resolve({})),
  updateCoach: vi.fn(() => Promise.resolve({})),
  deleteCoach: vi.fn(() => Promise.resolve()),
  createTeamCoach: vi.fn(() => Promise.resolve({})),
  deleteTeamCoach: vi.fn(() => Promise.resolve()),
  createCoachPlayer: vi.fn(() => Promise.resolve({})),
  deleteCoachPlayer: vi.fn(() => Promise.resolve()),
}));

beforeEach(() => {
  useWizardStore.setState({ stepId: "teams" });
  vi.mocked(api.listTeams).mockResolvedValue([{ id: "t1", name: "SF1", sportCategoryId: "cat1", priorityTierId: 1, tierOrder: 0, gender: null, sessionsPerWeek: 2, isActive: true }]);
});

describe("Wizard (integration)", () => {
  it("renders the Teams step with a team grouped under its tier", async () => {
    renderWithProviders(<WizardPage />, { route: "/wizard" });
    expect(await screen.findByDisplayValue("SF1")).toBeInTheDocument();
    expect(screen.getByText("S · Elite")).toBeInTheDocument();
  });

  it("advances to the next step via Suivant when the step is valid", async () => {
    const user = userEvent.setup();
    renderWithProviders(<WizardPage />, { route: "/wizard" });
    await screen.findByDisplayValue("SF1");

    await user.click(screen.getByRole("button", { name: "Suivant" }));
    expect(await screen.findByRole("heading", { name: "Gymnases" })).toBeInTheDocument();
  });

  it("blocks Suivant + shows an alert when there is no team", async () => {
    vi.mocked(api.listTeams).mockResolvedValue([]);
    renderWithProviders(<WizardPage />, { route: "/wizard" });

    expect(await screen.findByRole("alert")).toHaveTextContent("Ajoutez au moins une équipe");
    expect(screen.getByRole("button", { name: "Suivant" })).toBeDisabled();
  });
});
