// vitest v3 types custom matchers via the `vitest` module's Assertion interface.
// vitest-axe only ships a (stale) global `Vi.Assertion` augmentation, so we wire
// its matcher type in ourselves. Runtime registration is in setup.ts.
import type { AxeMatchers } from "vitest-axe/matchers";

declare module "vitest" {
  // eslint-disable-next-line @typescript-eslint/no-explicit-any, @typescript-eslint/no-empty-object-type, @typescript-eslint/no-unused-vars
  interface Assertion<T = any> extends AxeMatchers {}
  // eslint-disable-next-line @typescript-eslint/no-empty-object-type
  interface AsymmetricMatchersContaining extends AxeMatchers {}
}
