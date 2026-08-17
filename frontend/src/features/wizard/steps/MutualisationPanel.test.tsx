import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { HTTPError } from "ky";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { PriorityTier, SharedTrainingGroup, Team, TeamPeriodOverride } from "../api";

const stgCreate = vi.fn();
const stgUpdate = vi.fn();
const stgDelete = vi.fn();
const sharedGroupsState: { data: SharedTrainingGroup[] } = { data: [] };
const overridesState: { data: TeamPeriodOverride[] } = { data: [] };

// Le mock IGNORE `schedulePlanId` (le provider renvoie socle+périodes) : c'est le PANNEAU qui
// filtre le socle. Le rendre fidèle ferait passer sous silence ce tri côté client.
vi.mock("../queries", () => ({
  useSharedTrainingGroups: () => ({ data: sharedGroupsState.data }),
  useTeamPeriodOverrides: () => ({ data: overridesState.data }),
  useCreateSharedTrainingGroup: () => ({ mutateAsync: stgCreate, isPending: false }),
  useUpdateSharedTrainingGroup: () => ({ mutateAsync: stgUpdate, isPending: false }),
  useDeleteSharedTrainingGroup: () => ({ mutate: stgDelete }),
}));

import { MutualisationPanel } from "./MutualisationPanel";

const team = (over: Partial<Team> & Pick<Team, "id" | "name">): Team => ({
  sportCategoryId: "senior",
  priorityTierId: 1,
  tierOrder: 0,
  gender: "M",
  level: null,
  sessionsPerWeek: 3,
  isActive: true,
  ...over,
});

const TEAMS: Team[] = [
  team({ id: "t1", name: "SM1", priorityTierId: 1, tierOrder: 0, sessionsPerWeek: 3 }),
  team({ id: "t2", name: "SM2", priorityTierId: 1, tierOrder: 1, sessionsPerWeek: 2 }),
  team({ id: "t3", name: "SF1", priorityTierId: 2, tierOrder: 0, gender: "F", sessionsPerWeek: 2 }),
  team({ id: "t4", name: "U11", sportCategoryId: "u11", priorityTierId: 3, tierOrder: 0, sessionsPerWeek: 1 }),
];

const TIERS: PriorityTier[] = [
  { id: 1, label: "S", name: "Fanion", color: null },
  { id: 2, label: "A", name: "Importante", color: null },
  { id: 3, label: "B", name: "Moyenne", color: null },
];

const group = (id: string, teamIds: string[], commonSessions = 1, schedulePlanId: string | null = null): SharedTrainingGroup => ({
  id,
  version: 1,
  createdAt: "2026-08-17T00:00:00+00:00",
  updatedAt: "2026-08-17T00:00:00+00:00",
  schedulePlanId,
  teamIds,
  commonSessions,
});

const renderPanel = (schedulePlanId: string | null = null, pausedTeamIds?: Set<string>) =>
  renderWithProviders(<MutualisationPanel teams={TEAMS} tiers={TIERS} schedulePlanId={schedulePlanId} pausedTeamIds={pausedTeamIds} />);

beforeEach(() => {
  stgCreate.mockClear();
  stgUpdate.mockClear();
  stgDelete.mockClear();
  sharedGroupsState.data = [];
  overridesState.data = [];
});

