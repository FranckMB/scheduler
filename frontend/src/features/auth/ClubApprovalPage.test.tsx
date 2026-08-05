import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { HTTPError } from "ky";
import { MemoryRouter, Route, Routes } from "react-router";
import { describe, expect, it, vi } from "vitest";

const h = { get: vi.fn(), decide: vi.fn() };

// Couche API voisine mockée (le mock ESM n'intercepte pas l'intra-module) : la
// page reste montée sur un vrai QueryClient — le câblage query/mutation est réel.
vi.mock("./api", () => ({
  getClubApproval: (token: string) => h.get(token),
  decideClubApproval: (token: string, decision: string) => h.decide(token, decision),
}));

import { ClubApprovalPage } from "./ClubApprovalPage";

function renderAt(token = "a".repeat(64)) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[`/club-approval/${token}`]}>
        <Routes>
          <Route path="/club-approval/:token" element={<ClubApprovalPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

const info = { clubName: "BC Testville", ara: "ARA0000001", requesterName: "Sonia Saison", expiresAt: "2026-09-12" };

const httpError = (status: number): HTTPError =>
  new HTTPError(new Response("{}", { status }), new Request("http://t/api/club-approvals/x"), {} as never);

describe("ClubApprovalPage (P3-4 PR C — page publique)", () => {
  it("nomme le demandeur et le club, et porte les deux gestes", async () => {
    h.get.mockResolvedValueOnce(info);
    renderAt();

    expect(await screen.findByText("Sonia Saison")).toBeInTheDocument();
    expect(screen.getByText("BC Testville")).toBeInTheDocument();
    expect(screen.getByText(/expire le 2026-09-12/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Approuver" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Refuser" })).toBeInTheDocument();
  });

  it("approuve : POST decision=approve puis écran de confirmation", async () => {
    h.get.mockResolvedValueOnce(info);
    h.decide.mockResolvedValueOnce({ status: "approved" });
    renderAt();

    await userEvent.click(await screen.findByRole("button", { name: "Approuver" }));

    expect(h.decide).toHaveBeenCalledWith("a".repeat(64), "approve");
    expect(await screen.findByText("Espace créé")).toBeInTheDocument();
  });

  it("refuse : POST decision=refuse puis écran de clôture", async () => {
    h.get.mockResolvedValueOnce(info);
    h.decide.mockResolvedValueOnce({ status: "refused" });
    renderAt();

    await userEvent.click(await screen.findByRole("button", { name: "Refuser" }));

    expect(h.decide).toHaveBeenCalledWith("a".repeat(64), "refuse");
    expect(await screen.findByText("Demande refusée")).toBeInTheDocument();
  });

  it("410 → « expirée », 404 → « lien invalide » (les deux états d'erreur se distinguent)", async () => {
    h.get.mockRejectedValueOnce(httpError(410));
    renderAt();
    expect(await screen.findByText(/Cette demande a expiré/)).toBeInTheDocument();

    h.get.mockRejectedValueOnce(httpError(404));
    renderAt("b".repeat(64));
    expect(await screen.findByText(/lien est invalide/)).toBeInTheDocument();
  });
});
