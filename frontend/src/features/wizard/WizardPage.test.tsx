import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

// Established club (a main plan exists) → free wizard navigation, not guided.
vi.mock("@/features/auth/queries", () => ({
  useMe: () => ({ data: { seasonPlan: { id: "p1", name: "Planning", chosenScheduleId: "b1", hasFinishedVersion: true }, club: { id: "c", name: "C", onboardingCompleted: true } } }),
}));

// Garde d'abandon de période (retour fondateur 2026-07-18) : contrôle des
// données plan/versions par variables — le défaut (plan vide) arme le dialogue.
// La confirmation re-lit le serveur (fetchQuery → listSchedules) : `freshSchedules`
// pilote cette lecture FRAÎCHE, indépendamment du cache affiché (`schedulesData`).
const deleteEntryMutateAsync = vi.fn(() => Promise.resolve({}));
let periodPlanId: string | null = "plan-x";
let schedulesData: { schedulePlanId: string }[] | undefined = [];
let freshSchedules: { schedulePlanId: string }[] = [];
vi.mock("@/features/cockpit/queries", async (orig) => ({
  ...(await orig<typeof import("@/features/cockpit/queries")>()),
  useCalendarEntry: () => ({ data: { id: "entry-x", title: "Vacances de la Toussaint", startDate: "2026-10-16", endDate: "2026-10-31" }, error: null }),
  usePeriodAnchor: () => ({ planId: periodPlanId, ready: null !== periodPlanId, isLoading: false }),
  useDeleteEntry: () => ({ mutate: vi.fn(), mutateAsync: deleteEntryMutateAsync, isPending: false }),
}));
vi.mock("@/features/planning/queries", async (orig) => ({
  ...(await orig<typeof import("@/features/planning/queries")>()),
  useSchedules: () => ({ data: schedulesData }),
}));
vi.mock("@/features/planning/api", async (orig) => ({
  ...(await orig<typeof import("@/features/planning/api")>()),
  listSchedules: vi.fn(() => Promise.resolve(freshSchedules)),
}));

import * as api from "./api";
import { useWizardStore } from "./store";
import { WizardPage } from "./WizardLayout";

vi.mock("./api", async (orig) => ({
  // Partiel : les steps non ciblés (Contraintes en mode période) touchent d'autres
  // exports — un mock total ferait THROW tout l'arbre via l'ErrorBoundary du router.
  ...(await orig<typeof import("./api")>()),
  listTeams: vi.fn(() => Promise.resolve([{ id: "t1", name: "SF1", sportCategoryId: "cat1", priorityTierId: 1, tierOrder: 0, gender: null, level: null, sessionsPerWeek: 2, isActive: true }])),
  listSportCategories: vi.fn(() => Promise.resolve([{ id: "cat1", name: "U11", sortOrder: 0 }])),
  listPriorityTiers: vi.fn(() => Promise.resolve([{ id: 1, label: "S", name: "Elite", color: null }, { id: 2, label: "A", name: "Régional", color: null }])),
  createTeam: vi.fn(() => Promise.resolve({})),
  updateTeam: vi.fn(() => Promise.resolve({})),
  reorderTeams: vi.fn(() => Promise.resolve({})),
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
  listConstraints: vi.fn(() => Promise.resolve([])),
  createConstraint: vi.fn(() => Promise.resolve({})),
  deleteConstraint: vi.fn(() => Promise.resolve()),
  validateConstraints: vi.fn(() => Promise.resolve({ valid: true, errors: {}, conflicts: [] })),
  createSchedule: vi.fn(() => Promise.resolve({ id: "s1" })),
  generateSchedule: vi.fn(() => Promise.resolve({})),
}));

beforeEach(() => {
  useWizardStore.setState({ stepId: "teams", mode: "season", calendarEntryId: null });
  vi.mocked(api.listTeams).mockResolvedValue([{ id: "t1", name: "SF1", sportCategoryId: "cat1", priorityTierId: 1, tierOrder: 0, gender: null, level: null, sessionsPerWeek: 2, isActive: true }]);
  deleteEntryMutateAsync.mockClear();
  periodPlanId = "plan-x";
  schedulesData = [];
  freshSchedules = [];
});

