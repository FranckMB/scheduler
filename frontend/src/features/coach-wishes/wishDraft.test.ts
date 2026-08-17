import { beforeEach, describe, expect, it } from "vitest";

import { clearDraft, loadDraft, saveDraft } from "./wishDraft";

/**
 * P5-15 — la clé du brouillon porte le nom du produit. La renommer sans repli aurait fait
 * disparaître la saisie d'un coach en cours au moment du déploiement : on ÉCRIT la nouvelle clé,
 * on LIT encore l'ancienne, et `clearDraft` purge les deux (sinon un brouillon périmé ressurgit
 * après un envoi réussi — le pire des deux mondes).
 */
describe("wishDraft — migration de clé", () => {
  const token = "tok-42";
  const sections = new Map([["s1", { slotsWanted: 2, days: new Set([1, 3]), comment: "ok" }]]);

  beforeEach(() => sessionStorage.clear());

  it("écrit sous la nouvelle clé", () => {
    saveDraft(token, sections, 1);

    expect(sessionStorage.getItem(`amateo:wish-draft:${token}`)).not.toBeNull();
    expect(sessionStorage.getItem(`clubscheduler:wish-draft:${token}`)).toBeNull();
  });

  it("relit un brouillon écrit sous l'ANCIENNE clé", () => {
    sessionStorage.setItem(
      `clubscheduler:wish-draft:${token}`,
      JSON.stringify({ sections: { s1: { slotsWanted: 3, days: [2], comment: "hérité" } }, stepIndex: 2 }),
    );

    const draft = loadDraft(token);

    expect(draft?.stepIndex).toBe(2);
    expect(draft?.sections.get("s1")?.comment).toBe("hérité");
  });

  it("purge les deux clés au succès de l'envoi", () => {
    saveDraft(token, sections, 0);
    sessionStorage.setItem(`clubscheduler:wish-draft:${token}`, "{}");

    clearDraft(token);

    expect(sessionStorage.getItem(`amateo:wish-draft:${token}`)).toBeNull();
    expect(sessionStorage.getItem(`clubscheduler:wish-draft:${token}`)).toBeNull();
  });
});
