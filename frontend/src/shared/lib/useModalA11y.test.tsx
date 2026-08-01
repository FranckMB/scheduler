import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import { Modal } from "@/shared/components/ui/modal";

/**
 * Le piège à focus d'une modale — la garantie que Tab ne s'échappe pas du dialogue.
 *
 * ⚠ Écrit après la revue #346 : le sélecteur CSS des focusables ne connaît pas `hidden`,
 * si bien qu'une modale À ONGLETS — dont le panneau inactif porte ses propres boutons —
 * avait pour « dernier » élément un bouton INATTEIGNABLE. Tab depuis le dernier bouton
 * réellement visible ne bouclait donc plus : le focus sortait de la modale, sans retour au
 * clavier. Le défaut ne vient pas des onglets mais du helper partagé : il se garde ici.
 */
describe("useModalA11y — le piège à focus ignore les sous-arbres cachés", () => {
  const TabbedModal = () => (
    <Modal label="Test" title="Test" onClose={vi.fn()}>
      <button type="button">Premier</button>
      <button type="button">Dernier visible</button>
      <div hidden>
        <button type="button">Caché</button>
      </div>
    </Modal>
  );

  it("boucle du dernier VISIBLE vers le premier, sans passer par le caché", async () => {
    const user = userEvent.setup();
    render(<TabbedModal />);

    screen.getByRole("button", { name: "Dernier visible" }).focus();
    await user.tab();

    // Sans le filtre, `last` était le bouton caché : la comparaison échouait, aucun
    // `preventDefault`, et le focus quittait la modale.
    expect(document.activeElement).not.toBe(document.body);
    expect(screen.getByRole("button", { name: "Caché", hidden: true })).not.toBe(document.activeElement);
  });

  it("boucle en arrière depuis le premier vers le dernier VISIBLE", async () => {
    const user = userEvent.setup();
    render(<TabbedModal />);

    const first = screen.getAllByRole("button")[0];
    first.focus();
    await user.tab({ shift: true });

    expect(document.activeElement).not.toBe(document.body);
    expect(screen.getByRole("button", { name: "Caché", hidden: true })).not.toBe(document.activeElement);
  });
});
