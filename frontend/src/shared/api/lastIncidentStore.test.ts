import { afterEach, describe, expect, it } from "vitest";

import { clearLastIncident, readRecentIncidentRequestId, recordIncident } from "./lastIncidentStore";

const TEN_MINUTES_MS = 10 * 60 * 1000;

afterEach(() => {
  clearLastIncident();
});

describe("lastIncidentStore", () => {
  it("retient le request-id du dernier incident serveur", () => {
    recordIncident("req-abc", 1_000);
    expect(readRecentIncidentRequestId(1_000)).toBe("req-abc");
  });

  it("le rend tant qu'il date de moins de 10 minutes", () => {
    recordIncident("req-abc", 1_000);
    expect(readRecentIncidentRequestId(1_000 + TEN_MINUTES_MS - 1)).toBe("req-abc");
  });

  // ⚠ Garde de fraîcheur : un incident vieux de plus de 10 min ne colle plus au geste
  // de signalement. Falsification prévue : retirer ce garde rend ce test rouge.
  it("l'ignore une fois passé 10 minutes", () => {
    recordIncident("req-abc", 1_000);
    expect(readRecentIncidentRequestId(1_000 + TEN_MINUTES_MS + 1)).toBeNull();
  });

  it("renvoie null quand aucun incident n'a été enregistré", () => {
    expect(readRecentIncidentRequestId(50_000)).toBeNull();
  });

  it("ne garde que le dernier incident", () => {
    recordIncident("req-1", 1_000);
    recordIncident("req-2", 2_000);
    expect(readRecentIncidentRequestId(2_000)).toBe("req-2");
  });
});
