import { describe, expect, it } from "vitest";

import { scheduleIdToReuse } from "./retryTarget";

/**
 * AUD-FRT-09 — les quatre cas de « quelle version relancer ? ».
 *
 * ⚑ Ces tests existent parce que la règle, montée dans `GenerateStep`, n'était PAS
 * falsifiable : remplacer sa condition par « réutiliser toujours » laissait la suite verte
 * (falsification F2). L'écran ne sait produire que deux des quatre situations, et celles
 * qu'il rate sont précisément celles où se joue le risque.
 */
describe("quelle version relancer (AUD-FRT-09)", () => {
  it("premier lancement : aucune version à reprendre", () => {
    expect(scheduleIdToReuse({ periodMode: false, status: null, scheduleId: null })).toBeUndefined();
  });

  it("reprise après un FAILED avéré : la MÊME version", () => {
    expect(scheduleIdToReuse({ periodMode: false, status: "FAILED", scheduleId: "sched-1" })).toBe("sched-1");
  });

  /**
   * Le cas que l'écran ne sait pas jouer, et le seul qui puisse casser quelque chose : au
   * bout de 20 min l'UI déclare un timeout, mais le solve TOURNE peut-être encore.
   * Relancer le même identifiant buterait sur le verrou de club — une attente deviendrait
   * une erreur.
   */
  it("timeout d'affichage sur une génération encore en vol : surtout PAS la même", () => {
    expect(scheduleIdToReuse({ periodMode: false, status: "GENERATING", scheduleId: "sched-1" })).toBeUndefined();
    expect(scheduleIdToReuse({ periodMode: false, status: "PENDING", scheduleId: "sched-1" })).toBeUndefined();
  });

  it("statut inconnu : traité comme un vol en cours, jamais comme un échec", () => {
    expect(scheduleIdToReuse({ periodMode: false, status: null, scheduleId: "sched-1" })).toBeUndefined();
  });

  it("mode période : la reprise passe par l'overlay de l'entrée, pas par ici", () => {
    expect(scheduleIdToReuse({ periodMode: true, status: "FAILED", scheduleId: "sched-1" })).toBeUndefined();
  });
});
