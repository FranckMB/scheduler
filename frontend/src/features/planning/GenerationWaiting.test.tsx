import { render, screen, act } from "@testing-library/react";
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

  it("garde le titre, la première phrase et la note de durée", () => {
    render(<GenerationWaiting />);
    expect(screen.getByText(/génération du planning/i)).toBeInTheDocument();
    expect(screen.getByText("Placement des équipes prioritaires…")).toBeInTheDocument();
    expect(screen.getByText(/1 à 3 min/i)).toBeInTheDocument();
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

  // Décision fondateur (2026-08-17) : la scène EST le contenu, aucun logo de club
  // ni initiale de repli ne se superpose. Un <img> réintroduit signalerait la régression.
  it("ne rend AUCUN logo de club par-dessus la scène", () => {
    render(<GenerationWaiting />);
    expect(screen.queryByRole("img", { name: "" })).not.toBeInTheDocument();
    expect(document.querySelector("img")).toBeNull();
    expect(screen.queryByTestId("generation-club-logo")).not.toBeInTheDocument();
  });
});
