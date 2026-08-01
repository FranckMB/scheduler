import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { Team } from "../api";

const baseTeam: Team = {
  id: "t1", name: "SM3", sportCategoryId: "cat1", priorityTierId: 5, tierOrder: 0,
  gender: "M", level: "DEPARTEMENTAL", sessionsPerWeek: 1, isActive: true,
};
// Mutable : « engagée » vient du serveur, donc les tests le font varier comme lui.
let team: Team = baseTeam;
// P4-36 : le tri et les flèches demandent PLUSIEURS équipes, dans plusieurs rangs et
// plusieurs catégories. `teamsState` remplace la liste quand un cas en a besoin.
const teamsState: { data: Team[] | null } = { data: null };
const CATEGORIES = [
  { id: "catVet", name: "Vétéran", sortOrder: 0 },
  { id: "cat1", name: "Senior", sortOrder: 1 },
  { id: "catU11", name: "U11", sortOrder: 6 },
];

const createMut = vi.fn();
const updateMut = vi.fn();
const reorderMut = vi.fn();
const deleteMut = vi.fn();

vi.mock("../queries", () => ({
  useWizardTeams: () => ({ data: teamsState.data ?? [team] }),
  useSportCategories: () => ({ data: CATEGORIES }),
  usePriorityTiers: () => ({
    data: [
      { id: 1, label: "S", name: "Elite", color: null },
      { id: 5, label: "D", name: "Bonus", color: null },
    ],
  }),
  useCreateTeam: () => ({ mutate: createMut, isPending: false }),
  useUpdateTeam: () => ({ mutate: updateMut }),
  useDeleteTeam: () => ({ mutate: deleteMut }),
  useReorderTeams: () => ({ mutate: reorderMut }),
  useReservations: () => ({ data: [{ id: "r1", teamId: "t1", venueId: "v1", dayOfWeek: 2, startTime: "20:30", durationMinutes: 90, calendarEntryId: null }] }),
  useWizardTeamCoaches: () => ({ data: [] }),
  useWizardCoachPlayers: () => ({ data: [] }),
}));

import { TeamsStep } from "./TeamsStep";

