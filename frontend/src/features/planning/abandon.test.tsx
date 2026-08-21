import { QueryClientProvider } from "@tanstack/react-query";
import { renderHook, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { ReactNode } from "react";

import { abortLongActions } from "@/shared/lib/longActionAbort";
import { queryClient } from "@/shared/lib/queryClient";
import { useToastStore } from "@/shared/stores/toastStore";

import * as api from "./api";
import { VerdictAbandonedError } from "./api";
import { useMoveDryRun, useMoveSlot, usePlaceSlot } from "./queries";

// ⚠ VRAI queryClient de prod (filet global + invalidations réelles) — muter la PROD, pas le mock.
const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;

afterEach(() => {
  vi.restoreAllMocks();
  useToastStore.setState({ toasts: [] });
  queryClient.clear();
});

const invalidatedKeys = (spy: ReturnType<typeof vi.spyOn>): unknown[] =>
  spy.mock.calls.map((c: unknown[]) => (c[0] as { queryKey?: unknown[] } | undefined)?.queryKey?.[0]);

describe("Abandon d'un déplacement (voile bloquant, lot C PR-2)", () => {
  it("useMoveSlot : le signal reçu par la mutationFn est réellement aborté, et le MÊME paquet est réinvalidé", async () => {
    let captured: AbortSignal | undefined;
    vi.spyOn(api, "moveSlot").mockImplementation(
      (_id, _patch, signal) =>
        new Promise((_res, reject) => {
          captured = signal;
          signal?.addEventListener("abort", () => reject(new VerdictAbandonedError()));
        }),
    );
    const invalidateSpy = vi.spyOn(queryClient, "invalidateQueries");
    const { result } = renderHook(() => useMoveSlot(), { wrapper });

    result.current.mutate({ id: "s1", patch: { dayOfWeek: 2, startTime: "19:00", venueId: "v1" } });
    await waitFor(() => expect(captured).toBeDefined());

    abortLongActions();
    await waitFor(() => expect(result.current.isError).toBe(true));

    expect(captured?.aborted).toBe(true);
    expect(result.current.error).toBeInstanceOf(VerdictAbandonedError);
    // Le serveur a PU l'appliquer : on resynchronise le paquet d'un déplacement accepté.
    const keys = invalidatedKeys(invalidateSpy);
    expect(keys).toEqual(expect.arrayContaining(["slots", "schedules", "diagnostics", "socle-deviation"]));
    // Un mot est affiché — jamais un voile/geste qui disparaît en silence.
    expect(useToastStore.getState().toasts.some((t) => /abandonn/i.test(t.message) && /rechargé/i.test(t.message))).toBe(true);
  });

  it("usePlaceSlot : abandon → même paquet réinvalidé, message affiché", async () => {
    vi.spyOn(api, "placeSlot").mockImplementation(
      (_scheduleId, _body, signal) =>
        new Promise((_res, reject) => {
          signal?.addEventListener("abort", () => reject(new VerdictAbandonedError()));
        }),
    );
    const invalidateSpy = vi.spyOn(queryClient, "invalidateQueries");
    const { result } = renderHook(() => usePlaceSlot(), { wrapper });

    result.current.mutate({ scheduleId: "sc", body: { teamId: "t", dayOfWeek: 1, startTime: "18:00", venueId: "v" } });
    await waitFor(() => expect(result.current.isPending).toBe(true));

    abortLongActions();
    await waitFor(() => expect(result.current.isError).toBe(true));

    const keys = invalidatedKeys(invalidateSpy);
    expect(keys).toEqual(expect.arrayContaining(["slots", "schedules", "diagnostics", "socle-deviation"]));
  });

  it("useMoveDryRun : un essai abandonné ne resynchronise RIEN (l'essai n'écrit jamais)", async () => {
    vi.spyOn(api, "moveSlot").mockImplementation(
      (_id, _patch, signal) =>
        new Promise((_res, reject) => {
          signal?.addEventListener("abort", () => reject(new VerdictAbandonedError()));
        }),
    );
    const invalidateSpy = vi.spyOn(queryClient, "invalidateQueries");
    const { result } = renderHook(() => useMoveDryRun(), { wrapper });

    result.current.mutate({ id: "s1", patch: { dayOfWeek: 2, startTime: "19:00", venueId: "v1" } });
    await waitFor(() => expect(result.current.isPending).toBe(true));

    abortLongActions();
    await waitFor(() => expect(result.current.isError).toBe(true));

    expect(invalidateSpy).not.toHaveBeenCalled();
  });
});
