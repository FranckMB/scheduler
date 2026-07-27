import { render, screen } from "@testing-library/react";
import { createMemoryRouter, RouterProvider } from "react-router";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { RouteErrorBoundary } from "./RouteErrorBoundary";

const captureException = vi.fn();
vi.mock("@sentry/react", () => ({ captureException: (...a: unknown[]) => captureException(...a) }));

/**
 * NR du découpage en chunks (P4-6).
 *
 * Sans `errorElement`, le data router attrape lui-même la rejection d'un `lazy`
 * et rend SON écran par défaut — anglais, non stylé, sans issue — à la place de
 * toute l'application, et l'`ErrorBoundary` React ne le voit jamais (donc Sentry
 * non plus). Le cas réel : un déploiement remplace les `assets/*.js` hachés,
 * l'onglet resté ouvert demande un chunk qui n'existe plus.
 */
describe("RouteErrorBoundary — le filet du découpage", () => {
  beforeEach(() => {
    captureException.mockClear();
    vi.spyOn(console, "error").mockImplementation(() => {});
  });

  function renderWithFailingLazy(error: Error) {
    const router = createMemoryRouter(
      [
        {
          errorElement: <RouteErrorBoundary />,
          children: [
            {
              path: "/",
              lazy: () => Promise.reject(error),
            },
          ],
        },
      ],
      { initialEntries: ["/"] },
    );

    return render(<RouterProvider router={router} />);
  }

  it("un chunk absent après déploiement est nommé pour ce qu'il est, avec sa sortie", async () => {
    // Message réel de Chrome/Safari quand le fichier haché n'existe plus.
    renderWithFailingLazy(new TypeError("Failed to fetch dynamically imported module: /assets/WizardLayout-abc.js"));

    expect(await screen.findByText(/nouvelle version est disponible/i)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Recharger la page/i })).toBeInTheDocument();
    // Pas l'écran anglais du router : c'est précisément ce qu'on remplace.
    expect(screen.queryByText(/Unexpected Application Error/i)).toBeNull();
  });

  it("toute autre erreur de route reste actionnable (et n'est pas confondue avec une MAJ)", async () => {
    renderWithFailingLazy(new Error("boom"));

    expect(await screen.findByText(/n'a pas pu être ouverte/i)).toBeInTheDocument();
    expect(screen.queryByText(/nouvelle version est disponible/i)).toBeNull();
  });

  it("l'incident part à Sentry — sinon la panne serait invisible du monitoring", async () => {
    renderWithFailingLazy(new TypeError("Failed to fetch dynamically imported module: /assets/x.js"));

    await screen.findByRole("button", { name: /Recharger la page/i });
    expect(captureException).toHaveBeenCalled();
    expect(captureException.mock.calls[0][1]).toMatchObject({ tags: { staleChunk: "true" } });
  });
});
