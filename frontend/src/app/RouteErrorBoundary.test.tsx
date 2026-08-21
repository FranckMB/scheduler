import { onlineManager } from "@tanstack/react-query";
import { render, screen, waitFor } from "@testing-library/react";
import { createMemoryRouter, RouterProvider } from "react-router";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

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
    onlineManager.setOnline(true); // état réseau = source de vérité unique (useOnline), on mute la PROD
  });
  afterEach(() => {
    onlineManager.setOnline(true); // singleton global : ne pas laisser un test hors ligne polluer un autre
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

  it("hors ligne, le MÊME message navigateur ne doit pas être pris pour une mise à jour", async () => {
    // Chrome/Safari rendent exactement la même erreur qu'un chunk 404 après
    // déploiement — le navigateur ne distingue pas les deux causes. Dire
    // « rechargez » à quelqu'un hors ligne lui fait perdre le shell encore
    // utilisable pour tomber sur la page d'erreur du navigateur.
    // On mute la PROD (le vrai onlineManager), pas `navigator.onLine` : depuis la convergence P5-14,
    // c'est `useOnline()` (→ onlineManager) qui tranche, pas une lecture directe de `navigator`.
    onlineManager.setOnline(false);

    renderWithFailingLazy(new TypeError("Failed to fetch dynamically imported module: /assets/PlanningPage-abc.js"));

    expect(await screen.findByText(/pas de réseau/i)).toBeInTheDocument();
    expect(screen.queryByText(/nouvelle version est disponible/i)).toBeNull();
    // Et une issue qui ne dépend pas du réseau du document.
    expect(screen.getByRole("button", { name: /Réessayer/i })).toBeInTheDocument();
  });

  it("toute autre erreur de route reste actionnable (écran 500, pas confondu avec une MAJ)", async () => {
    renderWithFailingLazy(new Error("boom"));

    expect(await screen.findByText(/arrêt de jeu imprévu/i)).toBeInTheDocument();
    expect(screen.queryByText(/nouvelle version est disponible/i)).toBeNull();
  });

  // P5-14 — la porte 403 : un consommateur `throw`e une Response 403 (idiome
  // react-router), la 403 rend l'écran « Vestiaire réservé aux licenciés » et son
  // geste « Demander l'accès ». Aucun chemin UI ne la produit AUJOURD'HUI (le front
  // masque les gestes de gestion en amont) : la porte existe pour le jour où un
  // Membre atteint une route de gestion.
  it("une Response 403 rend l'écran 403 (et pas un chunk manquant)", async () => {
    const router = createMemoryRouter(
      [
        {
          errorElement: <RouteErrorBoundary />,
          children: [
            {
              path: "/",
              loader: () => {
                throw new Response(null, { status: 403 });
              },
              element: <div>jamais rendu</div>,
            },
          ],
        },
      ],
      { initialEntries: ["/"] },
    );
    render(<RouterProvider router={router} />);

    expect(await screen.findByText(/vestiaire réservé aux licenciés/i)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /demander l'accès/i })).toBeInTheDocument();
  });

  it("l'incident part à Sentry — sinon la panne serait invisible du monitoring", async () => {
    renderWithFailingLazy(new TypeError("Failed to fetch dynamically imported module: /assets/x.js"));

    await screen.findByRole("button", { name: /Recharger la page/i });

    // `waitFor` et non une assertion sèche : l'envoi à Sentry se fait dans un
    // `useEffect`, or trouver le bouton ne prouve QUE le rendu — pas que les
    // effets ont été purgés. La version sèche passait en local et sur une CI peu
    // chargée, puis a rougi une fois sur un run à 97 fichiers en parallèle (PR
    // #316), pour repasser au re-run : le flake exact que cette forme produit.
    await waitFor(() => expect(captureException).toHaveBeenCalled());
    expect(captureException.mock.calls[0][1]).toMatchObject({ tags: { chunkFailed: "true" } });
  });
});
