import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { axe } from "vitest-axe";

import type { GridResourceGroup } from "./lib/grid";
import { ResourceFilter } from "./ResourceFilter";

const groups: GridResourceGroup[] = [
  { label: null, resources: [{ id: "v1", label: "Gymnase Alpha" }, { id: "v2", label: "Gymnase Beta" }] },
];

const noop = () => {};

/**
 * P4-43. Ce composant n'avait AUCUN test : on pouvait en retirer l'état actif, l'attribut
 * `aria-expanded` ou la sortie du voile de l'ordre de tabulation sans qu'une seule
 * assertion rougisse (CLAUDE.md §7.2 pt 5).
 */
describe("ResourceFilter — un filtre posé doit se voir", () => {
  it("porte l'accent quand une sélection est active, et pas quand elle est vide", () => {
    // LA règle. Sans état visuel distinct, « Gymnases : 3 sélectionnés » et
    // « Gymnases : tous » portaient exactement le même habillage : le gestionnaire lisait
    // une grille filtrée comme une grille complète et concluait sur ce qu'il ne voyait pas.
    // C'est ce silence — pas la taille du bouton — qui rendait le contrôle invisible.
    const { rerender } = render(<ResourceFilter viewMode="gymnase" groups={groups} selected={[]} onToggle={noop} onClear={noop} />);
    expect(screen.getByRole("button", { expanded: false })).not.toHaveClass("text-accent");

    rerender(<ResourceFilter viewMode="gymnase" groups={groups} selected={["v1"]} onToggle={noop} onClear={noop} />);
    expect(screen.getByRole("button", { expanded: false })).toHaveClass("text-accent");
  });

  it("annonce le nombre sélectionné plutôt que « tous »", () => {
    render(<ResourceFilter viewMode="gymnase" groups={groups} selected={["v1", "v2"]} onToggle={noop} onClear={noop} />);
    expect(screen.getByRole("button", { expanded: false })).toHaveTextContent("2 sélectionnés");
  });

  it("`aria-expanded` suit l'ouverture du panneau", async () => {
    const user = userEvent.setup();
    render(<ResourceFilter viewMode="coach" groups={groups} selected={[]} onToggle={noop} onClear={noop} />);

    const trigger = screen.getByRole("button", { expanded: false });
    await user.click(trigger);
    expect(trigger).toHaveAttribute("aria-expanded", "true");
  });

  it("ouvert, ne laisse AUCUNE violation a11y — le voile de fermeture inclus", async () => {
    // Le voile portait `aria-hidden` tout en restant focusable (`aria-hidden-focus`) : le
    // clavier atterrissait sur un bouton que rien n'annonce.
    const user = userEvent.setup();
    const { container } = render(<ResourceFilter viewMode="equipe" groups={groups} selected={[]} onToggle={noop} onClear={noop} />);
    await user.click(screen.getByRole("button", { expanded: false }));

    expect(await axe(container)).toHaveNoViolations();
  });

  it("le champ de recherche porte un NOM ACCESSIBLE qui nomme son périmètre (A11Y-10)", async () => {
    // Un `placeholder` n'est pas un nom : il disparaît à la première frappe, et le
    // lecteur d'écran annonce alors « zone de texte », rien d'autre. Le champ reçoit
    // le focus à l'ouverture — c'est la PREMIÈRE chose entendue. Et comme deux filtres
    // coexistent dans la même page (modale doléances : coachs ET équipes), le nom doit
    // dire lequel des deux parle.
    const user = userEvent.setup();
    render(<ResourceFilter viewMode="gymnase" groups={groups} selected={[]} onToggle={noop} onClear={noop} />);
    await user.click(screen.getByRole("button", { expanded: false }));

    expect(screen.getByRole("textbox", { name: "Rechercher parmi les gymnases" })).toBeInTheDocument();
  });

  it("bascule la ressource cliquée", async () => {
    const onToggle = vi.fn();
    const user = userEvent.setup();
    render(<ResourceFilter viewMode="gymnase" groups={groups} selected={[]} onToggle={onToggle} onClear={noop} />);

    await user.click(screen.getByRole("button", { expanded: false }));
    await user.click(screen.getByRole("button", { name: "Gymnase Beta" }));

    expect(onToggle).toHaveBeenCalledWith("v2");
  });
});
