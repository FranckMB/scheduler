import "@testing-library/jest-dom/vitest";

import { cleanup } from "@testing-library/react";
import { afterEach, expect } from "vitest";
import * as axeMatchers from "vitest-axe/matchers";

// WCAG guardrail: register the `toHaveNoViolations` matcher (runtime). The type
// augmentation lives in vitest-axe.d.ts (vitest v3 types matchers via the
// `vitest` module, not vitest-axe's stale global `Vi.Assertion`).
expect.extend(axeMatchers);

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
