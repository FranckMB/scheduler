import { describe, expect, it } from "vitest";

import { isServiceDown, SERVICE_FAILURE_TYPES } from "./serviceFailure";

const err = (type: string) => ({ type, severity: "ERROR" as const });

describe("serviceFailure — isServiceDown (panne du service ≠ planning infaisable)", () => {
  it("VRAI sur chacun des 4 types de panne, seuls", () => {
    for (const type of SERVICE_FAILURE_TYPES) {
      expect(isServiceDown([err(type)])).toBe(true);
    }
  });

  it("FAUX sur engine_failed — le moteur a RÉPONDU « failed » (planning infaisable), pas une panne", () => {
    expect(isServiceDown([err("engine_failed")])).toBe(false);
  });

  it("FAUX sur un mélange panne + infaisable (un seul diagnostic métier suffit à disculper le service)", () => {
    expect(isServiceDown([err("engine_timeout"), err("engine_failed")])).toBe(false);
    expect(isServiceDown([err("session_below_effective_min"), err("engine_error")])).toBe(false);
  });

  it("FAUX sur une liste vide — l'écran de panne ne s'affiche jamais par défaut", () => {
    expect(isServiceDown([])).toBe(false);
  });

  it("FAUX sur overlay_entry_missing — échec DOMAINE (période supprimée), pas une panne", () => {
    expect(isServiceDown([err("overlay_entry_missing")])).toBe(false);
  });

  it("ne regarde QUE les diagnostics ERROR : un WARNING ne disqualifie ni ne qualifie", () => {
    // Un WARNING (unplaced) à côté d'un ERROR de panne : toujours une panne.
    expect(isServiceDown([{ type: "engine_timeout", severity: "ERROR" }, { type: "unplaced", severity: "WARNING" }])).toBe(true);
    // Aucun ERROR (que des WARNING/INFO) : pas de panne (le sous-ensemble ERROR est vide).
    expect(isServiceDown([{ type: "engine_timeout", severity: "WARNING" }])).toBe(false);
  });
});
