import { describe, expect, it } from "vitest";

import { pastMidnightMessage } from "./slotOverlap";

/**
 * P4-37 (revue) — UN CRÉNEAU FINIT DANS SA JOURNÉE.
 *
 * Rien ne gardait cette borne : l'API n'impose aucun maximum de durée et le seul contrôle
 * client regardait les créneaux voisins. Tant que la grille s'arrêtait à 21:45 et les
 * durées à 2h, le pire cas tombait à 23:45. Ouvrir les deux bouts a rendu « 22:45 + 2h30 »
 * posable en un clic — persisté, puis affiché « 25:15 » partout où une fin s'écrit.
 */
describe("pastMidnightMessage", () => {
  it("laisse passer un créneau du soir qui finit APRÈS la dernière ligne de la grille", () => {
    // 22:00 + 2h30 = 00:30 ? non : 23:30. Dépasser 23:00 est légitime — la borne est
    // minuit, pas la fin de la grille. Confondre les deux rendrait la saison plus stricte
    // qu'elle ne l'était avant P4-37.
    expect(pastMidnightMessage("22:00", 90)).toBeNull();
  });

  it("laisse passer un créneau qui finit PILE à minuit", () => {
    expect(pastMidnightMessage("22:00", 120)).toBeNull();
  });

  it("refuse le créneau qui franchit minuit, en nommant l'heure et la durée", () => {
    const message = pastMidnightMessage("22:45", 150);

    expect(message).toContain("22:45");
    expect(message).toContain("2h30");
    expect(message).toContain("après minuit");
  });
});
