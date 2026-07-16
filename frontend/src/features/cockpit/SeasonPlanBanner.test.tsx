import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { describe, expect, it, vi } from "vitest";

import type { Schedule } from "@/features/planning/api";

// Stub the modal (its own deps — ExportMenu/useVenues/store — are tested apart).
vi.mock("./SeasonSchedulesModal", () => ({
  SeasonSchedulesModal: ({ onClose }: { onClose: () => void }) => (
    <div role="dialog">
      Plannings de la saison
      <button onClick={onClose}>Fermer</button>
    </div>
  ),
}));
vi.mock("./seasonPlannings", () => ({ seasonPlanCounts: () => ({ total: 2, overlays: 1 }) }));

const navigate = vi.fn();
vi.mock("react-router-dom", async (orig) => ({ ...(await orig<typeof import("react-router-dom")>()), useNavigate: () => navigate }));

const chosen: Schedule = { id: "b1", name: "Socle", status: "COMPLETED", score: 9011, createdAt: "", updatedAt: "", calendarEntryId: null, isChosen: true };

import { SeasonPlanBanner } from "./SeasonPlanBanner";

function renderBanner() {
  return render(
    <MemoryRouter>
      <SeasonPlanBanner schedules={[chosen]} socleValidated />
    </MemoryRouter>,
  );
}

describe("SeasonPlanBanner", () => {
  it("offers only « Ouvrir » (no « Modifier… » — modification happens on the planning page)", () => {
    renderBanner();
    expect(screen.getByRole("button", { name: "Ouvrir" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Modifier/ })).not.toBeInTheDocument();
  });

  it("« Ouvrir » navigates to the planning (validated socle)", async () => {
    renderBanner();
    await userEvent.click(screen.getByRole("button", { name: "Ouvrir" }));
    expect(navigate).toHaveBeenCalledWith("/planning");
  });

  it("« Tous les plannings (N) » opens the plannings modal, counting distinct plannings", async () => {
    renderBanner();
    await userEvent.click(screen.getByRole("button", { name: /Tous les plannings \(2\)/ }));
    expect(screen.getByRole("dialog")).toHaveTextContent("Plannings de la saison");
  });
});
