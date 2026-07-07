import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import type { Fixture, Venue } from "./api";
import type { EnvelopeResult } from "./lib/envelope";
import { PlacementPanel } from "./PlacementPanel";

const fixture: Fixture = {
  id: "fx-1",
  teamId: "team-1",
  seasonId: "s",
  competitionId: null,
  matchDate: "2026-10-03",
  homeAway: "HOME",
  opponentLabel: "Voisins",
  status: "UNPLACED",
  venueId: null,
  kickoffTime: null,
  externalRef: null,
};
const venues: Venue[] = [{ id: "venue-1", name: "Gymnase Alpha", color: null }];

// Mapped envelope: 14:00 is inside, 20:00 is outside.
const mappedEnvelope: EnvelopeResult = {
  mapped: true,
  windows: [{ id: "w", league: "AURA", category: "U13", level: "DEPARTEMENTAL", gender: null, dayOfWeek: 6, kickoffMin: "13:00", kickoffMax: "18:00" }],
  dayOk: true,
  timeOk: (k) => "14:00" === k,
};

function renderPanel(envelope: EnvelopeResult, onPlace = vi.fn()) {
  render(<PlacementPanel fixture={fixture} venues={venues} teamLabel="U13" categoryLabel="U13" envelope={envelope} busy={false} onClose={vi.fn()} onPlace={onPlace} />);
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
    const onPlace = renderPanel({ mapped: false, windows: [], dayOk: false, timeOk: () => false });

    await user.selectOptions(screen.getByLabelText("Gymnase"), "venue-1");
    await user.type(screen.getByLabelText("Heure de coup d'envoi"), "23:00");

    expect(screen.getByRole("button", { name: "Placer" })).toBeEnabled();
    await user.click(screen.getByRole("button", { name: "Placer" }));
    expect(onPlace).toHaveBeenCalledOnce();
  });
});
