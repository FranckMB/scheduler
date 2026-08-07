import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen } from "@testing-library/react";
import { MemoryRouter } from "react-router";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { MeResponse } from "./api";

const h = { me: vi.fn(), navigate: vi.fn(), logout: vi.fn() };

vi.mock("./api", () => ({ getMe: () => h.me() }));
vi.mock("./queries", () => ({ useLogout: () => h.logout }));
vi.mock("react-router", async (importOriginal) => ({
  ...(await importOriginal<typeof import("react-router")>()),
  useNavigate: () => h.navigate,
}));
vi.mock("@/shared/stores/authStore", () => ({
  useAuthStore: (sel: (s: { isAuthenticated: boolean }) => unknown) => sel({ isAuthenticated: true }),
}));

import { WaitingApprovalPage } from "./WaitingApprovalPage";

function renderPage(me: Partial<MeResponse>) {
  h.me.mockResolvedValue(me);
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <WaitingApprovalPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

const request = (status: string, clubEmailKnown = true) =>
  ({ status, clubName: "BC Testville", ara: "ARA0000001", clubEmailKnown }) as MeResponse["clubRequest"];

/**
 * P3-4 PR C — la salle d'attente dit QUI approuve et l'ISSUE : club-mail FFBB,
 * file support (mail introuvable — le texte « un email est parti » mentirait),
 * refus, expiration. Le cas membership (club existant) garde son texte d'origine.
 */
describe("WaitingApprovalPage", () => {
  beforeEach(() => vi.clearAllMocks());

  it("création : annonce l'email au CLUB quand son adresse FFBB est connue", async () => {
    renderPage({ membershipStatus: "club_pending", clubRequest: request("pending") });
    expect(await screen.findByText(/adresse officielle de votre club/)).toBeInTheDocument();
  });

  it("création sans mail FFBB : annonce la validation par l'équipe — jamais un email fantôme", async () => {
    renderPage({ membershipStatus: "club_pending", clubRequest: request("pending", false) });
    expect(await screen.findByText(/validée par notre équipe/)).toBeInTheDocument();
    expect(screen.queryByText(/Un email d'approbation a été envoyé/)).not.toBeInTheDocument();
  });

  it("refus : l'issue s'affiche, pas un silence éternel", async () => {
    renderPage({ membershipStatus: "club_pending", clubRequest: request("refused") });
    expect(await screen.findByText("Demande refusée")).toBeInTheDocument();
  });

  it("expiration : nomme le délai et la voie de secours (support)", async () => {
    renderPage({ membershipStatus: "club_pending", clubRequest: request("expired") });
    expect(await screen.findByText("Demande expirée")).toBeInTheDocument();
    expect(screen.getByText(/support peut débloquer/)).toBeInTheDocument();
  });

  it("adhésion à un club existant : le texte historique (gestionnaire en place)", async () => {
    renderPage({ membershipStatus: "pending", clubRequest: null, club: { name: "BC Testville" } as MeResponse["club"] });
    expect(await screen.findByText(/Le gestionnaire de BC Testville doit approuver/)).toBeInTheDocument();
  });

  it("actif → entre dans l'app (le poll aboutit)", async () => {
    renderPage({ membershipStatus: "active", clubRequest: null });
    await vi.waitFor(() => expect(h.navigate).toHaveBeenCalledWith("/", { replace: true }));
  });
});
