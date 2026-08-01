import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
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

  it("RÉ-AMORCE quand les diagnostics changent sans démontage (changement de version)", () => {
    // LE défaut du round 1. Le sélecteur de version n'existe qu'en mode embedded : en
    // changer remplace les diagnostics SANS démonter le panneau. Avec un verrou à un coup,
    // `openSeverity` restait sur « WARNING » — sévérité absente de la nouvelle version —
    // donc AUCUN groupe ouvert : la régression même que P4-40 supprime, revenue par la
    // porte de derrière.
    const { rerender } = render(panel({ diagnostics: [diag("WARNING", "alerte V1")], openMostSevere: true }));
    expect(screen.getByText("alerte V1")).toBeInTheDocument();

    rerender(panel({ diagnostics: [diag("ERROR", "erreur V2")], openMostSevere: true }));

    expect(screen.getByText("erreur V2")).toBeInTheDocument();
  });

  it("ne DÉFAIT pas le repli manuel d'un groupe", async () => {
    // Le pendant du test ci-dessus : ré-amorcer sur tout changement rouvrirait ce que
    // l'utilisateur vient de fermer. La clé porte l'identité des diagnostics, pas le
    // nombre de rendus — refermer un groupe ne la change pas.
    const user = userEvent.setup();
    render(panel({ diagnostics: [diag("ERROR", "erreur lisible")], openMostSevere: true }));
    expect(screen.getByText("erreur lisible")).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: /Erreurs/ }));

    expect(screen.queryByText("erreur lisible")).not.toBeInTheDocument();
  });

  it("ne déplie RIEN en boucle de travail — la demande utilisateur d'origine ne bouge pas", () => {
    render(panel({ diagnostics: [diag("ERROR", "erreur lisible")] }));

    expect(screen.queryByText("erreur lisible")).not.toBeInTheDocument();
  });
});
