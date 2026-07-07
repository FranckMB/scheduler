import { beforeEach, describe, expect, it } from "vitest";

import { useTransitionUiStore } from "./transitionUiStore";

describe("transitionUiStore", () => {
  beforeEach(() => useTransitionUiStore.setState({ confirmOpen: false }));

  it("opens and closes the confirm", () => {
    expect(useTransitionUiStore.getState().confirmOpen).toBe(false);
    useTransitionUiStore.getState().openConfirm();
    expect(useTransitionUiStore.getState().confirmOpen).toBe(true);
    useTransitionUiStore.getState().closeConfirm();
    expect(useTransitionUiStore.getState().confirmOpen).toBe(false);
  });
});
