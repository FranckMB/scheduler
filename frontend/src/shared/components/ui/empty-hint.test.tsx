import { render, screen } from "@testing-library/react";
import { Landmark } from "lucide-react";
import { describe, expect, it } from "vitest";

import { EmptyBlock, EmptyHint, EmptyState } from "./empty-hint";

/**
 * UXC-17 (P4-117) — les TROIS étages du vide vivent dans UNE maison. `EmptyState`
 * (la Card « vue entière vide ») vivait en local dans `PlanningPage` avec ses deux
 * petits frères déjà partagés : un écran qui naissait vide re-inventait la Card au
 * lieu de la consommer. La promotion est mécanique — le rendu de PlanningPage ne
 * change pas d'un pixel (icône par défaut incluse).
 */
describe("EmptyState — la Card « vue entière vide », promue en primitive", () => {
  it("rend le titre, la description et une icône par défaut", () => {
    render(<EmptyState title="Aucun planning" description="Passez par l'assistant." />);

    expect(screen.getByText("Aucun planning")).toBeInTheDocument();
    expect(screen.getByText("Passez par l'assistant.")).toBeInTheDocument();
    // L'icône par défaut (CalendarX2) est décorative : présente, hors arbre accessible.
    expect(document.querySelector("svg")).not.toBeNull();
  });

  it("accepte une icône propre à l'écran — le défaut calendrier ne s'impose pas partout", () => {
    const { container } = render(<EmptyState icon={Landmark} title="Aucun gymnase" description="Ajoutez un gymnase." />);

    expect(container.querySelector("svg.lucide-landmark")).not.toBeNull();
  });
});

describe("EmptyHint / EmptyBlock — les deux étages existants restent intacts", () => {
  it("EmptyHint reste le paragraphe discret en ligne", () => {
    render(<EmptyHint>Aucune équipe.</EmptyHint>);
    expect(screen.getByText("Aucune équipe.")).toBeInTheDocument();
  });

  it("EmptyBlock reste le bloc pointillé de grille", () => {
    render(<EmptyBlock>Rien à afficher.</EmptyBlock>);
    expect(screen.getByText("Rien à afficher.")).toBeInTheDocument();
  });
});
