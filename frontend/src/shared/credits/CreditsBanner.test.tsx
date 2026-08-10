import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import type { CreditsView } from "./useCredits";

const credits = vi.hoisted(() => ({ current: null as CreditsView | null }));
vi.mock("./useCredits", () => ({ useCredits: () => credits.current }));

import { CreditsBanner } from "./CreditsBanner";

const view = (remaining: number): CreditsView => ({ max: 10, used: 10 - remaining, remaining, canGenerate: remaining > 0, canPlaceMatches: remaining > 0, canExportPdf: remaining > 0 });

function renderBanner() {
  return render(
    <MemoryRouter>
      <CreditsBanner />
    </MemoryRouter>,
  );
}

beforeEach(() => {
  sessionStorage.clear();
});
afterEach(() => {
  credits.current = null;
});

describe("CreditsBanner", () => {
  it("n'affiche RIEN hors Découverte bridée, ni au-dessus de 3 crédits", () => {
    credits.current = null;
    const { container, rerender } = renderBanner();
    expect(container).toBeEmptyDOMElement();
    credits.current = view(4);
    rerender(
      <MemoryRouter>
        <CreditsBanner />
      </MemoryRouter>,
    );
    expect(screen.queryByRole("alert")).toBeNull();
  });

  it("à ≤ 3 : bandeau d'urgence rouge fermable, CTA « Voir les offres » vers /club", () => {
    credits.current = view(3);
    renderBanner();
    expect(screen.getByRole("alert")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Voir les offres" })).toHaveAttribute("href", "/club");
    expect(screen.getByRole("button", { name: "Masquer l'alerte crédits" })).toBeInTheDocument();
  });

  it("une fois fermé, ne se ré-affiche pas au même solde mais REVIENT quand il baisse", async () => {
    const user = userEvent.setup();
    credits.current = view(3);
    const { rerender } = renderBanner();
    await user.click(screen.getByRole("button", { name: "Masquer l'alerte crédits" }));
    expect(screen.queryByRole("alert")).toBeNull();

    // Ré-affichage au MÊME solde (navigation) → toujours masqué.
    rerender(
      <MemoryRouter>
        <CreditsBanner />
      </MemoryRouter>,
    );
    expect(screen.queryByRole("alert")).toBeNull();

    // Le solde BAISSE (2 < 3) → il revient.
    credits.current = view(2);
    rerender(
      <MemoryRouter>
        <CreditsBanner />
      </MemoryRouter>,
    );
    expect(screen.getByRole("alert")).toBeInTheDocument();
  });

  it("à 0 : bandeau PERMANENT non fermable au ton juste", () => {
    credits.current = view(0);
    renderBanner();
    expect(screen.getByRole("alert")).toHaveTextContent(/crédits gratuits sont épuisés/i);
    expect(screen.getByRole("link", { name: "Voir les offres" })).toBeInTheDocument();
    // Non fermable : pas de bouton de masquage.
    expect(screen.queryByRole("button", { name: "Masquer l'alerte crédits" })).toBeNull();
  });
});
