import type { Diagnostic, DiagnosticSeverity } from "../api";

/** Du plus grave au moins grave — foyer unique de l'ordre des sévérités (partagé DiagnosticsPanel). */
export const SEVERITY_ORDER: DiagnosticSeverity[] = ["ERROR", "WARNING", "INFO", "SUCCESS"];

// Nom COMPTÉ par sévérité pour la barre repliée (« 2 erreurs »). Distinct des en-têtes de groupe
// du panneau (« Erreurs »), qui sont des titres, pas des décomptes.
const SEVERITY_NOUN: Record<DiagnosticSeverity, { one: string; many: string }> = {
  ERROR: { one: "erreur", many: "erreurs" },
  WARNING: { one: "alerte", many: "alertes" },
  INFO: { one: "info", many: "infos" },
  SUCCESS: { one: "ok", many: "ok" },
};

/**
 * La sévérité la PLUS HAUTE présente + son décompte, en clair (« 2 erreurs ») — pour la barre
 * repliée des diagnostics : repliés, ils ne disparaissent pas, l'essentiel (combien, de quelle
 * gravité) reste lisible. `null` sur une liste vide.
 */
export function topSeveritySummary(diagnostics: readonly Diagnostic[]): string | null {
  for (const severity of SEVERITY_ORDER) {
    const count = diagnostics.filter((d) => d.severity === severity).length;
    if (count > 0) {
      const noun = SEVERITY_NOUN[severity];

      return `${count} ${1 === count ? noun.one : noun.many}`;
    }
  }

  return null;
}
