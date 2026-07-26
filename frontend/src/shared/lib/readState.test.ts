import { describe, expect, it } from "vitest";

import { readFailed, readLoading, readState } from "./readState";

/**
 * Le contrat qui a coûté deux rounds de revue : ce qui compte est d'AVOIR une
 * donnée, pas ce que disent les drapeaux transitoires de react-query.
 */
describe("readState", () => {
  it("une donnée en cache l'emporte sur un refetch d'arrière-plan raté", () => {
    // Le piège : `isError` est vrai alors que la vue a tout ce qu'il lui faut.
    // Le traiter comme fatal détruisait un écran fonctionnel, ou bloquait la
    // génération sur un incident réseau passager.
    const q = { data: [{ id: "s1" }], isError: true };

    expect(readState(q)).toBe("ready");
    expect(readFailed(q)).toBe(false);
    expect(readLoading(q)).toBe(false);
  });

  it("échec SANS rien en cache : le seul cas qui doit céder la place à une erreur", () => {
    expect(readState({ data: undefined, isError: true })).toBe("failed");
    expect(readFailed({ data: undefined, isError: true })).toBe(true);
  });

  it("premier chargement : jamais « vide », donc jamais un vide crédible", () => {
    // `data ?? []` ici affichait « aucun créneau » / « aucun réglage » et poussait
    // le gestionnaire à re-saisir (doublons) ou à valider une période crue vide.
    expect(readState({ data: undefined, isError: false })).toBe("loading");
    expect(readLoading({ data: undefined, isError: false })).toBe(true);
  });

  it("une liste VIDE est une donnée : « il n'y a rien » n'est pas « je ne sais pas »", () => {
    expect(readState({ data: [], isError: false })).toBe("ready");
    expect(readLoading({ data: [], isError: false })).toBe(false);
  });

  it("`null` est une RÉPONSE (« il n'y a rien »), pas un chargement", () => {
    // Convention react-query : seul `undefined` veut dire « pas de donnée ».
    // Classer `null` en loading ferait tourner un spinner éternel sur une
    // réponse arrivée (ex. le plan d'une période jamais adaptée).
    expect(readState({ data: null, isError: false })).toBe("ready");
    expect(readState({ data: null, isError: true })).toBe("ready");
  });
});
