import { QueryClientProvider } from "@tanstack/react-query";
import { renderHook, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { ReactNode } from "react";

import { queryClient } from "@/shared/lib/queryClient";

import * as api from "./api";
import type { SocleDeviation } from "./api";
import { useLockSlot, useMoveSlot, usePlaceSlot, useSocleDeviation } from "./queries";

const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;

const EMPTY: SocleDeviation = { socleScheduleId: "socle", moved: [], unplaced: [] };

afterEach(() => {
  vi.restoreAllMocks();
  queryClient.clear();
});

describe("useSocleDeviation — gating", () => {
  it("scheduleId NUL → la route n'est JAMAIS appelée (vacance, /planning autonome)", async () => {
    const spy = vi.spyOn(api, "getSocleDeviation").mockResolvedValue(EMPTY);
    renderHook(() => useSocleDeviation(null), { wrapper });

    // Laisse react-query s'installer : une query désarmée ne doit rien fetcher.
    await new Promise((r) => setTimeout(r, 10));
    expect(spy).not.toHaveBeenCalled();
  });

  it("scheduleId présent → la route est appelée une fois", async () => {
    const spy = vi.spyOn(api, "getSocleDeviation").mockResolvedValue(EMPTY);
    const { result } = renderHook(() => useSocleDeviation("sc-1"), { wrapper });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(spy).toHaveBeenCalledWith("sc-1");
  });
});

describe("useSocleDeviation — réinvalidée après un placement changé", () => {
  it.each([
    ["move", (h: ReturnType<typeof useMounted>) => h.move.mutate({ id: "s1", patch: { dayOfWeek: 2, startTime: "19:00", venueId: "v1" } })],
    ["lock", (h: ReturnType<typeof useMounted>) => h.lock.mutate({ id: "s1", lockLevel: "HARD" })],
    ["place", (h: ReturnType<typeof useMounted>) => h.place.mutate({ scheduleId: "sc-1", body: { teamId: "t", dayOfWeek: 1, startTime: "18:00", venueId: "v" } })],
  ])("un %s accepté relit le diff socle↔période", async (_name, fire) => {
    const devSpy = vi.spyOn(api, "getSocleDeviation").mockResolvedValue(EMPTY);
    vi.spyOn(api, "moveSlot").mockResolvedValue({ valid: true, violations: [] } as never);
    vi.spyOn(api, "lockSlot").mockResolvedValue(undefined as never);
    vi.spyOn(api, "placeSlot").mockResolvedValue({ valid: true, violations: [] } as never);

    const { result } = renderHook(() => useMounted(), { wrapper });
    await waitFor(() => expect(result.current.dev.isSuccess).toBe(true));
    const before = devSpy.mock.calls.length;

    fire(result.current);
    await waitFor(() => expect(devSpy.mock.calls.length).toBeGreaterThan(before));
  });
});

function useMounted() {
  return { dev: useSocleDeviation("sc-1"), move: useMoveSlot(), lock: useLockSlot(), place: usePlaceSlot() };
}
