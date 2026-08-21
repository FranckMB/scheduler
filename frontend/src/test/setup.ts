import "@testing-library/jest-dom/vitest";

import { cleanup, configure } from "@testing-library/react";
import { afterEach, expect } from "vitest";
import * as axeMatchers from "vitest-axe/matchers";

// WCAG guardrail: register the `toHaveNoViolations` matcher (runtime). The type
// augmentation lives in vitest-axe.d.ts (vitest v3 types matchers via the
// `vitest` module, not vitest-axe's stale global `Vi.Assertion`).
expect.extend(axeMatchers);

// P4-116 (AUD-FRT-25) — **le plafond des attentes asynchrones, qui n'est PAS celui de Vitest.**
//
// `findBy*` et `waitFor` n'obéissent pas à `testTimeout` : ils ont leur propre budget,
// `asyncUtilTimeout`, dont le défaut est **1000 ms**. Il n'était pas configuré ici.
//
// ⚑ C'est ce qui a rendu le diagnostic de la ligne d'audit incomplet. Elle nommait le plafond
// Vitest de 5 s — vrai pour le cas le plus lourd (`PeriodStructure` › « déplacer un créneau
// réservé », 5,2 s de travail réel même sans charge). Mais les autres échecs mesurés sous
// contention tombaient à **1,3 s**, très loin de 5 s : ce n'étaient pas des timeouts de test,
// c'étaient des `findByRole` qui abandonnaient au bout d'une seconde et rapportaient « Unable
// to find an element » — un échec qui RESSEMBLE à une assertion fausse, et qu'on ne relie pas
// spontanément à la charge machine.
//
// 5 s laisse 4× la marge observée tout en restant bien SOUS le plafond de test (15 s) : un
// élément qui n'apparaît jamais échoue donc toujours ici, avec le message utile de
// testing-library (l'arbre DOM rendu), et non par un timeout de test qui ne dit rien.
configure({ asyncUtilTimeout: 5_000 });

// jsdom ships no ResizeObserver (used by the wizard's ScrollJumpButtons).
class ResizeObserverStub {
  observe() {}
  unobserve() {}
  disconnect() {}
}
globalThis.ResizeObserver ??= ResizeObserverStub as unknown as typeof ResizeObserver;

afterEach(() => {
  cleanup();
});
