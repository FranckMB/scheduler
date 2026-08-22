import { describe, expect, it } from "vitest";

import { IN_FLIGHT_STATUSES, isTerminalStatus } from "./scheduleStatus";

/**
 * D-31 — « une génération est-elle en vol ? » était déclaré à CINQ endroits, dont une
 * négation inline dans le flux SSE. Un statut non terminal ajouté et oublié quelque part,
 * et certains écrans arrêtent de poller ou réactivent leurs boutons EN PLEINE génération
 * pendant que d'autres continuent.
 */
describe("statuts de génération (foyer unique, D-31)", () => {
  it("PENDING et GENERATING sont en vol, les autres sont terminaux", () => {
    expect([...IN_FLIGHT_STATUSES]).toEqual(["PENDING", "GENERATING"]);
    expect(isTerminalStatus("PENDING")).toBe(false);
    expect(isTerminalStatus("GENERATING")).toBe(false);
    expect(isTerminalStatus("COMPLETED")).toBe(true);
    expect(isTerminalStatus("FAILED")).toBe(true);
    expect(isTerminalStatus("DRAFT")).toBe(true);
  });

  /**
   * Le cas qui a fait vivre la négation inline : un statut absent ne doit pas être
   * confondu avec un statut terminal — sinon l'écran croit la génération finie.
   */
  it("un statut inconnu (null) n'est pas terminal", () => {
    expect(isTerminalStatus(null)).toBe(false);
  });
});
