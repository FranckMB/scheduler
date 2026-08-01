import { describe, expect, it } from "vitest";

import type { VenueTrainingSlot } from "../api";
import { DURATIONS, durationOptions, toMinutes } from "./days";
import { conflictMessage, findSlotConflict, pastMidnightMessage, slotPlacementError } from "./slotOverlap";

const slot = (over: Partial<VenueTrainingSlot>): VenueTrainingSlot =>
  ({ id: "s", venueId: "v", dayOfWeek: 1, startTime: "17:00", durationMinutes: 90, capacity: 1, ...over }) as VenueTrainingSlot;

describe("findSlotConflict", () => {
  const existing = [slot({ id: "a", dayOfWeek: 1, startTime: "17:00", durationMinutes: 90 })]; // Mon 17:00–18:30

  it("flags a slot that starts before and ends inside the window", () => {
    // Mon 16:30–18:00 overlaps 17:00–18:30.
    expect(findSlotConflict(existing, 1, "16:30", 90)?.id).toBe("a");
  });

  it("flags a slot fully contained in the window", () => {
    expect(findSlotConflict(existing, 1, "17:30", 30)?.id).toBe("a");
  });

  it("does not flag a back-to-back slot (touching edges)", () => {
    // 18:30 starts exactly when the other ends — no shared time.
    expect(findSlotConflict(existing, 1, "18:30", 60)).toBeNull();
    expect(findSlotConflict(existing, 1, "15:30", 90)).toBeNull(); // ends 17:00
  });

  it("does not flag a slot on a different weekday", () => {
    expect(findSlotConflict(existing, 2, "17:00", 90)).toBeNull();
  });

  it("ignores the slot being edited (already excluded by caller)", () => {
    // When editing 'a' itself, the caller passes an empty 'others' list.
    expect(findSlotConflict([], 1, "17:00", 90)).toBeNull();
  });
});

describe("conflictMessage / toMinutes", () => {
  it("names the conflicting slot's day + window", () => {
    expect(conflictMessage(slot({ dayOfWeek: 1, startTime: "17:00", durationMinutes: 90 }))).toContain("17:00–18:30");
  });

  it("converts HH:MM to minutes", () => {
    expect(toMinutes("18:30")).toBe(18 * 60 + 30);
  });
});

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

  it("se TAIT sur une heure illisible plutôt que de crier une heure tardive", () => {
    // Le champ « Début » est un `<input type="time">` que l'utilisateur vide en cours de
    // frappe. `toMinutes("")` rend NaN et `NaN <= DAY_END` est faux : la garde annonçait
    // « un créneau qui commence à ⟨rien⟩ ne peut pas durer 1h30 », désignait la durée que
    // personne n'avait touchée, et court-circuitait le contrôle de chevauchement placé
    // après elle. Une heure illisible se signale là où elle se saisit.
    expect(pastMidnightMessage("", 90)).toBeNull();
    expect(pastMidnightMessage("2", 90)).toBeNull();
  });

  it("refuse le créneau qui franchit minuit, en nommant l'heure et la durée", () => {
    const message = pastMidnightMessage("22:45", 150);

    expect(message).toContain("22:45");
    expect(message).toContain("2h30");
    expect(message).toContain("après minuit");
  });
});

describe("slotPlacementError — l'ordre des contrôles", () => {
  const others = [{ id: "a", venueId: "v", dayOfWeek: 1, startTime: "17:00", durationMinutes: 90, capacity: 1 } as VenueTrainingSlot];

  it("nomme le CHAMP quand l'heure de début est illisible, au lieu de laisser passer", () => {
    // Sans ce contrôle, NaN traversait toute la chaîne : la borne de minuit se taisait
    // (correctement — NaN n'est pas tardif), puis `findSlotConflict` comparait des NaN et
    // ne trouvait donc AUCUN chevauchement, et l'écriture partait avec `startTime: ""`
    // pour un 422 générique qui ne désigne aucun champ.
    expect(slotPlacementError(others, 1, "", 90)).toMatch(/heure de début/i);
  });

  it("ne masque pas un chevauchement derrière une heure valide", () => {
    expect(slotPlacementError(others, 1, "17:30", 30)).toMatch(/[Cc]hevauchement/);
  });

  it("laisse passer une pose valide", () => {
    expect(slotPlacementError(others, 1, "19:00", 90)).toBeNull();
  });

  it("nomme la durée quand la pose franchirait minuit — sur TOUS les sites", () => {
    // Le message fut un temps surchargeable, la pose au clic en période imposant 90 min
    // sans offrir de sélecteur. P4-43 lui a donné sa barre « À poser » : les quatre sites
    // règlent la durée, le message par défaut est vrai partout, la surcharge est retirée.
    expect(slotPlacementError([], 1, "22:45", 90)).toMatch(/ne peut pas durer 1h30 .*après minuit/);
  });
});

describe("durationOptions", () => {
  it("garde la durée STOCKÉE disponible même après un changement du select", () => {
    // Appelée avec le seul état édité, elle retirait l'option d'origine dès le premier
    // changement : un créneau de 30 min ouvert puis modifié ne pouvait plus revenir à 30.
    expect(durationOptions(60, 30)).toContain(30);
    expect(durationOptions(60, 30)).toEqual([...durationOptions(60, 30)].sort((a, b) => a - b));
  });

  it("ignore une durée absurde plutôt que de l'offrir", () => {
    expect(durationOptions(90, 0)).toEqual(DURATIONS);
    expect(durationOptions(90, Number.NaN)).toEqual(DURATIONS);
  });
});
