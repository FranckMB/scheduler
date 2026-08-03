import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { FfbbEngagement, PriorityTier, Team } from "./api";
import { FfbbEngagementsDialog } from "./FfbbEngagementsDialog";

const { getFfbbEngagements, confirmFfbbPairings } = vi.hoisted(() => ({
  getFfbbEngagements: vi.fn(),
  confirmFfbbPairings: vi.fn(() => Promise.resolve()),
}));

vi.mock("./api", () => ({ getFfbbEngagements, confirmFfbbPairings }));

const teams: Team[] = [
  { id: "team-sm1", name: "SM1", sportCategoryId: "cat", level: null, gender: null, priorityTierId: 1, tierOrder: 0 },
  { id: "team-sm2", name: "SM2", sportCategoryId: "cat", level: null, gender: null, priorityTierId: 3, tierOrder: 0 },
];
const tiers: PriorityTier[] = [
  { id: 1, label: "S", name: "Fanion", color: null },
  { id: 3, label: "B", name: "Moyenne", color: null },
];

const engagement = (over: Partial<FfbbEngagement> = {}): FfbbEngagement => ({
  ffbbCompetitionId: "comp-1",
  ffbbCompetitionCode: "PRM",
  competitionName: "Pré régionale masculine",
  ffbbPouleId: "poule-b2",
  pouleName: "Poule B2",
  category: "Seniors",
  level: "Régional",
  gender: "Masculin",
  pouleSize: 8,
  pouleOpponents: [],
  suggestedTeamId: null,
  suggestedCompetitionId: null,
  ...over,
});

beforeEach(() => {
  getFfbbEngagements.mockReset();
  confirmFfbbPairings.mockClear();
});

describe("FfbbEngagementsDialog (P1-4 PR F)", () => {
  it("lists the engagements, requires a choice, and confirms in block", async () => {
    const user = userEvent.setup();
    getFfbbEngagements.mockResolvedValue({ engagements: [engagement()] });
    renderWithProviders(<FfbbEngagementsDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    expect(await screen.findByText("Pré régionale masculine")).toBeInTheDocument();
    expect(screen.getByText(/Poule B2 · 8 clubs/)).toBeInTheDocument();
    // League-data disclosure is MANDATORY (appariement §3).
    expect(screen.getByText(/Données de la ligue/)).toBeInTheDocument();
    // Nothing chosen yet → nothing to confirm.
    expect(screen.getByRole("button", { name: /Confirmer/ })).toBeDisabled();

    await user.selectOptions(screen.getByLabelText("Équipe pour Pré régionale masculine"), "team-sm2");
    await user.click(screen.getByRole("button", { name: "Confirmer 1 appariement" }));

    expect(confirmFfbbPairings).toHaveBeenCalledWith([{ ffbbCompetitionId: "comp-1", teamId: "team-sm2" }]);
  });

  it("pre-fills from the suggestion — next phases are 1 click", async () => {
    getFfbbEngagements.mockResolvedValue({ engagements: [engagement({ suggestedTeamId: "team-sm1" })] });
    renderWithProviders(<FfbbEngagementsDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    expect(await screen.findByLabelText("Équipe pour Pré régionale masculine")).toHaveValue("team-sm1");
    expect(screen.getByRole("button", { name: "Confirmer 1 appariement" })).toBeEnabled();
  });

  it("an emptied row is NOT sent — the absence of a link is the state", async () => {
    const user = userEvent.setup();
    getFfbbEngagements.mockResolvedValue({
      engagements: [engagement({ suggestedTeamId: "team-sm1" }), engagement({ ffbbCompetitionId: "comp-2", competitionName: "Coupe", suggestedTeamId: "team-sm2" })],
    });
    renderWithProviders(<FfbbEngagementsDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    await user.selectOptions(await screen.findByLabelText("Équipe pour Coupe"), "");
    await user.click(screen.getByRole("button", { name: "Confirmer 1 appariement" }));

    expect(confirmFfbbPairings).toHaveBeenCalledWith([{ ffbbCompetitionId: "comp-1", teamId: "team-sm1" }]);
  });

  it("FFBB down → named failure, no crash", async () => {
    getFfbbEngagements.mockRejectedValue(new Error("502"));
    renderWithProviders(<FfbbEngagementsDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    await waitFor(() => expect(screen.getByText(/FFBB indisponible/)).toBeInTheDocument());
  });
});
