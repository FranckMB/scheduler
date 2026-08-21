import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import { ConfirmDialog } from "./confirm-dialog";
import { Modal, type ModalSize } from "./modal";

/**
 * NR — P4-107 (3ᵉ tranche) : **une modale a une taille NOMMÉE, et cette taille cesse de
 * grandir.**
 *
 * Avant ce lot, `modal.tsx` imposait `max-w-md` (448 px) à TOUTES les modales : sur l'écran
 * de référence du fondateur (1920×1080) un formulaire occupait 23 % de la largeur et le
 * reste était de la marge — « si la marge est plus grande que l'utile, à quoi bon ». Six
 * appelants s'étaient bricolé leur propre `className="max-w-…"` (quatre valeurs différentes,
 * dont une qui redisait le défaut) : quand chaque appelant rafistole dans son coin, c'est que
 * le comportement manque en amont — exactement ce que le docblock de `modal.tsx` disait déjà
 * de la HAUTEUR, sans que la largeur en tire la leçon.
 *
 * ⚠ **Deux sens à falsifier, et le second est celui qu'on oublie.**
 *  1. Une classe qui MANQUE (la taille ne monte pas) → rouge.
 *  2. Une classe EN TROP → rouge aussi. C'est la vraie garde : elle attrape le résidu
 *     `max-w-md` de l'ancien code laissé dans le `cn(...)`, **et** toute reprise de la
 *     croissance au-delà du plafond décidé (un `2xl:max-w-7xl` ajouté « pour remplir »).
 *     D'où l'égalité d'ENSEMBLE ci-dessous, jamais un `toMatch` par classe.
 *
 * ⚑ **Pourquoi les chaînes attendues sont écrites EN DUR ici** plutôt qu'importées de la map
 * du composant : un test qui compare la map à elle-même est vert quoi qu'on y écrive. Les
 * littéraux font de ce fichier la SPÉCIFICATION de l'échelle — la modifier oblige à venir
 * dire ici que c'était voulu.
 *
 * ⚠ **Ce que ce fichier ne peut PAS prouver** : qu'une modale mesure réellement 1152 px.
 * jsdom n'a aucun moteur de mise en page (`getBoundingClientRect` y vaut 0) — la mesure en
 * pixels vit en Playwright (`tests/e2e/modal-reachability.spec.ts`), seul endroit où une
 * largeur existe. Ici on garde le CONTRAT de classes ; là-bas, son effet.
 *
 * Le plafond de chaque palier (le breakpoint après lequel la modale ne grandit plus) est un
 * choix issu de la passe de design `ui-ux-pro-max` : le corpus n'endosse que des échelles qui
 * TERMINENT sur une largeur fixe — `DON'T Full-width text on large screens`. Une modale qui
 * suivrait indéfiniment le viewport rejouerait l'anti-pattern à l'autre bout.
 */

/** Toutes les classes de largeur portées par le panneau, préfixes responsive compris. */
function widthClasses(element: HTMLElement): string[] {
  return element.className
    .split(/\s+/)
    .filter((token) => /(^|:)max-w-/.test(token))
    .sort();
}

function panelOf(size: ModalSize | undefined): HTMLElement {
  render(
    <Modal label="Test" title="Titre" onClose={vi.fn()} {...(size ? { size } : {})}>
      <p>Contenu</p>
    </Modal>,
  );

  return screen.getByRole("dialog");
}

/** L'échelle décidée (P4-107, 3ᵉ tranche) — plafond atteint dès `lg:`, sauf `sm` qui ne bouge jamais. */
const SCALE: Record<ModalSize, string[]> = {
  // Confirmation / geste destructif : une phrase et deux boutons. Élargir n'achète rien.
  sm: ["max-w-md"],
  // Défaut — formulaire de 6 champs au plus. Plafond 576 px : au-delà, une ligne de champs se délie.
  md: ["lg:max-w-xl", "max-w-md", "sm:max-w-lg"],
  // Formulaire long / liste. Plafond 768 px, sous la largeur des pages fiche (832 px).
  lg: ["lg:max-w-3xl", "max-w-lg", "md:max-w-2xl"],
  // Contenu tabulaire / comparaison de plannings. Plafond 1152 px (arbitrage fondateur 2026-08-21).
  xl: ["lg:max-w-5xl", "max-w-2xl", "md:max-w-4xl", "xl:max-w-6xl"],
};

describe("l'échelle de largeurs des modales", () => {
  it.each(Object.keys(SCALE) as ModalSize[])("taille « %s » : exactement les classes de son palier, ni plus ni moins", (size) => {
    expect(widthClasses(panelOf(size))).toEqual([...SCALE[size]].sort());
  });

  it("sans prop, une modale prend la taille md", () => {
    expect(widthClasses(panelOf(undefined))).toEqual([...SCALE.md].sort());
  });

  it("la taille sm ne grandit JAMAIS avec l'écran", () => {
    // Une confirmation destructive qui s'élargit sur grand écran éloigne les deux boutons
    // du regard sans rien ajouter à lire : le corpus de design ne connaît qu'une règle sur
    // les modales, et elle porte sur le geste, pas sur la boîte.
    expect(widthClasses(panelOf("sm")).filter((token) => token.includes(":"))).toEqual([]);
  });

  it("ConfirmDialog partage le palier sm — le dialogue qui ne passe pas par Modal ne dérive pas", () => {
    // ⚠ `confirm-dialog.tsx` recopie le markup du panneau (« une vérité, deux endroits »,
    // duplication assumée et commentée dans le fichier). Sans cette assertion, la moitié du
    // parc de modales ignorerait l'échelle — c'est précisément comment la dérive avait
    // commencé sur la largeur.
    render(<ConfirmDialog open title="Confirmer ?" description={<p>Description</p>} onCancel={vi.fn()} onConfirm={vi.fn()} />);

    expect(widthClasses(screen.getByRole("dialog"))).toEqual(SCALE.sm);
  });
});
