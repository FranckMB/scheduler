import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, describe, expect, it, vi } from "vitest";

import { ErrorBoundary } from "./ErrorBoundary";

function Boom(): never {
  throw new Error("boom");
}

const flag = { throws: true };
function MaybeBoom() {
  if (flag.throws) {
    throw new Error("boom");
  }
  return <p>recovered</p>;
}

describe("ErrorBoundary", () => {
  afterEach(() => vi.restoreAllMocks());

  it("renders children when there is no error", () => {
    render(
      <ErrorBoundary>
        <p>all good</p>
      </ErrorBoundary>,
    );
    expect(screen.getByText("all good")).toBeInTheDocument();
  });

  it("catches a render throw and shows the branded fallback + reload", () => {
    // React logs the caught error; silence it so the test output stays clean.
    vi.spyOn(console, "error").mockImplementation(() => {});
    render(
      <ErrorBoundary>
        <Boom />
      </ErrorBoundary>,
    );
    expect(screen.getByText(/erreur inattendue/i)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /recharger/i })).toBeInTheDocument();
  });

  it("recovers in-app when Réessayer is clicked and the cause is gone (no reload)", async () => {
    vi.spyOn(console, "error").mockImplementation(() => {});
    flag.throws = true;
    render(
      <ErrorBoundary>
        <MaybeBoom />
      </ErrorBoundary>,
    );
    expect(screen.getByText(/erreur inattendue/i)).toBeInTheDocument();

    flag.throws = false; // the transient cause is gone
    await userEvent.click(screen.getByRole("button", { name: /réessayer/i }));
    expect(screen.getByText("recovered")).toBeInTheDocument();
  });
});
