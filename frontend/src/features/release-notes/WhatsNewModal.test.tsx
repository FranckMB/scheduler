import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { ReleaseNotesResponse } from "./api";
import { getReleaseNotes, markReleaseNotesSeen } from "./api";
import { WhatsNewModal } from "./WhatsNewModal";

vi.mock("./api", async (importOriginal) => {
  const original = await importOriginal<typeof import("./api")>();
  return { ...original, getReleaseNotes: vi.fn(), markReleaseNotesSeen: vi.fn() };
});

const mockGet = vi.mocked(getReleaseNotes);
const mockSeen = vi.mocked(markReleaseNotesSeen);

const NOTE = {
  id: "n1",
  date: "2026-08-13",
  title: "Nouvelle vue planning",
  body: "Un récapitulatif plus lisible.",
  publishedAt: "2026-08-13T09:00:00+00:00",
};

function response(overrides: Partial<ReleaseNotesResponse> = {}): ReleaseNotesResponse {
  return { seenUpTo: "2026-08-01T00:00:00+00:00", items: [NOTE], ...overrides };
}

describe("WhatsNewModal", () => {
  beforeEach(() => {
    mockGet.mockReset();
    mockSeen.mockReset();
    mockSeen.mockResolvedValue(undefined);
  });

  it("s'ouvre quand une note a été publiée APRÈS le filigrane", async () => {
    mockGet.mockResolvedValue(response());
    renderWithProviders(<WhatsNewModal />);

    expect(await screen.findByRole("dialog", { name: /quoi de neuf/i })).toBeInTheDocument();
    expect(screen.getByText("Nouvelle vue planning")).toBeInTheDocument();
  });

  it("ne s'ouvre PAS pour un nouvel inscrit (seenUpTo null) et pose le filigrane en silence", async () => {
    mockGet.mockResolvedValue(response({ seenUpTo: null }));
    renderWithProviders(<WhatsNewModal />);

    // Le POST /seen part tout seul au premier chargement…
    await waitFor(() => expect(mockSeen).toHaveBeenCalledTimes(1));
    // …mais aucune modale ne s'affiche.
    expect(screen.queryByRole("dialog", { name: /quoi de neuf/i })).not.toBeInTheDocument();
  });

  it("ne s'ouvre pas quand aucune note n'est postérieure au filigrane", async () => {
    mockGet.mockResolvedValue(response({ seenUpTo: "2026-09-01T00:00:00+00:00" }));
    renderWithProviders(<WhatsNewModal />);

    await waitFor(() => expect(mockGet).toHaveBeenCalled());
    expect(screen.queryByRole("dialog", { name: /quoi de neuf/i })).not.toBeInTheDocument();
  });

  it("« J'ai compris » marque le journal lu et ferme la modale", async () => {
    const user = userEvent.setup();
    mockGet.mockResolvedValue(response());
    renderWithProviders(<WhatsNewModal />);

    await screen.findByRole("dialog", { name: /quoi de neuf/i });
    await user.click(screen.getByRole("button", { name: /j'ai compris/i }));

    expect(mockSeen).toHaveBeenCalled();
    await waitFor(() => expect(screen.queryByRole("dialog", { name: /quoi de neuf/i })).not.toBeInTheDocument());
  });
});
