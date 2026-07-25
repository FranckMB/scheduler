import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter, Route, Routes } from "react-router";
import { HTTPError } from "ky";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { PublicWishContext } from "./publicApi";

const h = { getContext: vi.fn(), submit: vi.fn() };
vi.mock("./publicApi", async (importOriginal) => ({
  ...(await importOriginal<typeof import("./publicApi")>()),
  getPublicWishContext: (token: string) => h.getContext(token),
  submitPublicWishes: (token: string, submissions: unknown) => h.submit(token, submissions),
}));

import { PublicWishPage } from "./PublicWishPage";

const context = (over: Partial<PublicWishContext> = {}): PublicWishContext => ({
  coachFirstName: "Maxime",
  periodTitle: "Vacances de février",
  deadline: "2027-06-30",
  weeks: ["2026-02-16"],
  teams: [{ id: "t1", name: "SM1" }],
  wishes: [{ teamId: "t1", weekStart: "2026-02-16", slotsWanted: 2, unavailableDays: [3], comment: "note manager" }],
  respondedAt: null,
  ...over,
});

const httpError = (status: number): HTTPError => new HTTPError(new Response(null, { status }), new Request("http://x/api/coach-wishes/public/tok"), {} as never);

function renderAt() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={["/doleances/abc"]}>
        <Routes>
          <Route path="/doleances/:token" element={<PublicWishPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe("PublicWishPage", () => {
  beforeEach(() => {
    h.getContext.mockReset();
    h.submit.mockReset();
  });

  it("pré-remplit le formulaire avec l'état courant (saisie « au nom de »)", async () => {
    h.getContext.mockResolvedValue(context());
    renderAt();

    expect(await screen.findByText(/Bonjour Maxime/)).toBeInTheDocument();
    const slots = screen.getByLabelText(/Séances souhaitées — SM1/);
    expect(slots).toHaveValue(2);
    expect(screen.getByLabelText(/Mer indisponible — SM1/)).toBeChecked();
  });

  it("n'envoie QUE les sections modifiées (dirty-tracking)", async () => {
    h.getContext.mockResolvedValue(context());
    h.submit.mockResolvedValue({ deadline: "2027-06-30" });
    renderAt();
    await screen.findByText(/Bonjour Maxime/);

    // Sans modification, le bouton est inerte.
    expect(screen.getByRole("button", { name: /Aucune modification/ })).toBeDisabled();

    // On modifie les séances → une seule section part.
    const slots = screen.getByLabelText(/Séances souhaitées — SM1/);
    await userEvent.clear(slots);
    await userEvent.type(slots, "4");
    await userEvent.click(screen.getByRole("button", { name: /Envoyer/ }));

    await waitFor(() => expect(h.submit).toHaveBeenCalledTimes(1));
    expect(h.submit).toHaveBeenCalledWith("abc", [{ teamId: "t1", weekStart: "2026-02-16", slotsWanted: 4, unavailableDays: [3], comment: "note manager" }]);
  });

  it("affiche « lien invalide » sur 404", async () => {
    h.getContext.mockRejectedValue(httpError(404));
    renderAt();
    expect(await screen.findByText(/Lien invalide/)).toBeInTheDocument();
  });

  it("affiche « lien expiré » sur 410", async () => {
    h.getContext.mockRejectedValue(httpError(410));
    renderAt();
    expect(await screen.findByText(/Lien expiré/)).toBeInTheDocument();
  });
});
