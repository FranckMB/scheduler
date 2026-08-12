import { describe, expect, it } from "vitest";

import type { Diagnostic, DiagnosticSeverity } from "../api";
import { topSeveritySummary } from "./diagnosticsSummary";

const diag = (severity: DiagnosticSeverity): Diagnostic =>
  ({ id: `${severity}-${Math.random()}`, scheduleId: "s", type: "generic", severity, teamId: null, coachId: null, venueId: null, message: "", suggestions: null }) as Diagnostic;

describe("topSeveritySummary — la sévérité la plus haute, en clair, pour la barre repliée", () => {
  it("nomme la sévérité la PLUS HAUTE présente et son décompte", () => {
    // 2 erreurs + 1 alerte → c'est l'erreur qui prime.
    expect(topSeveritySummary([diag("ERROR"), diag("ERROR"), diag("WARNING")])).toBe("2 erreurs");
  });

  it("s'accorde au singulier", () => {
    expect(topSeveritySummary([diag("ERROR"), diag("WARNING"), diag("WARNING")])).toBe("1 erreur");
  });

  it("retombe sur les alertes quand il n'y a pas d'erreur", () => {
    expect(topSeveritySummary([diag("WARNING"), diag("WARNING"), diag("INFO")])).toBe("2 alertes");
  });

  it("rend `null` sur une liste vide", () => {
    expect(topSeveritySummary([])).toBeNull();
  });
});
