import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { renderHook, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

// On mocke l'APPEL RÉSEAU, pas le hook : la query react-query et `usePeriodAnchor`
// s'exécutent pour de vrai. Mocker `usePeriodAnchor` (ou même
// `useSchedulePlanForEntry`, appelé DANS le même module) reviendrait à tester une
// copie de la logique — c'est exactement ce que font les tests d'écran.
const getPlan = vi.fn();
vi.mock("./api", () => ({ getSchedulePlanForEntry: (...a: unknown[]) => getPlan(...a) }));

const { anchorIsWritable, usePeriodAnchor } = await import("./queries");

let client: QueryClient;

function wrapper({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
}

/**
 * Le VRAI hook, exécuté.
 *
 * Tous les tests d'écran mockent `usePeriodAnchor` en RE-COPIANT sa logique de
 * précédence : ils valident donc leur propre copie, pas la production. La
 * mutation qui devait prouver l'invariant « une ancre en cache l'emporte »
 * mutait en réalité le mock (revue #303 round 1). Ce fichier est le seul
 * endroit où la fonction livrée est mise à l'épreuve.
 */
describe("usePeriodAnchor — la vraie précédence", () => {
  beforeEach(() => {
    getPlan.mockReset();
    client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  });

  it("hors mode période, `base` : `null` EST l'ancre légitime (structure partagée)", () => {
    const { result } = renderHook(() => usePeriodAnchor(null), { wrapper });

    expect(result.current.state).toBe("base");
    expect(result.current.planId).toBeNull();
    expect(anchorIsWritable(result.current)).toBe(true);
  });

  it("query résolue avec un plan : `period`, et c'est le seul état écrivable en période", async () => {
    getPlan.mockResolvedValue({ id: "plan-1" });

    const { result } = renderHook(() => usePeriodAnchor("e1"), { wrapper });

    await waitFor(() => expect(result.current.state).toBe("period"));
    expect(result.current.planId).toBe("plan-1");
    expect(anchorIsWritable(result.current)).toBe(true);
  });

  it("query en échec SANS ancre : `failed`, et `retry` relance vraiment l'appel", async () => {
    getPlan.mockRejectedValue(new Error("500"));

    const { result } = renderHook(() => usePeriodAnchor("e1"), { wrapper });

    await waitFor(() => expect(result.current.state).toBe("failed"));
    expect(anchorIsWritable(result.current)).toBe(false);
    if ("failed" !== result.current.state) {
      throw new Error("état inattendu");
    }
    const callsBefore = getPlan.mock.calls.length;
    result.current.retry();
    await waitFor(() => expect(getPlan.mock.calls.length).toBeGreaterThan(callsBefore));
  });

  it("une ancre EN CACHE l'emporte sur un refetch en échec (l'écran ne doit pas être détruit)", async () => {
    // react-query garde `data` et bascule `status` à error sur un refetch d'arrière-plan :
    // basculer en `failed` ici viderait une vue parfaitement fonctionnelle.
    getPlan.mockResolvedValueOnce({ id: "plan-1" }).mockRejectedValue(new Error("500"));

    const { result } = renderHook(() => usePeriodAnchor("e1"), { wrapper });
    await waitFor(() => expect(result.current.state).toBe("period"));

    // Un VRAI refetch d'arrière-plan, qui échoue alors que la donnée est déjà en cache.
    await client.refetchQueries({ queryKey: ["calendar-entries", "plan", "e1"] });
    await waitFor(() => expect(client.getQueryState(["calendar-entries", "plan", "e1"])?.status).toBe("error"));

    // La query est en `error` ET porte encore sa donnée : l'ancre doit rester utilisable.
    expect(client.getQueryState(["calendar-entries", "plan", "e1"])?.data).toEqual({ id: "plan-1" });
    expect(result.current.state).toBe("period");
    expect(result.current.planId).toBe("plan-1");
  });

  it("query RÉGLÉE sans plan : `absent`, jamais `loading` (sinon spinner éternel)", async () => {
    // Le cul-de-sac d'origine : une période sans espace de travail s'annonçait « en
    // chargement » pour toujours, sans message ni geste possible.
    getPlan.mockResolvedValue(null);

    const { result } = renderHook(() => usePeriodAnchor("e1"), { wrapper });

    await waitFor(() => expect(result.current.state).toBe("absent"));
    expect(anchorIsWritable(result.current)).toBe(false);
  });

  it("un `null` PÉRIMÉ en cours de refetch reste `loading`, jamais `absent`", async () => {
    // Le piège : après « Adapter », la clé est invalidée et re-fetchée alors qu'un
    // `null` périmé est encore en cache. `isLoading` est faux (il y a une donnée) —
    // conclure `absent` disait « adaptez cette période » un clic après que le
    // gestionnaire l'a fait. On ne conclut rien tant que ça vole.
    getPlan.mockResolvedValueOnce(null);
    const { result } = renderHook(() => usePeriodAnchor("e1"), { wrapper });
    await waitFor(() => expect(result.current.state).toBe("absent"));

    // Refetch en vol (la réponse ne revient pas tout de suite).
    getPlan.mockReturnValue(new Promise(() => {}));
    void client.refetchQueries({ queryKey: ["calendar-entries", "plan", "e1"] });

    await waitFor(() => expect(result.current.state).toBe("loading"));
  });

  it("pendant le vol : `loading`, jamais écrivable", () => {
    getPlan.mockReturnValue(new Promise(() => {})); // ne se résout jamais

    const { result } = renderHook(() => usePeriodAnchor("e1"), { wrapper });

    expect(result.current.state).toBe("loading");
    expect(anchorIsWritable(result.current)).toBe(false);
  });
});
