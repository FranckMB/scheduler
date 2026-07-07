import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { MeResponse, MeSeason } from "@/features/auth/api";
import { useTransitionUiStore } from "@/shared/stores/transitionUiStore";

import { SeasonTransitionBanner, seasonYearOf } from "./SeasonTransitionBanner";

let meData: Partial<MeResponse> | undefined;

vi.mock("@/features/auth/queries", () => ({
  useMe: () => ({ data: meData }),
}));

const season = (overrides: Partial<MeSeason>): MeSeason => ({
  id: "sN",
  name: "2025-2026",
  startDate: "2025-08-01",
  endDate: "2026-07-15",
  isCurrent: true,
  isReadonly: false,
  ...overrides,
});

/** Local-time date (never the Date(iso) UTC parse) — mirrors the component's localIso. */
const day = (iso: string): Date => new Date(`${iso}T12:00:00`);

describe("seasonYearOf", () => {
  it("bins by the July-15 pivot", () => {
    expect(seasonYearOf("2025-08-01")).toBe(2025);
    expect(seasonYearOf("2026-07-14")).toBe(2025);
    expect(seasonYearOf("2026-07-15")).toBe(2026);
  });
});

describe("SeasonTransitionBanner", () => {
  beforeEach(() => {
    meData = undefined;
    useTransitionUiStore.setState({ confirmOpen: false });
  });

  it("shows inside the window when no successor exists", () => {
    meData = { seasons: [season({})] };
    render(<SeasonTransitionBanner today={day("2026-05-20")} />);
    expect(screen.getByRole("status")).toHaveTextContent("préparez la saison suivante");
  });

  it("hides before May 15", () => {
    meData = { seasons: [season({})] };
    render(<SeasonTransitionBanner today={day("2026-05-14")} />);
    expect(screen.queryByRole("status")).not.toBeInTheDocument();
  });

  it("hides on the pivot day (July 15) and after", () => {
    meData = { seasons: [season({})] };
    render(<SeasonTransitionBanner today={day("2026-07-15")} />);
    expect(screen.queryByRole("status")).not.toBeInTheDocument();
  });

  it("hides when the next season is already prepared", () => {
    meData = { seasons: [season({}), season({ id: "sD", name: "2026-2027", startDate: "2026-08-01", isCurrent: false })] };
    render(<SeasonTransitionBanner today={day("2026-06-20")} />);
    expect(screen.queryByRole("status")).not.toBeInTheDocument();
  });

  it("CTA opens the shared transition confirm", async () => {
    meData = { seasons: [season({})] };
    const user = userEvent.setup();
    render(<SeasonTransitionBanner today={day("2026-06-20")} />);

    await user.click(screen.getByRole("button", { name: "Préparer la saison suivante" }));
    expect(useTransitionUiStore.getState().confirmOpen).toBe(true);
  });
});
