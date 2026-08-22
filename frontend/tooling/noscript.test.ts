import { readFileSync } from "node:fs";
import { resolve } from "node:path";

import { describe, expect, it } from "vitest";

/**
 * P5-14 (vague 3) — garde statique : `index.html` porte un bloc `<noscript>`.
 *
 * JavaScript coupé (stratégie d'entreprise, extension, navigateur durci), la SPA
 * rend une page BLANCHE : `<div id="root">` reste vide et rien ne le dira jamais
 * — le cas de panne le plus silencieux de tous. Le bloc `<noscript>` est le seul
 * canal qui s'affiche précisément dans cet état.
 *
 * Contraintes gardées ici, et pourquoi :
 *  - copie FRANÇAISE avec cause + geste (« JavaScript est désactivé » + comment
 *    le réactiver) — la doctrine des écrans système (frontend-spec §6.8) ;
 *  - AUCUNE marque hors `<title>` : `product.ts:8-11` documente le titre comme
 *    l'unique littéral toléré de `index.html` — un « Amateo » dans le noscript
 *    étendrait l'exception en silence et repiégerait le prochain renommage ;
 *  - aucun lien externe : la page doit être autonome, même doctrine que les
 *    pages système (`.claude/rules/system-pages.md`).
 *
 * Lit le VRAI `frontend/index.html` (celui que Vite cuit dans le dist) : retirer
 * le bloc ou y glisser la marque doit rougir ici.
 */
const html = readFileSync(resolve(process.cwd(), "index.html"), "utf8");

describe("index.html — le bloc <noscript> (P5-14)", () => {
  const block = /<noscript>([\s\S]*?)<\/noscript>/.exec(html)?.[1] ?? "";

  it("existe — JS coupé ne peut pas rendre une page blanche muette", () => {
    expect(block).not.toBe("");
  });

  it("nomme la cause et le geste, en français", () => {
    expect(block).toContain("JavaScript");
    // Le geste : réactiver, pas « contactez le support » (l'utilisateur peut agir seul).
    expect(block).toMatch(/activez|réactivez/i);
  });

  it("ne porte aucune marque — le <title> reste l'unique exception documentée", () => {
    expect(block).not.toMatch(/amateo|maratech/i);
  });

  it("ne charge rien : aucun lien externe, aucune ressource", () => {
    expect(block).not.toMatch(/https?:\/\//);
    expect(block).not.toMatch(/<img|<link|<script/);
  });
});
