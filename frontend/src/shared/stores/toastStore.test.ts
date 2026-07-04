import { beforeEach, describe, expect, it } from "vitest";

import { toast, useToastStore } from "./toastStore";

describe("toastStore", () => {
  beforeEach(() => {
    useToastStore.setState({ toasts: [] });
  });

  it("pushes a toast with the given variant and a unique id", () => {
    const a = useToastStore.getState().push("Boom", "error");
    const b = useToastStore.getState().push("Ok", "success");

    const { toasts } = useToastStore.getState();
    expect(toasts).toHaveLength(2);
    expect(a).not.toBe(b);
    expect(toasts[0]).toMatchObject({ message: "Boom", variant: "error" });
    expect(toasts[1]).toMatchObject({ message: "Ok", variant: "success" });
  });

  it("defaults to the error variant", () => {
    useToastStore.getState().push("Oops");
    expect(useToastStore.getState().toasts[0].variant).toBe("error");
  });

  it("dismisses a toast by id", () => {
    const id = useToastStore.getState().push("Boom");
    useToastStore.getState().dismiss(id);
    expect(useToastStore.getState().toasts).toHaveLength(0);
  });

  it("exposes an imperative helper", () => {
    toast.success("Saved");
    const { toasts } = useToastStore.getState();
    expect(toasts[0]).toMatchObject({ message: "Saved", variant: "success" });
  });
});
