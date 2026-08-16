import type { Compromise, CompromiseEffect } from "./api";

/**
 * Présente une liste de COMPROMIS nommés (P2-32) : les préférences SOUPLES que le geste
 * accepté cède d'abord (« broken »), celles qu'il rétablit ensuite (« gained »), chacune avec
 * une petite pastille sobre.
 *
 * PRÉSENTATION pure — le tri et la pastille dérivent de `effect`, ce qui est du LIBELLÉ, pas
 * une décision métier (le backend a déjà tranché ce qui est compromis ; cf. `.claude/rules/
 * frontend.md` : brancher un comportement sur un enum métier est interdit, choisir un libellé
 * ne l'est pas). Style volontairement SOBRE : ce sont des compromis LÉGAUX, pas des erreurs —
 * aucun rouge destructif.
 */

/** broken (une concession) en premier, gained (un gain) ensuite ; un effet inconnu à la fin. */
const rank = (effect: string): number => ("broken" === effect ? 0 : "gained" === effect ? 1 : 2);

const PILL: Record<CompromiseEffect, { label: string; className: string }> = {
  broken: { label: "Concession", className: "bg-warning/15 text-warning" },
  gained: { label: "Gain", className: "bg-accent/15 text-accent" },
};

export function CompromiseList({ compromises }: { compromises: Compromise[] }) {
  const ordered = [...compromises].sort((a, b) => rank(a.effect) - rank(b.effect));

  return (
    <ul className="flex flex-col gap-1.5">
      {ordered.map((compromise, index) => {
        // Un effet hors des deux connus dégrade proprement (pastille neutre), jamais un plantage.
        const pill = PILL[compromise.effect] ?? { label: "Compromis", className: "bg-muted text-muted-foreground" };
        return (
          <li key={`${compromise.family}-${compromise.effect}-${index}`} className="flex items-start gap-2 text-sm">
            <span className={`mt-0.5 shrink-0 rounded px-1.5 py-0.5 text-xs font-medium ${pill.className}`}>{pill.label}</span>
            <span className="text-foreground">{compromise.message}</span>
          </li>
        );
      })}
    </ul>
  );
}
