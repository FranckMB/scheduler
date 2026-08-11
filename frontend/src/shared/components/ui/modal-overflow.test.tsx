import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import { ConfirmDialog } from "./confirm-dialog";
import { Modal } from "./modal";

/**
 * NR — une modale trop haute doit RESTER ATTEIGNABLE (retour fondateur 2026-08-11).
 *
 * Le panneau des deux composants n'avait ni hauteur bornée ni zone défilante. Dans un
 * conteneur `items-center`, un contenu plus haut que l'écran débordait donc **en haut ET en
 * bas**, hors viewport, sans rien à faire défiler : le seul recours était de dézoomer le
 * navigateur. Constaté sur la modale d'actions superadmin, sur PC. Manquement WCAG 1.4.10
 * (reflow) : un contenu hors viewport qu'on ne peut pas atteindre n'existe pas.
 *
 * ⚠ **Pourquoi un test sur des CLASSES, ce qu'on évite d'habitude.** jsdom n'a **aucun moteur
 * de mise en page** — `offsetHeight`, `scrollHeight` et `getBoundingClientRect` y valent 0.
 * Mesurer le vrai débordement est donc impossible ici (même limite qu'A11Y-06 pour le
 * contraste). Le choix est entre garder le contrat de mise en page par ses classes, ou ne
 * rien garder du tout — et « rien » est ce qui a laissé passer le défaut. On épingle donc les
 * trois classes qui PORTENT le comportement, en nommant le rôle de chacune.
 *
 * ⚑ Ces trois-là ne sont pas décoratives et ne se remplacent pas l'une l'autre :
 *  - la borne de hauteur empêche le panneau de sortir de l'écran ;
 *  - `overflow-y-auto` donne le moyen d'atteindre ce qui dépasse ;
 *  - `min-h-0` est ce qui rend le défilement POSSIBLE — un enfant flex refuse de rétrécir
 *    sous la taille de son contenu sans lui, et la zone « défilante » ne défilerait jamais.
 *    C'est le piège classique de flexbox, invisible à la relecture.
 */

/** Le panneau `role="dialog"` — les deux composants le portent au même endroit. */
function panel(): HTMLElement {
  return screen.getByRole("dialog");
}

/** La zone défilante : le seul descendant direct qui porte `overflow-y-auto`. */
function scroller(root: HTMLElement): HTMLElement | null {
  return root.querySelector(":scope > .overflow-y-auto");
}

describe("les modales bornent leur hauteur et laissent défiler leur contenu", () => {
  it("Modal : panneau borné, contenu défilant, en-tête figé", () => {
    render(
      <Modal label="Test" title="Titre" onClose={vi.fn()}>
        <p>Contenu très long</p>
      </Modal>,
    );

    const dialog = panel();
    expect(dialog.className).toMatch(/max-h-\[calc\(100dvh-2rem\)\]/);
    // `dvh` et non `vh` : sur mobile, `vh` ignore la barre d'adresse et reproduit le débordement.
    expect(dialog.className).not.toMatch(/max-h-\[calc\(100vh/);
    expect(dialog.className).toMatch(/\bflex\b/);
    expect(dialog.className).toMatch(/\bflex-col\b/);

    const zone = scroller(dialog);
    expect(zone, "le contenu doit vivre dans une zone défilante, sinon la borne de hauteur le COUPE au lieu de le rendre atteignable").not.toBeNull();
    expect(zone?.className).toMatch(/\bmin-h-0\b/);
    expect(zone?.textContent).toContain("Contenu très long");

    // L'en-tête ne défile pas : le titre et la croix de fermeture restent joignables.
    const header = dialog.querySelector(":scope > .shrink-0");
    expect(header).not.toBeNull();
    expect(header?.textContent).toContain("Titre");
  });

  it("ConfirmDialog : panneau borné, description défilante, BOUTONS toujours visibles", () => {
    render(
      <ConfirmDialog
        open
        title="Confirmer ?"
        description={<p>Une description très longue</p>}
        onCancel={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );

    const dialog = panel();
    expect(dialog.className).toMatch(/max-h-\[calc\(100dvh-2rem\)\]/);
    expect(dialog.className).toMatch(/\bflex-col\b/);

    const zone = scroller(dialog);
    expect(zone).not.toBeNull();
    expect(zone?.className).toMatch(/\bmin-h-0\b/);
    expect(zone?.textContent).toContain("Une description très longue");

    // ⚠ LE point propre à cette modale : les actions vivent HORS de la zone défilante.
    // Une confirmation dont on ne voit plus « Confirmer » est pire qu'une modale trop longue.
    const confirm = screen.getByRole("button", { name: /confirmer/i });
    expect(zone?.contains(confirm), "le bouton de confirmation ne doit JAMAIS pouvoir défiler hors de vue").toBe(false);
  });
});
