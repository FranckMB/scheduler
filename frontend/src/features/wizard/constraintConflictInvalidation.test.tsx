import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { renderHook, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { describe, expect, it, vi } from "vitest";

import * as wizardApi from "./api";
import { useCreateConstraint, useDeleteConstraint, useUpdateConstraint } from "./queries";

vi.mock("./api", () => ({
  createConstraint: vi.fn(() => Promise.resolve({ id: "c1" })),
  updateConstraint: vi.fn(() => Promise.resolve({ id: "c1" })),
  deleteConstraint: vi.fn(() => Promise.resolve()),
}));

/**
 * D7 (P2-22) — une contrainte DATÉE (fermeture de gymnase) modifie ce que sert
 * `/calendar-entries/{id}/conflicts` (les `closures`). La grille de la période lit cet
 * endpoint : sans invalidation de `["entry-conflicts"]`, elle reste périmée après la saisie
 * ou la suppression d'une fermeture depuis le wizard.
 */
describe("les mutations de contrainte invalident les conflits d'entrée", () => {
  const harness = () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    const spy = vi.spyOn(queryClient, "invalidateQueries");
    const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;
    return { spy, wrapper };
  };

  const invalidatedConflicts = (spy: ReturnType<typeof vi.spyOn>): boolean =>
    spy.mock.calls.some(([arg]: [unknown]) => {
      const key = (arg as { queryKey?: unknown[] } | undefined)?.queryKey;
      return Array.isArray(key) && "entry-conflicts" === key[0];
    });

  it("créer une contrainte invalide entry-conflicts", async () => {
    const { spy, wrapper } = harness();
    const { result } = renderHook(() => useCreateConstraint(), { wrapper });
    result.current.mutate({ name: "n", scope: "FACILITY", family: "FACILITY", ruleType: "HARD", config: {} });
    await waitFor(() => expect(vi.mocked(wizardApi.createConstraint)).toHaveBeenCalled());
    await waitFor(() => expect(invalidatedConflicts(spy)).toBe(true));
  });

  it("modifier une contrainte invalide entry-conflicts", async () => {
    const { spy, wrapper } = harness();
    const { result } = renderHook(() => useUpdateConstraint(), { wrapper });
    result.current.mutate({ id: "c1", body: { name: "n", scope: "FACILITY", family: "FACILITY", ruleType: "HARD", config: {} } });
    await waitFor(() => expect(vi.mocked(wizardApi.updateConstraint)).toHaveBeenCalled());
    await waitFor(() => expect(invalidatedConflicts(spy)).toBe(true));
  });

  it("supprimer une contrainte invalide entry-conflicts", async () => {
    const { spy, wrapper } = harness();
    const { result } = renderHook(() => useDeleteConstraint(), { wrapper });
    result.current.mutate("c1");
    await waitFor(() => expect(vi.mocked(wizardApi.deleteConstraint)).toHaveBeenCalled());
    await waitFor(() => expect(invalidatedConflicts(spy)).toBe(true));
  });
});
