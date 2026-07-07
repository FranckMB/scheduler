import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import { RedateEventsDialog } from "./RedateEventsDialog";

const { getSeasonEvents, redateEvent } = vi.hoisted(() => ({
  getSeasonEvents: vi.fn(),
  redateEvent: vi.fn(() => Promise.resolve({})),
}));

vi.mock("./api", () => ({ getSeasonEvents, redateEvent }));

const EVENT = {
  id: "ev-1",
  kind: "event",
  title: "AG du club",
  startDate: "2026-10-03", // Saturday
  endDate: "2026-10-03",
  isDisruptive: true,
  periodType: null,
  schoolHolidayId: null,
  status: "active",
  overlayScheduleId: null,
  createdBy: null,
};

function renderDialog(onClose = vi.fn()) {
  renderWithProviders(<RedateEventsDialog sourceSeasonId="season-n" targetSeasonId="season-n1" targetSeasonName="2027" onClose={onClose} />);
  return onClose;
}

beforeEach(() => {
  getSeasonEvents.mockReset();
  redateEvent.mockClear();
});

describe("RedateEventsDialog", () => {
  it("suggests +364 days (same weekday) and re-dates the kept event into the target season", async () => {
    getSeasonEvents.mockImplementation((seasonId: string) => Promise.resolve("season-n" === seasonId ? [EVENT] : []));
    const user = userEvent.setup();
    const onClose = renderDialog();

    expect(await screen.findByText("AG du club")).toBeInTheDocument();
    // Suggested date: 2026-10-03 + 364 = 2027-10-02 (Saturday preserved).
    expect(screen.getByLabelText("Nouvelle date de début de AG du club")).toHaveValue("2027-10-02");

    await user.click(screen.getByRole("button", { name: /Reconduire 1 événement/ }));

    await waitFor(() => expect(redateEvent).toHaveBeenCalledOnce());
    expect(redateEvent).toHaveBeenCalledWith("season-n1", {
      title: "AG du club",
      startDate: "2027-10-02",
      endDate: "2027-10-02",
      isDisruptive: true,
    });
    await waitFor(() => expect(onClose).toHaveBeenCalled());
  });

  it("does not post an unchecked event ('Plus tard' posts nothing)", async () => {
    getSeasonEvents.mockImplementation((seasonId: string) => Promise.resolve("season-n" === seasonId ? [EVENT] : []));
    const user = userEvent.setup();
    const onClose = renderDialog();

    await screen.findByText("AG du club");
    await user.click(screen.getByLabelText("Garder AG du club"));
    // All unchecked → submit disabled.
    expect(screen.getByRole("button", { name: /Reconduire 0 événement/ })).toBeDisabled();

    await user.click(screen.getByRole("button", { name: "Plus tard" }));
    expect(redateEvent).not.toHaveBeenCalled();
    expect(onClose).toHaveBeenCalled();
  });

  it("auto-skips when the source season has no event", async () => {
    getSeasonEvents.mockResolvedValue([]);
    const onClose = renderDialog();

    await waitFor(() => expect(onClose).toHaveBeenCalled());
    expect(redateEvent).not.toHaveBeenCalled();
  });

  it("auto-skips when the target season already has events (step done)", async () => {
    getSeasonEvents.mockImplementation((seasonId: string) =>
      Promise.resolve("season-n" === seasonId ? [EVENT] : [{ ...EVENT, id: "ev-target" }]),
    );
    const onClose = renderDialog();

    await waitFor(() => expect(onClose).toHaveBeenCalled());
    expect(redateEvent).not.toHaveBeenCalled();
  });

  it("keeps a failed row listed with the server message", async () => {
    getSeasonEvents.mockImplementation((seasonId: string) => Promise.resolve("season-n" === seasonId ? [EVENT] : []));
    redateEvent.mockRejectedValueOnce(new Error("Saison archivée"));
    const user = userEvent.setup();
    const onClose = renderDialog();

    await screen.findByText("AG du club");
    await user.click(screen.getByRole("button", { name: /Reconduire 1 événement/ }));

    // errorMessage() maps a plain Error to its network fallback — the point is
    // that the row shows a message and the dialog stays open.
    await waitFor(() => expect(screen.getByText(/Problème de connexion/)).toBeInTheDocument());
    expect(onClose).not.toHaveBeenCalled();
  });

  it("stays open on a PARTIAL failure even though the target now has events", async () => {
    // Two kept events: A succeeds (the draft now has an event → a refetch of the
    // target query must NOT auto-close the dialog), B fails and must stay
    // visible with its error. The open/skip decision is frozen at first settle.
    const eventB = { ...EVENT, id: "ev-2", title: "Tournoi" };
    getSeasonEvents.mockImplementation((seasonId: string) => Promise.resolve("season-n" === seasonId ? [EVENT, eventB] : []));
    // First call (A) succeeds, second (B) fails.
    redateEvent.mockResolvedValueOnce({}).mockRejectedValueOnce(new Error("invalide"));
    const user = userEvent.setup();
    const onClose = renderDialog();

    await screen.findByText("AG du club"); // decision "show" frozen here
    // From now on the target query returns the freshly created event — the
    // post-submit invalidation refetch must NOT re-decide and auto-close.
    getSeasonEvents.mockImplementation((seasonId: string) =>
      Promise.resolve("season-n" === seasonId ? [EVENT, eventB] : [{ ...EVENT, id: "created-in-target" }]),
    );
    await user.click(screen.getByRole("button", { name: /Reconduire 2 événements/ }));

    await waitFor(() => expect(screen.getByText(/Problème de connexion/)).toBeInTheDocument());
    expect(onClose).not.toHaveBeenCalled();
  });

  it("shows an error state (not a silent skip) when the source query fails", async () => {
    getSeasonEvents.mockImplementation((seasonId: string) =>
      "season-n" === seasonId ? Promise.reject(new Error("500")) : Promise.resolve([]),
    );
    const onClose = renderDialog();

    await waitFor(() => expect(screen.getByText(/Impossible de charger les événements/)).toBeInTheDocument());
    expect(onClose).not.toHaveBeenCalled();
    expect(redateEvent).not.toHaveBeenCalled();
  });

  it("disables the submit when a kept date is emptied", async () => {
    getSeasonEvents.mockImplementation((seasonId: string) => Promise.resolve("season-n" === seasonId ? [EVENT] : []));
    const user = userEvent.setup();
    renderDialog();

    await screen.findByText("AG du club");
    await user.clear(screen.getByLabelText("Nouvelle date de début de AG du club"));

    expect(screen.getByText(/Renseignez les deux dates/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Reconduire 1 événement/ })).toBeDisabled();
  });
});