describe("MutualisationPanel — le sélecteur d'équipes (cases + split proches/reste)", () => {
  it("liste toutes les équipes à plat tant que rien n'est coché (le split n'apparaît qu'à la première coche)", () => {
    renderPanel();
    // Pas de bloc « Équipes proches » avant la première coche.
    expect(screen.queryByRole("group", { name: "Équipes proches" })).toBeNull();
    for (const name of ["SM1", "SM2", "SF1", "U11"]) {
      expect(screen.getByRole("checkbox", { name })).toBeInTheDocument();
    }
  });

  it("dès qu'une équipe est cochée, remonte les proches (même catégorie + genre compatible) et replie le reste", async () => {
    const user = userEvent.setup();
    renderPanel();

    await user.click(screen.getByRole("checkbox", { name: "SM1" }));

    // Proches = SM1 (cochée) + SM2 (senior/M). SF1 (senior/F) et U11 (u11) sont dans le reste.
    const proches = screen.getByRole("group", { name: "Équipes proches" });
    expect(within(proches).getByRole("checkbox", { name: "SM1" })).toBeInTheDocument();
    expect(within(proches).getByRole("checkbox", { name: "SM2" })).toBeInTheDocument();
    // Le reste est replié : SF1 n'est pas rendue tant qu'on ne déplie pas.
    expect(screen.queryByRole("checkbox", { name: "SF1" })).toBeNull();

    // …mais toujours atteignable en un clic.
    await user.click(screen.getByRole("button", { name: "Afficher toutes les équipes" }));
    expect(screen.getByRole("checkbox", { name: "SF1" })).toBeInTheDocument();
    expect(screen.getByRole("checkbox", { name: "U11" })).toBeInTheDocument();
  });

  it("avertit sans bloquer au-delà de 3 équipes cochées", async () => {
    const user = userEvent.setup();
    renderPanel();
    for (const name of ["SM1", "SM2"]) {
      await user.click(screen.getByRole("checkbox", { name }));
    }
    await user.click(screen.getByRole("button", { name: "Afficher toutes les équipes" }));
    await user.click(screen.getByRole("checkbox", { name: "SF1" }));
    await user.click(screen.getByRole("checkbox", { name: "U11" }));

    expect(screen.getByRole("alert")).toHaveTextContent(/inhabituel/);
    // Jamais bloquant : le bouton reste actif (le serveur accepte jusqu'à 10).
    expect(screen.getByRole("button", { name: "Créer le groupe" })).toBeEnabled();
  });
});

describe("MutualisationPanel — pré-validation FAIL-SAFE (le serveur reste juge)", () => {
  it("plafonne K au plus petit sessionsPerWeek effectif de la sélection", async () => {
    const user = userEvent.setup();
    renderPanel();
    // SM1 = 3 séances, SM2 = 2 → plafond 2.
    await user.click(screen.getByRole("checkbox", { name: "SM1" }));
    await user.click(screen.getByRole("checkbox", { name: "SM2" }));

    const k = screen.getByRole("spinbutton", { name: "Séances communes" });
    await user.clear(k);
    await user.type(k, "5");
    // Ramené au plafond 2, jamais 5.
    expect(k).toHaveValue(2);
    expect(screen.getByText(/au plus 2 séances communes/)).toBeInTheDocument();
  });

  it("honore l'override de période dans le plafond (l'override est prioritaire)", async () => {
    const user = userEvent.setup();
    // En période, SM1 tombe à 1 séance via override → plafond 1 même si sa valeur de saison est 3.
    overridesState.data = [{ id: "o", schedulePlanId: "plan-1", teamId: "t1", isActive: true, sessionsPerWeek: 1 }];
    renderPanel("plan-1");

    await user.click(screen.getByRole("checkbox", { name: "SM1" }));
    await user.click(screen.getByRole("checkbox", { name: "SM2" }));
    expect(screen.getByText(/au plus 1 séance commune/)).toBeInTheDocument();
  });

  it("désactive une équipe déjà membre d'un autre groupe de la portée, avec la raison EN TEXTE", () => {
    sharedGroupsState.data = [group("g1", ["t3", "t4"])];
    renderPanel();

    const sf1 = screen.getByRole("checkbox", { name: "SF1" });
    expect(sf1).toBeDisabled();
    // La raison nomme la co-équipière, ce n'est pas un simple `title` de survol.
    expect(screen.getByText(/déjà mutualisée avec U11/)).toBeInTheDocument();
  });

  it("affiche le motif d'un 422 serveur (lu depuis error.data via apiErrorMessage)", async () => {
    const user = userEvent.setup();
    const err = new HTTPError(new Response(null, { status: 422 }), new Request("http://localhost/"), { method: "POST" } as never);
    (err as { data?: unknown }).data = { error: "Une équipe du groupe est inconnue de cette saison." };
    stgCreate.mockRejectedValueOnce(err);
    renderPanel();

    await user.click(screen.getByRole("checkbox", { name: "SM1" }));
    await user.click(screen.getByRole("checkbox", { name: "SM2" }));
    await user.click(screen.getByRole("button", { name: "Créer le groupe" }));

    expect(await screen.findByText("Une équipe du groupe est inconnue de cette saison.")).toBeInTheDocument();
  });
});

