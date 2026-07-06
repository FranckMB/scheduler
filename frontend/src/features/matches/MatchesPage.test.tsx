import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import { MatchesPage } from "./MatchesPage";
import { useMatchesStore } from "./store";

const { placeFixture } = vi.hoisted(() => ({ placeFixture: vi.fn(() => Promise.resolve({})) }));

vi.mock("./api", () => ({
  getFixtures: vi.fn(() =>
    Promise.resolve([
      { id: "fx-unplaced", teamId: "team-1", seasonId: "s", competitionId: null, matchDate: "2026-10-03", homeAway: "HOME", opponentLabel: "Voisins", status: "UNPLACED", venueId: null, kickoffTime: null },
      { id: "fx-placed", teamId: "team-2", seasonId: "s", competitionId: null, matchDate: "2026-10-03", homeAway: "HOME", opponentLabel: "Rivaux", status: "PLACED", venueId: "venue-1", kickoffTime: "16:00" },
    ]),
  ),
  getCompetitions: vi.fn(() => Promise.resolve([])),
  getTeams: vi.fn(() =>
    Promise.resolve([
      { id: "team-1", name: "U13", sportCategoryId: "cat-1", level: null, gender: null },
      { id: "team-2", name: "Seniors", sportCategoryId: "cat-2", level: null, gender: null },
    ]),
  ),
  getVenues: vi.fn(() => Promise.resolve([{ id: "venue-1", name: "Gymnase Alpha", color: "#00aa00" }])),
  getCategories: vi.fn(() => Promise.resolve([{ id: "cat-1", name: "U13" }, { id: "cat-2", name: "Seniors" }])),
  getCoaches: vi.fn(() => Promise.resolve([{ id: "coach-1", firstName: "Jean", lastName: "Dupont" }])),
  getLeagueWindows: vi.fn(() => Promise.resolve({ league: "AURA", items: [] })),
  getConflicts: vi.fn(() =>
    Promise.resolve({
      clubId: "c",
      seasonId: "s",
      conflicts: [
        {
          type: "MATCH_MATCH",
          coachId: "coach-1",
          start: "2026-10-03T15:30:00+00:00",
          end: "2026-10-03T16:00:00+00:00",
          left: { fixtureId: "fx-unplaced", teamId: "team-1", homeAway: "HOME", matchDate: "2026-10-03", kickoffTime: null, windowStart: "", windowEnd: "" },
          right: { fixtureId: "fx-placed", teamId: "team-2", homeAway: "HOME", matchDate: "2026-10-03", kickoffTime: "16:00", windowStart: "", windowEnd: "" },
        },
      ],
    }),
  ),
  createFixture: vi.fn(() => Promise.resolve({})),
  placeFixture,
}));

beforeEach(() => {
  placeFixture.mockClear();
  useMatchesStore.setState({ selectedWeekend: null, selectedFixtureId: null, fixtureFormOpen: false });
});

describe("MatchesPage (integration)", () => {
  it("lists the unplaced home match and renders the conflict radar", async () => {
    renderWithProviders(<MatchesPage />);

    // Unplaced to-do list.
    expect(await screen.findByText("U13")).toBeInTheDocument();
    // Radar shows the same-coach conflict.
    expect(await screen.findByText("Jean Dupont")).toBeInTheDocument();
    expect(screen.getByText(/Deux matchs/)).toBeInTheDocument();
    // Placed match is on the grid.
    expect(screen.getByText("Seniors")).toBeInTheDocument();
  });

  it("opens the placement panel and places a home fixture (venue + kickoff)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<MatchesPage />);

    await user.click(await screen.findByText("U13"));

    const venue = await screen.findByLabelText("Gymnase");
    await user.selectOptions(venue, "venue-1");
    await user.type(screen.getByLabelText("Heure de coup d'envoi"), "15:00");
    await user.click(screen.getByRole("button", { name: "Placer" }));

    expect(placeFixture).toHaveBeenCalledOnce();
    expect(placeFixture).toHaveBeenCalledWith(expect.objectContaining({ id: "fx-unplaced" }), { venueId: "venue-1", kickoffTime: "15:00" });
  });
});
