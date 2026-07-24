import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { renderHook, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { describe, expect, it, vi } from "vitest";

import * as wizardApi from "./api";
import { useClearVenuePeriodGrid, useResetVenuePeriodGrid, useSetVenuePeriodMode } from "./queries";

vi.mock("./api", () => ({
  resetVenuePeriodGrid: vi.fn(() => Promise.resolve({})),
  clearVenuePeriodGrid: vi.fn(() => Promise.resolve({})),
  createVenuePeriodOverride: vi.fn(() => Promise.resolve({ id: "o1" })),
  updateVenuePeriodOverride: vi.fn(() => Promise.resolve({ id: "o1" })),
}));

/**
 * Revue #8 PR-B, finding #2 — vider/reprendre une grille supprime EN CASCADE les
 * réservations du gymnase côté serveur. Le cache des réservations DOIT donc être invalidé,
 * sinon l'onglet « Réserver » et le récap affichent des épinglages fantômes et le décompte
 * de la confirmation suivante ment (invariant n°4). Ce test garde l'invalidation croisée.
 */
describe("les actions de grille invalident le cache des réservations", () => {
  const PLAN = "plan-1";

  const harness = () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    const spy = vi.spyOn(queryClient, "invalidateQueries");
    const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;
    return { spy, wrapper };
  };

  const invalidatedReservations = (spy: ReturnType<typeof vi.spyOn>): boolean =>
    spy.mock.calls.some(([arg]: [unknown]) => {
      const key = (arg as { queryKey?: unknown[] } | undefined)?.queryKey;
      return Array.isArray(key) && "wizard" === key[0] && "reservations" === key[1] && PLAN === key[2];
    });

  it("« reprendre la grille » invalide les réservations", async () => {
    const { spy, wrapper } = harness();
    const { result } = renderHook(() => useResetVenuePeriodGrid(PLAN), { wrapper });
    result.current.mutate("v1");
    await waitFor(() => expect(vi.mocked(wizardApi.resetVenuePeriodGrid)).toHaveBeenCalled());
    await waitFor(() => expect(invalidatedReservations(spy)).toBe(true));
  });

  it("« vider la grille » invalide les réservations", async () => {
    const { spy, wrapper } = harness();
    const { result } = renderHook(() => useClearVenuePeriodGrid(PLAN), { wrapper });
    result.current.mutate("v1");
    await waitFor(() => expect(vi.mocked(wizardApi.clearVenuePeriodGrid)).toHaveBeenCalled());
    await waitFor(() => expect(invalidatedReservations(spy)).toBe(true));
  });

  it("changer le mode d'un gymnase invalide aussi les réservations", async () => {
    const { spy, wrapper } = harness();
    const { result } = renderHook(() => useSetVenuePeriodMode(PLAN), { wrapper });
    result.current.mutate({ venueId: "v1", mode: "DISABLED" });
    await waitFor(() => expect(vi.mocked(wizardApi.createVenuePeriodOverride)).toHaveBeenCalled());
    await waitFor(() => expect(invalidatedReservations(spy)).toBe(true));
  });
});