describe("MutualisationPanel — créer / modifier / supprimer", () => {
  it("crée un groupe : POST { schedulePlanId, teamIds, commonSessions }", async () => {
    const user = userEvent.setup();
    renderPanel(null);

    await user.click(screen.getByRole("checkbox", { name: "SM1" }));
    await user.click(screen.getByRole("checkbox", { name: "SM2" }));
    await user.click(screen.getByRole("button", { name: "Créer le groupe" }));

    expect(stgCreate).toHaveBeenCalledOnce();
    const arg = stgCreate.mock.calls[0][0] as { schedulePlanId: string | null; teamIds: string[]; commonSessions: number };
    expect(arg.schedulePlanId).toBeNull();
    expect([...arg.teamIds].sort()).toEqual(["t1", "t2"]);
    expect(arg.commonSessions).toBe(1);
  });

  it("le bouton reste inerte tant qu'il n'y a pas au moins deux équipes", async () => {
    const user = userEvent.setup();
    renderPanel();
    await user.click(screen.getByRole("checkbox", { name: "SM1" }));
    expect(screen.getByRole("button", { name: "Créer le groupe" })).toBeDisabled();
  });

  it("modifie un groupe existant : préremplissage puis PUT { teamIds, commonSessions } sur le même id", async () => {
    const user = userEvent.setup();
    sharedGroupsState.data = [group("g1", ["t1", "t2"], 2)];
    renderPanel();

    await user.click(screen.getByRole("button", { name: "Modifier" }));
    // Préremplissage : les deux membres sont cochés, K = 2 (et un membre du groupe édité n'est PAS verrouillé).
    expect(screen.getByRole("checkbox", { name: "SM1" })).toBeChecked();
    expect(screen.getByRole("checkbox", { name: "SM2" })).toBeChecked();
    expect(screen.getByRole("spinbutton", { name: "Séances communes" })).toHaveValue(2);

    await user.click(screen.getByRole("button", { name: "Enregistrer le groupe" }));
    expect(stgCreate).not.toHaveBeenCalled();
    const arg = stgUpdate.mock.calls[0][0] as { id: string; body: { teamIds: string[]; commonSessions: number } };
    expect(arg.id).toBe("g1");
    expect([...arg.body.teamIds].sort()).toEqual(["t1", "t2"]);
    expect(arg.body.commonSessions).toBe(2);
  });

  it("supprime sous une confirmation qui NOMME l'impact", async () => {
    const user = userEvent.setup();
    sharedGroupsState.data = [group("g1", ["t1", "t2"], 1)];
    renderPanel();

    await user.click(screen.getByRole("button", { name: "Supprimer" }));
    const dialog = screen.getByRole("dialog");
    // L'impact est nommé, pas un « OK ? » nu.
    expect(within(dialog).getByText(/SM1 \+ SM2 — 1 séance commune/)).toBeInTheDocument();
    expect(stgDelete).not.toHaveBeenCalled();

    await user.click(within(dialog).getByRole("button", { name: "Supprimer" }));
    expect(stgDelete).toHaveBeenCalledWith("g1");
  });
});

describe("MutualisationPanel — portée", () => {
  it("en portée socle, ne liste QUE les groupes du socle (le provider renvoie aussi les périodes)", () => {
    sharedGroupsState.data = [group("g-base", ["t1", "t2"], 1, null), group("g-period", ["t3", "t4"], 1, "plan-9")];
    renderPanel(null);

    expect(screen.getByText(/SM1 \+ SM2 — 1 séance commune/)).toBeInTheDocument();
    expect(screen.queryByText(/SF1 \+ U11/)).toBeNull();
  });

  it("n'offre pas une équipe en pause pour la période", () => {
    renderPanel("plan-1", new Set(["t4"]));
    expect(screen.queryByRole("checkbox", { name: "U11" })).toBeNull();
  });
});
