import { QueryClientProvider } from "@tanstack/react-query";
import { renderHook, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { ReactNode } from "react";

import { queryClient } from "@/shared/lib/queryClient";
import { useToastStore } from "@/shared/stores/toastStore";

import * as api from "./api";
import { MoveRejectedError } from "./api";
import { useMoveSlot, usePlaceSlot } from "./queries";

// ⚠ On câble le VRAI `queryClient` de prod — celui qui porte le filet global
// `MutationCache.onError`. renderWithProviders fabrique un client NEUF sans ce filet :
// il ne reproduirait donc pas le bug (double toast « Problème de connexion » sur un refus
// 422). Muter la PROD, pas le mock (frontend.md).
const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;

const hasConnToast = () => useToastStore.getState().toasts.some((t) => /Problème de connexion/.test(t.message));

afterEach(() => {
  vi.restoreAllMocks();
  useToastStore.setState({ toasts: [] });
  queryClient.clear();
});

// Bug vérifié sur la stack réelle : le filet global toaste « Problème de connexion » pour toute
// mutation SANS onError de NIVEAU HOOK. Comme le feedback des refus vit au niveau mutate() (page),
// un refus MÉTIER (MoveRejectedError…) tombait dans le filet → toast mensonger (le réseau va bien).
describe("useMoveSlot / usePlaceSlot — le hook possède son feedback, le filet global ne double plus", () => {
  it("useMoveSlot : un refus MÉTIER (MoveRejectedError) ne toaste JAMAIS « Problème de connexion »", async () => {
    vi.spyOn(api, "moveSlot").mockRejectedValue(new MoveRejectedError([{ rule: "coach_double_booking", message: "conflit." }]));
    const { result } = renderHook(() => useMoveSlot(), { wrapper });

    result.current.mutate({ id: "s1", patch: { dayOfWeek: 2, startTime: "19:00", venueId: "v1" } });
    await waitFor(() => expect(result.current.isError).toBe(true));

    expect(hasConnToast()).toBe(false);
  });

  it("usePlaceSlot : un refus MÉTIER ne toaste JAMAIS « Problème de connexion »", async () => {
    vi.spyOn(api, "placeSlot").mockRejectedValue(new MoveRejectedError([{ rule: "coach_double_booking", message: "conflit." }]));
    const { result } = renderHook(() => usePlaceSlot(), { wrapper });

    result.current.mutate({ scheduleId: "sc", body: { teamId: "t", dayOfWeek: 1, startTime: "18:00", venueId: "v" } });
    await waitFor(() => expect(result.current.isError).toBe(true));

    expect(hasConnToast()).toBe(false);
  });

  it("useMoveSlot : un VRAI échec transport (Error nue) → toast « Problème de connexion » (le filet du hook marche)", async () => {
    vi.spyOn(api, "moveSlot").mockRejectedValue(new Error("network down"));
    const { result } = renderHook(() => useMoveSlot(), { wrapper });

    result.current.mutate({ id: "s1", patch: { dayOfWeek: 2, startTime: "19:00", venueId: "v1" } });
    await waitFor(() => expect(hasConnToast()).toBe(true));
  });

  it("usePlaceSlot : un VRAI échec transport → toast « Problème de connexion »", async () => {
    vi.spyOn(api, "placeSlot").mockRejectedValue(new Error("network down"));
    const { result } = renderHook(() => usePlaceSlot(), { wrapper });

    result.current.mutate({ scheduleId: "sc", body: { teamId: "t", dayOfWeek: 1, startTime: "18:00", venueId: "v" } });
    await waitFor(() => expect(hasConnToast()).toBe(true));
  });
});
