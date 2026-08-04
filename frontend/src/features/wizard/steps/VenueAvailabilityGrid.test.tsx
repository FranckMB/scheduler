import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import type { VenueMatchWindow } from "@/features/matches/api";

import type { Venue, VenueTrainingSlot } from "../api";
import { VenueAvailabilityGrid } from "./VenueAvailabilityGrid";

const venue: Venue = { id: "v1", name: "Gymnase A", color: "#00aa00", canSplit: false, isActive: true } as Venue;

const slot = (over: Partial<VenueTrainingSlot>): VenueTrainingSlot =>
  ({ id: "s1", venueId: "v1", dayOfWeek: 1, startTime: "18:00:00", durationMinutes: 90, capacity: 1, ...over }) as VenueTrainingSlot;

const matchWindow = (over: Partial<VenueMatchWindow>): VenueMatchWindow =>
  ({ id: "w1", venueId: "v1", dayOfWeek: 6, startTime: "14:00", endTime: "22:00", ...over }) as VenueMatchWindow;

/**
 * P4-37 — ce que la grille rend POSABLE.
 *
 * ⚠ Les assertions CLIQUENT au lieu de constater une présence : une cellule rendue mais
 * inerte laisserait passer un test « la case existe », alors que le défaut corrigé est
 * précisément qu'on ne pouvait pas poser de créneau là.
 */
describe("VenueAvailabilityGrid — les bornes de ce qu'on peut poser", () => {
  it("pose un créneau le DIMANCHE (jour 7), que la grille n'affichait pas", async () => {
    const onAdd = vi.fn();
    const user = userEvent.setup();
    render(<VenueAvailabilityGrid venue={venue} slots={[]} selectedSlotId={null} onAdd={onAdd} onSelect={vi.fn()} />);

    await user.click(screen.getByRole("button", { name: "Dim 10:00" }));
    expect(onAdd).toHaveBeenCalledWith(7, "10:00");
  });

  it("pose un créneau après 22h, jusqu'au dernier quart d'heure", async () => {
    const onAdd = vi.fn();
    const user = userEvent.setup();
    render(<VenueAvailabilityGrid venue={venue} slots={[]} selectedSlotId={null} onAdd={onAdd} onSelect={vi.fn()} />);

    await user.click(screen.getByRole("button", { name: "Mar 22:45" }));
    expect(onAdd).toHaveBeenCalledWith(2, "22:45");
  });

  it("ÉTEND la grille vers le haut plutôt que de reloger un créneau matinal", async () => {
    // Trois comportements se sont succédé ici, et les deux premiers mentaient.
    //  (1) Ligne libre : 07:00 donnait `grid-row: -2`, compté depuis la FIN de la grille —
    //      le bloc s'affichait à 22:45 sous le libellé « 07:00 ».
    //  (2) Ligne BORNÉE (round 2) : le bloc venait se poser sur la ligne 08:00, recouvrant
    //      des cellules vides qui ne répondaient plus au clic et masquant un créneau de
    //      08:00 légitime. Ce test épinglait alors ce relogement, donc il le PROTÉGEAIT.
    //  (3) Grille ÉTENDUE : la plage suit les données, le bloc tombe où il est.
    const onAdd = vi.fn();
    const user = userEvent.setup();
    render(<VenueAvailabilityGrid venue={venue} slots={[slot({ startTime: "07:00:00" })]} selectedSlotId={null} onAdd={onAdd} onSelect={vi.fn()} />);

    // La grille commence à 07:00 : le créneau est sur SA ligne, la toute première.
    expect(screen.getByRole("button", { name: /^07:00 ·/ })).toHaveStyle({ gridRow: "2 / span 6" });

    // …et surtout la cellule de 08:00 reste POSABLE — c'est ce que le relogement cassait.
    await user.click(screen.getByRole("button", { name: "Lun 08:00" }));
    expect(onAdd).toHaveBeenCalledWith(1, "08:00");
  });

  it("affiche un créneau du dimanche déjà posé", () => {
    render(<VenueAvailabilityGrid venue={venue} slots={[slot({ dayOfWeek: 7, startTime: "22:00:00" })]} selectedSlotId={null} onAdd={vi.fn()} onSelect={vi.fn()} />);

    // On vise le TITRE du créneau, pas son texte : « 22:00 » figure aussi dans la
    // gouttière des heures, et un `getByText` y trouverait deux éléments — vert ou rouge
    // pour la mauvaise raison. Avant P4-37, `WEEK.findIndex` rendait -1 sur un dimanche et
    // le créneau n'était pas rendu du tout.
    expect(screen.getByTitle(/22:00 .* cliquer pour modifier/)).toBeInTheDocument();
  });
});

