import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { act, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ActionVeil } from "@/app/ActionVeil";
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

  // Bug e2e récurrent (#684/#687/#689/#694, diagnostiqué au trace le 2026-08-22) : le POST
  // silencieux est une MUTATION — sans exemption `meta.veil`, l'ActionVeil rendait l'app `inert`
  // à 0 ms (voile invisible sous 250 ms). Or ce POST part ~1,5 s après l'arrivée d'un nouvel
  // inscrit sur le wizard : exactement quand il tape le nom de sa première équipe. Ses frappes
  // étaient avalées SANS retour visuel — le mode d'échec que le fondateur a nommé « pire que pas
  // de voile » (en-tête d'ActionVeil.tsx). Un POST d'entretien ne protège aucun geste parti.
  it("le filigrane silencieux ne gèle JAMAIS l'écran — l'utilisateur est peut-être en train de taper", async () => {
    mockGet.mockResolvedValue(response({ seenUpTo: null }));
    // Le POST reste EN VOL pour toute la durée du test : c'est pendant ce vol que le voile mangeait.
    mockSeen.mockReturnValue(new Promise(() => {}));

    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={qc}>
        <ActionVeil>
          <WhatsNewModal />
        </ActionVeil>
      </QueryClientProvider>,
    );

    await waitFor(() => expect(mockSeen).toHaveBeenCalledTimes(1));
    // Laisser la propagation react-query (mutation pending → useIsMutating) se régler : sans ce
    // battement, la version BOGUÉE passerait aussi — l'inert n'était pas encore posé.
    await act(async () => new Promise((resolve) => setTimeout(resolve, 20)));

    expect(screen.getByTestId("veil-content")).not.toHaveAttribute("inert");
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