describe("Wizard (integration)", () => {
  it("renders the Teams step with a team grouped under its tier", async () => {
    renderWithProviders(<WizardPage />, { route: "/wizard" });
    expect(await screen.findByDisplayValue("SF1")).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "S · Fanion" })).toBeInTheDocument();
  });

  it("advances to the next step via Suivant when the step is valid", async () => {
    const user = userEvent.setup();
    renderWithProviders(<WizardPage />, { route: "/wizard" });
    await screen.findByDisplayValue("SF1");

    await user.click(screen.getByRole("button", { name: "Suivant" }));
    // Assert on the Venues step BODY (its own control), not the store-derived
    // sticky header, so the test still proves the step component actually rendered.
    expect(await screen.findByLabelText("Nom du gymnase")).toBeInTheDocument();
  });

  it("blocks Suivant + shows an alert when there is no team", async () => {
    vi.mocked(api.listTeams).mockResolvedValue([]);
    renderWithProviders(<WizardPage />, { route: "/wizard" });

    expect(await screen.findByRole("alert")).toHaveTextContent("Ajoutez au moins une équipe");
    expect(screen.getByRole("button", { name: "Suivant" })).toBeDisabled();
  });

  // Bug Toussaint (retour fondateur 2026-07-18) : « Adapter » crée la période
  // AVANT le wizard ; repartir sans rien générer laissait une entrée orpheline.
  it("quitting an untouched period adjustment offers to remove the period, and deletes on confirm", async () => {
    const user = userEvent.setup();
    useWizardStore.setState({ mode: "period", calendarEntryId: "entry-x", stepId: "constraints" });
    renderWithProviders(<WizardPage />, { route: "/wizard" });

    await user.click(await screen.findByRole("button", { name: /Quitter/ }));
    // Rien n'est supprimé sans confirmation explicite.
    expect(deleteEntryMutateAsync).not.toHaveBeenCalled();
    expect(await screen.findByRole("dialog")).toHaveTextContent("Abandonner l'ajustement ?");

    await user.click(screen.getByRole("button", { name: "Retirer la période" }));
    // La confirmation re-vérifie le serveur (lecture fraîche) puis supprime.
    await waitFor(() => expect(deleteEntryMutateAsync).toHaveBeenCalledWith("entry-x"));
  });

  it("confirm does NOT delete when the fresh server read reveals a version launched meanwhile", async () => {
    // Le cache dit « vide » (dialogue armé) mais le serveur, relu à la confirmation,
    // a la version lancée entre-temps → période CONSERVÉE (revue #260 round 1 :
    // supprimer sur le cache détruirait la génération en vol via la cascade).
    const user = userEvent.setup();
    freshSchedules = [{ schedulePlanId: "plan-x" }];
    useWizardStore.setState({ mode: "period", calendarEntryId: "entry-x", stepId: "constraints" });
    renderWithProviders(<WizardPage />, { route: "/wizard" });

    await user.click(await screen.findByRole("button", { name: /Quitter/ }));
    await user.click(await screen.findByRole("button", { name: "Retirer la période" }));
    await waitFor(() => expect(screen.queryByRole("dialog")).not.toBeInTheDocument());
    expect(deleteEntryMutateAsync).not.toHaveBeenCalled();
  });

  it("quitting a period whose plan HAS versions leaves silently (no dialog, nothing deleted)", async () => {
    const user = userEvent.setup();
    schedulesData = [{ schedulePlanId: "plan-x" }];
    useWizardStore.setState({ mode: "period", calendarEntryId: "entry-x", stepId: "constraints" });
    renderWithProviders(<WizardPage />, { route: "/wizard" });

    await user.click(await screen.findByRole("button", { name: /Quitter/ }));
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    expect(deleteEntryMutateAsync).not.toHaveBeenCalled();
  });

  it("enters sort mode from the footer « Trier » button and shows the tier drop zones", async () => {
    const user = userEvent.setup();
    renderWithProviders(<WizardPage />, { route: "/wizard" });
    await screen.findByDisplayValue("SF1");

    await user.click(await screen.findByRole("button", { name: /trier/i }));
    expect(await screen.findByRole("button", { name: /terminer le tri/i })).toBeInTheDocument();
    expect(screen.getByText(/par sa poignée/i)).toBeInTheDocument();
  });
});
