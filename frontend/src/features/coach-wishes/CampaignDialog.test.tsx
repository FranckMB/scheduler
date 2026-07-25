import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { CalendarEntry } from "@/features/cockpit/api";

import type { CoachWishCampaign } from "./campaignApi";

vi.mock("@/features/wizard/queries", () => ({
  useWizardTeams: () => ({ data: [{ id: "t1", name: "SM1", isActive: true }, { id: "t2", name: "U13", isActive: true }] }),
  useWizardTeamCoaches: () => ({ data: [{ id: "tc1", teamId: "t1", coachId: "c1", role: "MAIN" }] }),
  useUpdateCoach: () => ({ mutate: vi.fn() }),
}));

const createMut = vi.fn();
const updateMut = vi.fn();
vi.mock("./campaignQueries", () => ({
  useCreateCoachWishCampaign: () => ({ mutate: createMut, isPending: false, isError: false }),
  useUpdateCoachWishCampaign: () => ({ mutate: updateMut, isPending: false, isError: false }),
}));

const copyMock = vi.fn().mockResolvedValue(true);
vi.mock("@/shared/lib/clipboard", () => ({ copyToClipboard: (t: string) => copyMock(t) }));

import { CampaignDialog } from "./CampaignDialog";

const entry: CalendarEntry = {
  id: "e1",
  kind: "period",
  title: "Vacances de février",
  startDate: "2026-02-16",
  endDate: "2026-03-01",
  isDisruptive: false,
  periodType: "holiday",
  schoolHolidayId: null,
  parentEntryId: null,
  status: "active",
  createdBy: null,
};
const season = { startDate: "2025-09-01", endDate: "2026-06-30" };

describe("CampaignDialog", () => {
  beforeEach(() => {
    createMut.mockReset();
    updateMut.mockReset();
    copyMock.mockClear();
  });

  it("crée une campagne avec les semaines et équipes choisies", async () => {
    render(<CampaignDialog entry={entry} season={season} existing={null} onClose={vi.fn()} />);

    // Seules les équipes AVEC coach sont proposées (t1 ; t2 sans coach est masquée).
    expect(screen.getByLabelText("SM1")).toBeInTheDocument();
    expect(screen.queryByLabelText("U13")).not.toBeInTheDocument();

    await userEvent.click(screen.getByLabelText("SM1"));
    await userEvent.click(screen.getByRole("button", { name: /Créer la collecte/ }));

    expect(createMut).toHaveBeenCalledTimes(1);
    const body = createMut.mock.calls[0][0];
    expect(body.calendarEntryId).toBe("e1");
    expect(body.teamIds).toEqual(["t1"]);
    expect(body.weeks.length).toBeGreaterThan(0);
    expect(body.deadline).toBe("2026-02-16");
  });

  it("copie le lien personnel d'un coach", async () => {
    const existing: CoachWishCampaign = {
      id: "camp1",
      calendarEntryId: "e1",
      deadline: "2027-06-30",
      weeks: ["2026-02-16"],
      teamIds: ["t1"],
      totalCoachCount: 1,
      respondedCoachCount: 0,
      openWishCount: 0,
      coaches: [{ coachId: "c1", firstName: "Maxime", lastName: "Durand", email: null, token: "a".repeat(64), respondedAt: null }],
    };
    render(<CampaignDialog entry={entry} season={season} existing={existing} onClose={vi.fn()} />);

    await userEvent.click(screen.getByRole("button", { name: /Copier le lien/ }));
    expect(copyMock).toHaveBeenCalledWith(`${window.location.origin}/doleances/${"a".repeat(64)}`);
  });
});
