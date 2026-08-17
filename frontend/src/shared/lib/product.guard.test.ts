import { readFileSync, readdirSync } from "node:fs";
import { join } from "node:path";

import { describe, expect, it } from "vitest";

import { PRODUCT_NAME, PUBLISHER_NAME } from "./product";

/**
 * P5-15 — le nom produit a UNE maison côté frontend : `product.ts`.
 *
 * Le test qui compte est le second : il interdit que l'ancien nom commercial
 * (« ClubScheduler ») revienne EN DUR dans `src/`. C'est ce qui a rendu le
 * renommage coûteux — des libellés d'écran à retrouver un par un. Un
 * « ClubScheduler » littéral échoue ici, en nommant le fichier et la ligne.
 *
 * UN littéral technique reste, EXCLU par écrit et pour un temps borné : l'ANCIENNE
 * clé de brouillon de vœux (`clubscheduler:wish-draft:<token>`), que `wishDraft.ts`
 * n'ÉCRIT plus — il l'a renommée — mais LIT encore en repli, pour ne pas jeter la
 * saisie d'un coach en cours au moment du déploiement. C'est une clé de PERSISTANCE,
 * pas un texte lu par un humain, et le repli s'enlève après une saison
 * (`wishDraft.test.ts` garde les trois comportements). Une exclusion sans raison
 * écrite serait une régression déguisée.
 */
const SRC_ROOT = join(import.meta.dirname, "..", "..");

/** Sous-chaîne technique tolérée : l'ANCIENNE clé de brouillon, lue en repli, jamais écrite. */
const TECHNICAL_EXCEPTION = "clubscheduler:wish-draft:";

function sourceFiles(dir: string): string[] {
  const out: string[] = [];
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = join(dir, entry.name);
    if (entry.isDirectory()) {
      if (entry.name === "node_modules") continue;
      out.push(...sourceFiles(full));
    } else if (/\.(ts|tsx)$/.test(entry.name)) {
      out.push(full);
    }
  }
  return out;
}

describe("product identity", () => {
  it("exposes the commercial name and the publisher", () => {
    expect(PRODUCT_NAME).toBe("Amateo");
    expect(PUBLISHER_NAME).toBe("Maratech");
  });

  it("does not hard-code the old product name in src", () => {
    const offenders: string[] = [];
    for (const file of sourceFiles(SRC_ROOT)) {
      // Le fichier de garde lui-même cite l'ancien nom dans sa documentation.
      if (file.endsWith("product.guard.test.ts")) continue;
      const lines = readFileSync(file, "utf8").split("\n");
      lines.forEach((line, index) => {
        if (!/clubscheduler/i.test(line)) return;
        if (line.includes(TECHNICAL_EXCEPTION)) return;
        offenders.push(`${file.replace(`${SRC_ROOT}/`, "")}:${index + 1}`);
      });
    }
    expect(offenders, "Ancien nom produit codé en dur : importer PRODUCT_NAME depuis @/shared/lib/product.").toEqual([]);
  });
});
