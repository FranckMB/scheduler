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

  it("RÉ-AMORCE quand la VERSION change, même à forme identique", () => {
    // LE défaut du round 2. Le premier correctif comparait « sévérités + cardinalités » :
    // deux versions de même forme (1 ERROR + 1 INFO) produisaient la MÊME clé, donc aucun
    // ré-amorçage — le groupe INFO que l'utilisateur avait ouvert sur V1 restait ouvert et
    // l'ERREUR de V2 restait fermée. Deviner l'identité à partir du contenu était le tort :
    // l'appelant la connaît.
    const v1 = [diag("ERROR", "erreur V1"), diag("INFO", "info V1")];
    const v2 = [diag("ERROR", "erreur V2"), diag("INFO", "info V2")];
    const { rerender } = render(panel({ diagnostics: v1, openMostSevere: true, seedToken: "v1" }));
    expect(screen.getByText("erreur V1")).toBeInTheDocument();

    rerender(panel({ diagnostics: v2, openMostSevere: true, seedToken: "v2" }));

    expect(screen.getByText("erreur V2")).toBeInTheDocument();
  });

  it("ne ré-amorce PAS quand seul le filtre change à version constante", () => {
    // Le pendant, et l'autre moitié du défaut : la clé de forme changeait quand un filtre
    // gymnase retirait des lignes, si bien qu'un simple glisser-déposer rouvrait sous le
    // curseur un groupe que l'utilisateur venait de fermer. À version constante, l'amorce
    // ne rejoue pas.
    const complet = [diag("ERROR", "erreur filtrable"), diag("WARNING", "alerte restante")];
    const { rerender } = render(panel({ diagnostics: complet, openMostSevere: true, seedToken: "v1" }));
    expect(screen.getByText("erreur filtrable")).toBeInTheDocument();

    // Le filtre retire les ERREURS : la FORME change, la version non.
    rerender(panel({ diagnostics: [diag("WARNING", "alerte restante")], openMostSevere: true, seedToken: "v1" }));

    // Le groupe ALERTES ne s'est pas ouvert tout seul.
    expect(screen.queryByText("alerte restante")).not.toBeInTheDocument();
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
