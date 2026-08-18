import { render, screen, act, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { GenerationWaiting } from "./GenerationWaiting";

describe("GenerationWaiting", () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });
  afterEach(() => {
    vi.runOnlyPendingTimers();
    vi.useRealTimers();
  });

  it("rend la scène de génération (role img + aria-label descriptif)", () => {
    render(<GenerationWaiting />);
    const scene = screen.getByRole("img", { name: /créneaux|planning/i });
    expect(scene.tagName.toLowerCase()).toBe("svg");
  });

  it("rend la mini-grille animée au centre de la scène", () => {
    render(<GenerationWaiting />);
    expect(screen.getByTestId("gen-minigrid")).toBeInTheDocument();
  });

  it("place titre, phrase et note DANS la scène — pas des frères sous le cadre", () => {
    render(<GenerationWaiting />);
    const frame = screen.getByTestId("gen-scene-frame");
    // Décor (role img) ET textes vivent dans le MÊME cadre : le centre est superposé.
    expect(within(frame).getByRole("img", { name: /créneaux|planning/i })).toBeInTheDocument();
    expect(within(frame).getByText(/génération du planning/i)).toBeInTheDocument();
    expect(within(frame).getByText("Placement des équipes prioritaires…")).toBeInTheDocument();
    expect(within(frame).getByText(/1 à 3 min/i)).toBeInTheDocument();
    // Aucun contenu rejeté sous le cadre : le conteneur externe n'a que le cadre pour enfant.
    expect(frame.parentElement?.childElementCount).toBe(1);
  });

  it("fait tourner les phrases toutes les 3 s", () => {
    render(<GenerationWaiting />);
    expect(screen.getByText("Placement des équipes prioritaires…")).toBeInTheDocument();
    act(() => {
      vi.advanceTimersByTime(3000);
    });
    expect(screen.getByText("Respect des disponibilités des gymnases…")).toBeInTheDocument();
    expect(screen.queryByText("Placement des équipes prioritaires…")).not.toBeInTheDocument();
  });

  // Retour fondateur 2026-08-18 : le cadre était borné à 640 px alors que le shell est
  // pleine largeur — de l'espace perdu à gauche ET à droite pendant toute la génération.
  it("occupe toute la largeur disponible — le cadre ne porte AUCUNE largeur maximale", () => {
    render(<GenerationWaiting />);
    const frame = screen.getByTestId("gen-scene-frame");
    expect(frame.className).toContain("w-full");
    expect(frame.className).not.toMatch(/\bmax-w-/);
  });

  /**
   * Le décor ne vit que dans DEUX bandes latérales (le centre est la zone protégée des
   * textes) : elles sont donc ancrées aux bords et le centre s'étire. Chaque bande garde
   * les coordonnées d'origine du dessin — seule sa viewBox cadre sa moitié.
   */
  it("ancre les deux bandes de décor aux bords, coordonnées d'origine préservées", () => {
    render(<GenerationWaiting />);
    const left = screen.getByTestId("gen-band-left");
    const right = screen.getByTestId("gen-band-right");
    expect(left.getAttribute("viewBox")).toBe("0 0 230 500");
    expect(right.getAttribute("viewBox")).toBe("570 0 230 500");
    expect(left.getAttribute("class")).toContain("left-0");
    expect(right.getAttribute("class")).toContain("right-0");
    // Une seule des deux porte le sens : deux `role="img"` feraient lire la scène deux fois.
    expect(right.getAttribute("aria-hidden")).toBe("true");
  });

  // Décision fondateur : la scène EST le contenu, aucun logo de club ni initiale par-dessus.
  it("ne rend AUCUN logo de club", () => {
    render(<GenerationWaiting />);
    expect(document.querySelector("img")).toBeNull();
    expect(screen.queryByTestId("generation-club-logo")).not.toBeInTheDocument();
  });
});
