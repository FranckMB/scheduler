import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { MeResponse, MeSeason } from "@/features/auth/api";
import { useSeasonStore } from "@/shared/stores/seasonStore";
import { ReadonlySeasonBanner } from "./ReadonlySeasonBanner";

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

describe("ReadonlySeasonBanner", () => {
  beforeEach(() => {
    meData = undefined;
    useSeasonStore.getState().clear();
  });

  it("renders nothing on the current (writable) season", () => {
    meData = { currentSeasonId: "sN", seasons: [season({})] };
    render(<ReadonlySeasonBanner />);
    expect(screen.queryByRole("status")).not.toBeInTheDocument();
  });

  it("shows the read-only notice when an archived season is selected", () => {
    meData = { currentSeasonId: "sN", seasons: [season({ id: "sP", name: "2024-2025", isCurrent: false, isReadonly: true }), season({})] };
    useSeasonStore.getState().setSelectedSeasonId("sP");
    render(<ReadonlySeasonBanner />);

    expect(screen.getByRole("status")).toHaveTextContent("2024-2025");
    expect(screen.getByRole("status")).toHaveTextContent(/lecture seule/i);
  });
});
