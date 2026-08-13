import { screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import { getReleaseNotes } from "./api";
import { ReleaseNotesPage } from "./ReleaseNotesPage";

vi.mock("./api", async (importOriginal) => {
  const original = await importOriginal<typeof import("./api")>();
  return { ...original, getReleaseNotes: vi.fn(), markReleaseNotesSeen: vi.fn().mockResolvedValue(undefined) };
});

const mockGet = vi.mocked(getReleaseNotes);

describe("ReleaseNotesPage", () => {
  beforeEach(() => mockGet.mockReset());

  it("liste les nouveautés datées, corps sur plusieurs lignes", async () => {
    mockGet.mockResolvedValue({
      seenUpTo: null,
      items: [
        { id: "n1", date: "2026-08-13", title: "Vue planning", body: "Ligne 1\nLigne 2", publishedAt: "2026-08-13T09:00:00+00:00" },
      ],
    });
    renderWithProviders(<ReleaseNotesPage />);

    expect(await screen.findByText("Vue planning")).toBeInTheDocument();
    // Le corps est rendu tel quel (whitespace-pre-line préserve le retour ligne).
    const body = screen.getByText(/Ligne 1/);
    expect(body).toHaveClass("whitespace-pre-line");
  });

  it("affiche un état vide en français quand il n'y a aucune note", async () => {
    mockGet.mockResolvedValue({ seenUpTo: null, items: [] });
    renderWithProviders(<ReleaseNotesPage />);

    expect(await screen.findByText(/aucune nouveauté/i)).toBeInTheDocument();
  });
});
