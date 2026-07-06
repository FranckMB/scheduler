import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { renderHook, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { createConstraint } from "@/features/wizard/api";

import * as cockpitApi from "./api";
import { useCreateCutoff, usePublicHolidays } from "./queries";

vi.mock("./api", () => ({
  getCalendarEntries: vi.fn(),
  getCalendarEntry: vi.fn(),
  getSchoolHolidays: vi.fn(),
  getPublicHolidays: vi.fn().mockResolvedValue({ zone: "A", items: [] }),
  getEntryConflicts: vi.fn(),
  createCalendarEntry: vi.fn().mockResolvedValue({ id: "e1" }),
  deleteCalendarEntry: vi.fn(),
}));
vi.mock("@/features/wizard/api", () => ({
  createConstraint: vi.fn(),
}));

function wrapper({ children }: { children: ReactNode }) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;
}

describe("cockpit queries — payload contracts", () => {
  beforeEach(() => {
    vi.mocked(cockpitApi.createCalendarEntry).mockClear();
    vi.mocked(cockpitApi.getPublicHolidays).mockClear();
    vi.mocked(createConstraint).mockClear();
  });

  it("useCreateCutoff posts a bare cutoff period — no dated constraint, ever", async () => {
    const { result } = renderHook(() => useCreateCutoff(), { wrapper });

    result.current.mutate({ title: "Coupure de Noël", startDate: "2026-12-21", endDate: "2026-12-27" });

    await waitFor(() =>
      expect(cockpitApi.createCalendarEntry).toHaveBeenCalledWith({
        kind: "period",
        periodType: "cutoff",
        title: "Coupure de Noël",
        startDate: "2026-12-21",
        endDate: "2026-12-27",
      }),
    );
    // Unlike a venue closure, a cutoff must NOT create a FACILITY constraint.
    expect(createConstraint).not.toHaveBeenCalled();
  });

  it("usePublicHolidays sends the explicit window (no-params call 400s without an active season)", async () => {
    renderHook(() => usePublicHolidays("2026-07-01", "2026-08-09"), { wrapper });

    await waitFor(() => expect(cockpitApi.getPublicHolidays).toHaveBeenCalledWith("2026-07-01", "2026-08-09"));
  });
});
