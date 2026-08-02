import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import type { Fixture, Venue, VenueMatchWindow, VenueUnavailability } from "./api";
import type { EnvelopeResult } from "./lib/envelope";
import { PlacementPanel } from "./PlacementPanel";

const fixture: Fixture = {
  id: "fx-1",
  teamId: "team-1",
  seasonId: "s",
  competitionId: null,
  matchDate: "2026-10-03", // a Saturday
  homeAway: "HOME",
  opponentLabel: "Voisins",
  status: "UNPLACED",
  venueId: null,
  kickoffTime: null,
  externalRef: null,
  fbiVenueLabel: null,
};
const venues: Venue[] = [
  { id: "venue-1", name: "Gymnase Alpha", color: null },
  { id: "venue-2", name: "Gymnase Beta", color: null },
];

// Mapped envelope: 14:00 is inside, 20:00 is outside.
const mappedEnvelope: EnvelopeResult = {
  mapped: true,
  windows: [{ id: "w", league: "AURA", category: "U13", level: "DEPARTEMENTAL", gender: null, dayOfWeek: 6, kickoffMin: "13:00", kickoffMax: "18:00" }],
  dayOk: true,
  timeOk: (k) => "14:00" === k,
};
const openEnvelope: EnvelopeResult = { mapped: false, windows: [], dayOk: false, timeOk: () => false };

interface Overrides {
  matchWindows?: VenueMatchWindow[];
  unavailabilities?: VenueUnavailability[];
}

function renderPanel(envelope: EnvelopeResult, onPlace = vi.fn(), overrides: Overrides = {}) {
  render(
    <PlacementPanel
      fixture={fixture}
      venues={venues}
      matchWindows={overrides.matchWindows ?? []}
      unavailabilities={overrides.unavailabilities ?? []}
      teamLabel="U13"
      categoryLabel="U13"
      envelope={envelope}
      busy={false}
      onClose={vi.fn()}
      onPlace={onPlace}
    />,
  );
  return onPlace;
}

describe("PlacementPanel", () => {
  it("blocks placement out of the envelope when the team maps", async () => {
    const user = userEvent.setup();
    const onPlace = renderPanel(mappedEnvelope);

    await user.selectOptions(screen.getByLabelText("Gymnase"), "venue-1");
    await user.type(screen.getByLabelText("Heure de coup d'envoi"), "20:00");

    expect(screen.getByText(/Hors fenêtre autorisée/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Placer" })).toBeDisabled();
    expect(onPlace).not.toHaveBeenCalled();
  });

  it("allows and emits a placement inside the envelope", async () => {
    const user = userEvent.setup();
    const onPlace = renderPanel(mappedEnvelope);

    await user.selectOptions(screen.getByLabelText("Gymnase"), "venue-1");
    await user.type(screen.getByLabelText("Heure de coup d'envoi"), "14:00");

    const place = screen.getByRole("button", { name: "Placer" });
    expect(place).toBeEnabled();
    await user.click(place);
    expect(onPlace).toHaveBeenCalledWith({ venueId: "venue-1", kickoffTime: "14:00" });
  });

  it("never blocks an unmapped team (advisory only)", async () => {
    const user = userEvent.setup();
    const onPlace = renderPanel(openEnvelope);

    await user.selectOptions(screen.getByLabelText("Gymnase"), "venue-1");
    await user.type(screen.getByLabelText("Heure de coup d'envoi"), "23:00");

    expect(screen.getByRole("button", { name: "Placer" })).toBeEnabled();
    await user.click(screen.getByRole("button", { name: "Placer" }));
    expect(onPlace).toHaveBeenCalledOnce();
  });

  // ── Capacity guards (P1-4 PR B) ──────────────────────────────────────────

  it("only offers match venues once the club declared windows, and blocks outside them", async () => {
    const user = userEvent.setup();
    const onPlace = renderPanel(openEnvelope, vi.fn(), {
      // venue-1 is a match venue on Saturdays 14:00-18:00; venue-2 is training-only.
      matchWindows: [{ id: "w1", venueId: "venue-1", dayOfWeek: 6, startTime: "14:00", endTime: "18:00" }],
    });

    // The selector masks the CHOICE: training-only venue-2 is not offered.
    expect(screen.queryByRole("option", { name: "Gymnase Beta" })).not.toBeInTheDocument();

    await user.selectOptions(screen.getByLabelText("Gymnase"), "venue-1");
    await user.type(screen.getByLabelText("Heure de coup d'envoi"), "20:00");
    expect(screen.getByText(/Hors fenêtre d'accès match \(14:00–18:00\)/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Placer" })).toBeDisabled();
    expect(onPlace).not.toHaveBeenCalled();
  });

  it("blocks a day without any access window on the venue", async () => {
    const user = userEvent.setup();
    // Sunday-only window; the fixture is on a Saturday.
    renderPanel(openEnvelope, vi.fn(), {
      matchWindows: [{ id: "w1", venueId: "venue-1", dayOfWeek: 7, startTime: "09:00", endTime: "18:00" }],
    });

    await user.type(screen.getByLabelText("Heure de coup d'envoi"), "14:00");
    expect(screen.getByText(/Pas d'accès match le samedi/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Placer" })).toBeDisabled();
  });

  it("blocks an unavailable venue and keeps the full list when no window exists", async () => {
    const user = userEvent.setup();
    const onPlace = renderPanel(openEnvelope, vi.fn(), {
      // No window anywhere (club has not adopted the data) → full list, no
      // window guard — but the unavailability still bites.
      unavailabilities: [{ id: "u1", venueId: "venue-1", startDate: "2026-10-01", endDate: "2026-10-05", label: "travaux" }],
    });

    expect(screen.getByRole("option", { name: "Gymnase Beta" })).toBeInTheDocument();

    await user.selectOptions(screen.getByLabelText("Gymnase"), "venue-1");
    await user.type(screen.getByLabelText("Heure de coup d'envoi"), "14:00");
    expect(screen.getByText(/indisponible du 1 oct\. au 5 oct\. \(travaux\)/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Placer" })).toBeDisabled();

    // The other venue stays placeable.
    await user.selectOptions(screen.getByLabelText("Gymnase"), "venue-2");
    expect(screen.getByRole("button", { name: "Placer" })).toBeEnabled();
    await user.click(screen.getByRole("button", { name: "Placer" }));
    expect(onPlace).toHaveBeenCalledWith({ venueId: "venue-2", kickoffTime: "14:00" });
  });
});
