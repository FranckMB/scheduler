import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import type { Diagnostic, DiagnosticSeverity } from "./api";
import { DiagnosticsPanel } from "./DiagnosticsPanel";
import type { Lookups } from "./lib/grid";

const lookups: Lookups = {
  teams: new Map(),
  venues: new Map(),
  coaches: new Map(),
  teamCoach: new Map(),
  teamPlayerCoaches: new Map(),
};

const diag = (severity: DiagnosticSeverity, message: string): Diagnostic =>
  ({ id: `${severity}-${message}`, scheduleId: "s", type: "generic", severity, teamId: null, coachId: null, venueId: null, message, suggestions: null }) as Diagnostic;

const panel = (props: Partial<Parameters<typeof DiagnosticsPanel>[0]> = {}) => (
  <DiagnosticsPanel diagnostics={[]} slots={[]} lookups={lookups} onHighlight={vi.fn()} {...props} />
);

/**
 * P4-40 — LE SECOND REPLI.
 *
 * Ouvrir le panneau ne suffisait pas : ses groupes par sévérité restaient TOUS fermés, si
 * bien qu'un diagnostic était encore à deux clics. Le retour terrain visait la visibilité
 * (« on risque de ne pas le voir si on n'est pas familier avec l'écran génération ») —
 * un panneau ouvert sur des groupes fermés ne la donne pas.
 */
describe("DiagnosticsPanel — ouverture contextuelle", () => {
  it("déplie le groupe le PLUS SÉVÈRE présent quand on sort du wizard", () => {
    render(panel({ diagnostics: [diag("INFO", "info lisible"), diag("ERROR", "erreur lisible")], openMostSevere: true }));

    // Le message de l'erreur est lisible sans le moindre clic…
    expect(screen.getByText("erreur lisible")).toBeInTheDocument();
    // …et le groupe INFO reste replié : ouvrir TOUT ferait un mur.
    expect(screen.queryByText("info lisible")).not.toBeInTheDocument();
  });

  it("déplie le plus sévère PRÉSENT, pas ERROR par principe", () => {
    render(panel({ diagnostics: [diag("INFO", "info lisible"), diag("WARNING", "alerte lisible")], openMostSevere: true }));

    expect(screen.getByText("alerte lisible")).toBeInTheDocument();
    expect(screen.queryByText("info lisible")).not.toBeInTheDocument();
  });

  it("ne déplie RIEN en boucle de travail — la demande utilisateur d'origine ne bouge pas", () => {
    render(panel({ diagnostics: [diag("ERROR", "erreur lisible")] }));

    expect(screen.queryByText("erreur lisible")).not.toBeInTheDocument();
  });
});