describe("TeamsStep", () => {
  beforeEach(() => {
    team = baseTeam;
    teamsState.data = null;
    reorderMut.mockClear();
    createMut.mockClear();
    updateMut.mockClear();
    deleteMut.mockClear();
  });

  it("deleting a team confirms the impact first, then deletes on confirm", async () => {
    const user = userEvent.setup();
    renderWithProviders(<TeamsStep />);

    // The row's Trash button (aria-label "Supprimer") opens the confirmation.
    await user.click(screen.getByRole("button", { name: "Supprimer" }));
    const dialog = screen.getByRole("dialog");
    // Impact confirmation names the linked reservation — no immediate delete.
    expect(within(dialog).getByText("1 créneau réservé")).toBeInTheDocument();
    expect(deleteMut).not.toHaveBeenCalled();

    await user.click(within(dialog).getByRole("button", { name: "Supprimer" }));
    expect(deleteMut).toHaveBeenCalledWith("t1");
  });

  /** La ligne de l'équipe — le formulaire d'ajout porte les mêmes libellés. */
  const teamRow = (): HTMLElement => screen.getByRole("button", { name: "Supprimer" }).closest("div") as HTMLElement;

  it("locks Supprimer and the play level on a team already engaged in competition", () => {
    // Le serveur refuse les deux (ses matchs sont connus de la fédé) : l'écran ne
    // doit pas les proposer, sinon il promet un geste qui finit en 409.
    team = { ...baseTeam, isEngaged: true };
    renderWithProviders(<TeamsStep />);
    const row = teamRow();

    expect(within(row).getByRole("button", { name: "Supprimer" })).toBeDisabled();
    expect(within(row).getByRole("combobox", { name: "Niveau de jeu" })).toBeDisabled();
    // Ce qui reste libre le reste : le nom et les créneaux ne dépendent pas de la fédé.
    expect(within(row).getByRole("textbox", { name: "Nom" })).toBeEnabled();
    expect(within(row).getByRole("spinbutton", { name: "Séances/sem" })).toBeEnabled();
    // La raison est du TEXTE, pas un survol : un contrôle `disabled` sort de l'ordre de
    // tabulation et ne reçoit aucun événement souris — au clavier comme au lecteur
    // d'écran, un `title` laisserait deux contrôles grisés sans explication.
    // Deux niveaux, et il faut les DEUX : le pourquoi une fois pour la liste…
    expect(screen.getByText(/joue en compétition/)).toBeInTheDocument();
    // …et le marqueur sur LA ligne concernée, sinon on ne sait pas laquelle est verrouillée.
    expect(within(row.parentElement as HTMLElement).getByText(/Engagée en compétition/)).toBeInTheDocument();
  });

  it("leaves both open on a team that does not play yet", () => {
    renderWithProviders(<TeamsStep />);
    const row = teamRow();

    expect(within(row).getByRole("button", { name: "Supprimer" })).toBeEnabled();
    expect(within(row).getByRole("combobox", { name: "Niveau de jeu" })).toBeEnabled();
    expect(screen.queryByText(/joue en compétition/)).not.toBeInTheDocument();
    expect(screen.queryByText(/Engagée en compétition/)).not.toBeInTheDocument();
  });

  it("shows a play-level select and no redundant inner heading", () => {
    renderWithProviders(<TeamsStep />);
    // Play-level select exists on both the add form and the row.
    expect(screen.getAllByLabelText("Niveau de jeu").length).toBeGreaterThan(0);
    // Point 5: the sticky wizard header owns the title; no inner "Équipes" h2.
    expect(screen.queryByRole("heading", { name: "Équipes" })).toBeNull();
  });

  it("keeps a Rang select on the ADD form but NOT on team rows (rang changed via Trier)", () => {
    renderWithProviders(<TeamsStep />); // exactly one team (t1) is rendered
    // Only the add form still offers a rank picker; the row has none — a team's
    // tier is changed via the "Trier" drag & drop, not an inline dropdown.
    expect(screen.getAllByLabelText("Rang")).toHaveLength(1);
  });

  it("keeps the gender select (categories are ungendered now)", () => {
    renderWithProviders(<TeamsStep />);
    expect(screen.getAllByLabelText("Genre").length).toBeGreaterThan(0);
  });

  it("warns when a competitive team is ranked Bonus (D)", () => {
    renderWithProviders(<TeamsStep />);
    // team t1 = DEPARTEMENTAL (competitive) + tier 5 (D) → warning.
    expect(screen.getByText(/en compétition classée Bonus/i)).toBeInTheDocument();
  });

  it("no warning for a loisir team ranked Bonus", () => {
    team.level = "LOISIR_ADULTE";
    renderWithProviders(<TeamsStep />);
    expect(screen.queryByText(/en compétition classée Bonus/i)).toBeNull();
    team.level = "DEPARTEMENTAL"; // restore
  });

  it("shows a required-name error (and does not create) when adding with an empty name", async () => {
    const user = userEvent.setup();
    renderWithProviders(<TeamsStep />);
    await user.click(screen.getByRole("button", { name: "Ajouter l'équipe" }));
    expect(screen.getByText(/nom de l'équipe est obligatoire/i)).toBeInTheDocument();
    expect(createMut).not.toHaveBeenCalled();
    // Typing clears the error.
    await user.type(screen.getByLabelText("Nom de l'équipe"), "SF1");
    expect(screen.queryByText(/nom de l'équipe est obligatoire/i)).toBeNull();
  });

  it("sends the play level when changed on a row", async () => {
    const user = userEvent.setup();
    renderWithProviders(<TeamsStep />);
    const rowLevel = screen.getAllByLabelText("Niveau de jeu")[1]; // [0] = add form, [1] = row
    await user.selectOptions(rowLevel, "REGIONAL");
    expect(updateMut).toHaveBeenCalled();
    const body = updateMut.mock.calls[0][0].body;
    expect(body.level).toBe("REGIONAL");
  });

  // ── P4-36 (retour terrain 2026-07-31) ──

  // (a) L'en-tête ne vivait QUE dans la branche « au moins une équipe », alors que le
  // formulaire d'ajout est AU-DESSUS : un club neuf saisissait sa première équipe à
  // l'aveugle, avec des placeholders pour seuls repères.
  it("nomme les colonnes du formulaire même quand aucune équipe n'existe", () => {
    teamsState.data = [];
    renderWithProviders(<TeamsStep />);

    expect(screen.getByText("Aucune équipe pour le moment.")).toBeInTheDocument();
    expect(screen.getByText("Niveau de jeu")).toBeInTheDocument();
    expect(screen.getByText("Séances")).toBeInTheDocument();
  });

  // (b) Le rang n'était qu'un titre de section : invisible dès qu'on trie autrement.
  it("affiche le rang sur chaque ligne, et les flèches hors du mode Trier", () => {
    renderWithProviders(<TeamsStep />);

    // « D » est le label du rang de l'équipe fixture (priorityTierId 5).
    expect(screen.getByTitle("D · Bonus")).toHaveTextContent("D");
    expect(screen.getByRole("button", { name: /Monter SM3/ })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Descendre SM3/ })).toBeInTheDocument();
  });

  // Les flèches persistent l'ordre COMPLET, comme la sortie du mode « Trier » — un envoi
  // partiel laisserait des équipes sans `tierOrder` explicite.
  it("déplace une équipe dans son rang et persiste l'ordre entier", async () => {
    teamsState.data = [
      { ...baseTeam, id: "a", name: "Alpha", tierOrder: 0 },
      { ...baseTeam, id: "b", name: "Bravo", tierOrder: 1 },
    ];
    const user = userEvent.setup();
    renderWithProviders(<TeamsStep />);

    await user.click(screen.getByRole("button", { name: /Descendre Alpha/ }));
    expect(reorderMut).toHaveBeenCalledWith([
      { id: "b", priorityTierId: 5, tierOrder: 0 },
      { id: "a", priorityTierId: 5, tierOrder: 1 },
    ]);
  });

  it("désactive la flèche qui sortirait du rang", () => {
    teamsState.data = [
      { ...baseTeam, id: "a", name: "Alpha", tierOrder: 0 },
      { ...baseTeam, id: "b", name: "Bravo", tierOrder: 1 },
    ];
    renderWithProviders(<TeamsStep />);

    expect(screen.getByRole("button", { name: /Monter Alpha/ })).toBeDisabled();
    expect(screen.getByRole("button", { name: /Descendre Bravo/ })).toBeDisabled();
  });

  // (c) Trier par une autre colonne bascule en LISTE PLATE : appliquer le tri à l'intérieur
  // de chaque section donnerait cinq listes triées séparément, ce qui ne répond pas à
  // « je veux voir mes équipes par catégorie ».
  it("bascule en liste plate au clic d'une colonne, et revient aux sections sur Rang", async () => {
    teamsState.data = [
      { ...baseTeam, id: "a", name: "Alpha", priorityTierId: 1, sportCategoryId: "catU11" },
      { ...baseTeam, id: "b", name: "Bravo", priorityTierId: 5, sportCategoryId: "catVet" },
    ];
    const user = userEvent.setup();
    renderWithProviders(<TeamsStep />);

    // Par défaut : sections de rang, une par rang présent.
    expect(screen.getByRole("heading", { name: "S · Fanion" })).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: /Catégorie/ }));
    expect(screen.queryByRole("heading", { name: "S · Fanion" })).toBeNull();
    // Vétéran (sortOrder 0) passe devant U11 (6) : c'est l'ordre SERVI, pas l'alphabet.
    const names = screen.getAllByLabelText("Nom").map((input) => (input as HTMLInputElement).value);
    expect(names).toEqual(["Bravo", "Alpha"]);

    await user.click(screen.getByRole("button", { name: /Rang/ }));
    expect(screen.getByRole("heading", { name: "S · Fanion" })).toBeInTheDocument();
  });

  // Les flèches déplacent AU SEIN d'un rang : hors ordre par rang, ce geste n'a plus de
  // sens. Absentes plutôt qu'inertes — un bouton désactivé sans raison lisible est pire.
  it("retire les flèches quand la liste n'est plus triée par rang", async () => {
    const user = userEvent.setup();
    renderWithProviders(<TeamsStep />);

    await user.click(screen.getByRole("button", { name: /Catégorie/ }));
    expect(screen.queryByRole("button", { name: /Monter SM3/ })).toBeNull();
    // …mais le rang reste LISIBLE sur la ligne, sinon la bascule perdrait l'information.
    expect(screen.getByTitle("D · Bonus")).toHaveTextContent("D");
  });
});
