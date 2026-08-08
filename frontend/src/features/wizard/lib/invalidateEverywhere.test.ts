import { QueryClient } from "@tanstack/react-query";
import { describe, expect, it, vi } from "vitest";

import { invalidateEverywhere } from "../queries";

/**
 * D-25 — le wizard écrit sous `["wizard", …]` et n'invalidait QUE cette clé, alors que
 * Planning et Matchs lisent les mêmes ressources sous `["teams"]`/`["venues"]`/`["coaches"]`
 * avec un `staleTime` de cinq minutes.
 *
 * Effet mesuré : après avoir ajouté un gymnase dans l'assistant, l'écran Matchs ne le
 * proposait pas pendant cinq minutes, et une équipe renommée y gardait son ancien nom. Rien
 * ne le signalait — le cache faisait exactement son travail.
 */
describe("invalidation croisée du wizard (D-25)", () => {
  it("invalide la clé du wizard ET la clé partagée des autres écrans", async () => {
    const queryClient = new QueryClient();
    const invalidate = vi.spyOn(queryClient, "invalidateQueries").mockResolvedValue();

    await invalidateEverywhere(queryClient, "venues");

    const keys = invalidate.mock.calls.map((call) => JSON.stringify(call[0]?.queryKey));
    expect(keys).toContain(JSON.stringify(["wizard", "venues"]));
    expect(keys, "sans la clé partagée, Planning et Matchs gardent 5 min de données périmées").toContain(JSON.stringify(["venues"]));
  });

  it("vaut pour les trois familles partagées", async () => {
    const queryClient = new QueryClient();
    const invalidate = vi.spyOn(queryClient, "invalidateQueries").mockResolvedValue();

    for (const family of ["teams", "venues", "coaches"] as const) {
      await invalidateEverywhere(queryClient, family);
    }

    const keys = invalidate.mock.calls.map((call) => JSON.stringify(call[0]?.queryKey));
    for (const family of ["teams", "venues", "coaches"]) {
      expect(keys).toContain(JSON.stringify([family]));
    }
  });
});