/**
 * Les fenêtres d'accès MATCH, en fantôme.
 *
 * Retour terrain 2026-08-04 : on pouvait ajouter une fenêtre match dans l'onglet Gymnases
 * sans jamais la voir sur la grille — elle ne vivait que dans la liste texte en dessous.
 */
describe("VenueAvailabilityGrid — les fenêtres d'accès match", () => {
  it("pose la fenêtre au bon jour, sur toute sa plage, sans jamais capter le clic", async () => {
    const onAdd = vi.fn();
    const user = userEvent.setup();
    render(
      <VenueAvailabilityGrid venue={venue} slots={[]} selectedSlotId={null} onAdd={onAdd} onSelect={vi.fn()} matchWindows={[matchWindow({ dayOfWeek: 6, startTime: "14:00", endTime: "22:00" })]} />,
    );

    // Samedi = 6ᵉ jour → colonne 7 ; 14:00 sur une grille qui commence à 08:00 → ligne
    // 2 + (360/15) = 26 ; 8 heures → 32 quarts d'heure.
    const ghost = document.querySelector('[aria-hidden="true"][style*="grid-row"]');
    expect(ghost).toHaveStyle({ gridColumn: "7", gridRow: "26 / span 32" });

    // Le fantôme recouvre la cellule de samedi 15:00 — qui doit RESTER posable : un
    // entraînement est parfois accordé dans la plage match. Un fantôme qui bloque le clic
    // supprimerait silencieusement une case de saisie légitime.
    //
    // ⚠ Les deux assertions ci-dessous ne se remplacent PAS. jsdom ne calcule aucune mise
    // en page : il ignore qu'un élément en recouvre un autre, donc le clic passerait même
    // sans `pointer-events-none`. Le clic garde que la cellule existe et reste câblée ;
    // c'est la classe, assertée explicitement, qui garde la traversée.
    expect(ghost).toHaveClass("pointer-events-none");
    await user.click(screen.getByRole("button", { name: "Sam 15:00" }));
    expect(onAdd).toHaveBeenCalledWith(6, "15:00");
  });

  it("ÉTEND la grille sur une fenêtre matinale, au lieu de la reloger", () => {
    // Même piège que le créneau de 07:00 : sans l'union, `grid-row` part en négatif et le
    // bloc s'affiche en bas de grille sous un libellé qui dit le contraire. En fantôme le
    // mensonge serait pire — rien à cliquer pour s'en apercevoir.
    render(
      <VenueAvailabilityGrid venue={venue} slots={[]} selectedSlotId={null} onAdd={vi.fn()} onSelect={vi.fn()} matchWindows={[matchWindow({ dayOfWeek: 1, startTime: "07:00", endTime: "09:00" })]} />,
    );

    // La grille commence à 07:00 : la fenêtre est sur la toute première ligne.
    const ghost = document.querySelector('[aria-hidden="true"][style*="grid-row"]');
    expect(ghost).toHaveStyle({ gridColumn: "2", gridRow: "2 / span 8" });
    // …et la grille descend bien jusqu'à 07:00 côté gouttière.
    expect(screen.getByRole("button", { name: "Lun 07:00" })).toBeInTheDocument();
  });

  it("ne rend aucun fantôme quand l'appelant ne passe pas de fenêtre (éditeur de période)", () => {
    render(<VenueAvailabilityGrid venue={venue} slots={[slot({})]} selectedSlotId={null} onAdd={vi.fn()} onSelect={vi.fn()} />);

    expect(document.querySelector('[aria-hidden="true"][style*="grid-row"]')).toBeNull();
  });
});
