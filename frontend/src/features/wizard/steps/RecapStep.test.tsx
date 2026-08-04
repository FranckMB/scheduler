import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

const h = { reservations: [] as Array<Record<string, unknown>> };

type TeamRow = { id: string; name: string; sportCategoryId: string; priorityTierId: number; tierOrder: number; gender: null; level: null; sessionsPerWeek: number; isActive: boolean };
const team = (id: string, name: string, tier: number): TeamRow => ({ id, name, sportCategoryId: "c", priorityTierId: tier, tierOrder: 0, gender: null, level: null, sessionsPerWeek: 2, isActive: true });

// P2-15 : la COUCHE que le récap décrit — période (équipes/gymnases actifs) ou socle.
const { recapLayer, anchorState, storeState } = vi.hoisted(() => ({
  anchorState: { value: { state: "period", planId: "plan-1" } as { state: string; planId: string | null } },
  storeState: { value: { mode: "season", calendarEntryId: null } as { mode: string; calendarEntryId: string | null } },
  recapLayer: {
    teams: [] as unknown[],
    pausedIds: [] as string[],
    venues: [] as unknown[],
    slots: [] as unknown[],
    teamsRead: "ready" as "loading" | "failed" | "ready",
    venuesRead: "ready" as "loading" | "failed" | "ready",
  },
}));

// Le plan de la période : ancre des réservations depuis le lot C3 (inv. 5).
vi.mock("@/features/cockpit/queries", () => ({
  useSchedulePlanForEntry: () => ({ data: { id: "plan-1" }, isLoading: false }),
  usePeriodAnchor: () => anchorState.value,
  anchorIsWritable: (a: { state: string }) => "period" === a.state || "base" === a.state,
}));
vi.mock("../queries", () => ({
  useWizardTeams: () => ({
    data: [
      { id: "t1", name: "SM1", sportCategoryId: "c", priorityTierId: 3, tierOrder: 0, gender: null, level: null, sessionsPerWeek: 2, isActive: true },
      { id: "t2", name: "Fanion", sportCategoryId: "c", priorityTierId: 1, tierOrder: 0, gender: null, level: null, sessionsPerWeek: 2, isActive: true },
    ],
  }),
  useWizardVenues: () => ({ data: [{ id: "v1", name: "Gymnase A", color: null, isActive: true }] }),
  // P2-15 : le récap compte la COUCHE courante (période ou socle).
  useActiveVenues: () => ({ venues: recapLayer.venues, disabledIds: new Set<string>(), layerRead: recapLayer.venuesRead }),
  useActiveTeams: () => ({ teams: recapLayer.teams, pausedIds: new Set(recapLayer.pausedIds), layerRead: recapLayer.teamsRead }),
  useGridSlots: () => ({ data: recapLayer.slots }),
  useVenueSlots: () => ({ data: [] }),
  useWizardCoaches: () => ({ data: [] }),
  useWizardCoachPlayers: () => ({ data: [] }),
  useWizardTeamCoaches: () => ({ data: [] }),
  useWizardConstraints: () => ({ data: [] }),
  useWizardTeamTags: () => ({ data: [] }),
  useReservations: () => ({ data: h.reservations }),
  usePriorityTiers: () => ({
    data: [
      { id: 1, label: "S", name: "Fanion", color: null },
      { id: 3, label: "B", name: "Moyenne", color: null },
    ],
  }),
}));
vi.mock("../lib/useStepValidation", () => ({ useStepValidation: () => ({ errors: [] }) }));
vi.mock("../store", () => ({ useWizardStore: (sel: (s: { mode: string; calendarEntryId: string | null }) => unknown) => sel(storeState.value) }));

import { RecapStep } from "./RecapStep";

describe("RecapStep — read-only summary", () => {
  beforeEach(() => {
    h.reservations = [];
    // Défaut : la couche décrit les mêmes équipes que la liste de saison, aucune en pause.
    recapLayer.teams = [team("t1", "SM1", 3), team("t2", "Fanion", 1)];
    recapLayer.pausedIds = [];
    recapLayer.venues = [{ id: "v1", name: "Gymnase A", color: null, isActive: true }];
    recapLayer.slots = [];
    recapLayer.teamsRead = "ready";
    recapLayer.venuesRead = "ready";
    anchorState.value = { state: "period", planId: "plan-1" };
    storeState.value = { mode: "season", calendarEntryId: null };
  });

  // P2-15 (retour fondateur) — LE symptôme : « je sélectionne 6 équipes sur l'overlay, il
  // me dit 49 équipes ». Le compteur décrit ce qui sera GÉNÉRÉ, donc la couche courante.
  it("compte les équipes de la PÉRIODE, pas celles du club", async () => {
    recapLayer.teams = [team("t1", "SM1", 3)];
    recapLayer.pausedIds = ["t2"];
    renderWithProviders(<RecapStep />);

    // La carte compteur du haut : sa valeur est le nombre qui sera GÉNÉRÉ.
    const card = (await screen.findAllByText("Équipes"))[0];
    expect(card.previousElementSibling).toHaveTextContent("1");
  });

  // … et une équipe en pause reste VISIBLE, barrée : le récap sert à vérifier ce qu'on va
  // générer, y compris ce qu'on a délibérément mis de côté (décision fondateur).
  it("montre une équipe en pause, barrée, sans la compter", async () => {
    recapLayer.teams = [team("t1", "SM1", 3)];
    recapLayer.pausedIds = ["t2"];
    renderWithProviders(<RecapStep />);

    const card = (await screen.findAllByText("Équipes"))[0];
    expect(card.previousElementSibling).toHaveTextContent("1");
    // Le détail est dans un accordéon FERMÉ par défaut : on l'ouvre pour lire la liste.
    await userEvent.click(screen.getAllByRole("button", { name: /Équipes/ })[0]);
    // L'équipe en pause y reste LISTÉE, barrée — on doit voir ce qu'on a mis de côté.
    expect(screen.getByText("Fanion")).toHaveClass("line-through");
    expect(screen.getByText(/en pause pour cette période/)).toBeInTheDocument();
  });

  // Mode période dont l'ancre n'est pas résolue : les listes lues sont celles de la
  // SAISON. Les présenter comme celles de la période serait le mensonge que ce lot
  // corrige — on l'annonce (revue #342, trouvé en appliquant la règle à TOUS ses sites).
  it("annonce que les chiffres sont ceux de la saison tant que l'ancre n'est pas résolue", async () => {
    storeState.value = { mode: "period", calendarEntryId: "entry-1" };
    anchorState.value = { state: "loading", planId: null };
    renderWithProviders(<RecapStep />);

    expect(await screen.findByText(/ne sont pas encore chargés/)).toBeInTheDocument();
  });

  // FAIL-CLOSED (P4-20/P4-1) : sur une lecture ratée on ne masque RIEN, et on le DIT.
  // Masquer en silence ferait croire à une période plus petite qu'elle n'est.
  it("ne masque rien et l'annonce quand les réglages de la période sont illisibles", async () => {
    recapLayer.teamsRead = "failed";
    renderWithProviders(<RecapStep />);

    expect(await screen.findByText(/n'a pas pu être lue/)).toBeInTheDocument();
  });

  // CHARGER ≠ ÉCHOUER (revue #342 round 2). Le premier jet repliait `loading` sur `failed` :
  // le récap d'une période affichait « n'a pas pu être lue » à CHAQUE ouverture, sur une
  // requête simplement en vol. Un bandeau d'alerte qui se déclenche en régime normal
  // apprend au gestionnaire à l'ignorer — précisément le jour où la lecture échoue.
  it("dit « en cours de lecture » — pas « échec » — pendant le premier chargement", async () => {
    recapLayer.teamsRead = "loading";
    renderWithProviders(<RecapStep />);

    expect(await screen.findByText(/est en cours de lecture/)).toBeInTheDocument();
    expect(screen.queryByText(/n'a pas pu être lue/)).not.toBeInTheDocument();
  });

  it("lists reservations by team rank (fanion before B) with NO delete button (read-only)", async () => {
    // Server order puts the rank-B team first; the accordion must show rank-S first.
    h.reservations = [
      { id: "rB", calendarEntryId: null, teamId: "t1", venueId: "v1", dayOfWeek: 2, startTime: "20:30", durationMinutes: 120 },
      { id: "rS", calendarEntryId: null, teamId: "t2", venueId: "v1", dayOfWeek: 3, startTime: "18:00", durationMinutes: 90 },
    ];
    const user = userEvent.setup();
    renderWithProviders(<RecapStep />);

    await user.click(screen.getByRole("button", { name: /Réservations/ }));

    // Rank order: the Fanion (S) row precedes the SM1 (B) row in the DOM.
    const rows = screen.getAllByText(/^(Fanion|SM1)$/).map((el) => el.textContent);
    expect(rows.indexOf("Fanion")).toBeLessThan(rows.indexOf("SM1"));

    // Read-only strict: the recap exposes no destructive action.
    expect(screen.queryByRole("button", { name: /Retirer la réservation/ })).not.toBeInTheDocument();
  });

  it("shows the team tiers open by default (ranks visible at first glance)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<RecapStep />);

    // Open the outer "Équipes" accordion; the tier groups inside must be OPEN
    // (their team rows visible) with their rank labels shown, S before B.
    const equipesHeaders = screen.getAllByRole("button", { name: /Équipes/ });
    await user.click(equipesHeaders[0]);

    const sHeader = screen.getByRole("button", { name: /S · Fanion/ });
    expect(sHeader).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /B · Moyenne/ })).toBeInTheDocument();
    // defaultOpen: the S tier's team row is already visible without a click.
    expect(within(sHeader.parentElement as HTMLElement).getByText("Fanion")).toBeInTheDocument();
  });
});

/**
 * Décision P3-8 (2026-08-04) — le récap avertit sur les créneaux PARTAGÉS sans bloquer.
 * La règle vit en fonction pure (`sharedSlotStatuses`, testée à part) ; ici on garde le
 * CÂBLAGE : l'écran rend bien les deux messages, nommés par gymnase · jour · heure.
 */
describe("RecapStep — créneaux partagés", () => {
  const sharedSlot = { id: "sl1", venueId: "v1", dayOfWeek: 6, startTime: "14:00", durationMinutes: 90, capacity: 2 };

  beforeEach(() => {
    h.reservations = [];
    recapLayer.teams = [team("t1", "SM1", 3), team("t2", "Fanion", 1)];
    recapLayer.pausedIds = [];
    // canSplit ABSENT du mock d'origine : on le pose, c'est lui qui arme la capacité 2.
    recapLayer.venues = [{ id: "v1", name: "Gymnase A", color: null, isActive: true, canSplit: true }];
    recapLayer.slots = [sharedSlot];
    recapLayer.teamsRead = "ready";
    recapLayer.venuesRead = "ready";
    anchorState.value = { state: "period", planId: "plan-1" };
    storeState.value = { mode: "season", calendarEntryId: null };
  });

  it("annonce qu'un créneau partagé SANS réservation sera composé par le système", () => {
    renderWithProviders(<RecapStep />);
    expect(screen.getByText(/le système associera les équipes lui-même/)).toBeInTheDocument();
    expect(screen.getByText(/Gymnase A · Sam 14:00/)).toBeInTheDocument();
  });

  it("avertit qu'un créneau partagé PARTIELLEMENT réservé gardera sa place vide (ALIGN-07)", () => {
    h.reservations = [{ id: "r1", teamId: "t1", venueId: "v1", dayOfWeek: 6, startTime: "14:00" }];
    renderWithProviders(<RecapStep />);
    expect(screen.getByText(/le système ne complétera pas ce créneau/)).toBeInTheDocument();
  });

  it("se tait quand le créneau partagé est plein", () => {
    h.reservations = [
      { id: "r1", teamId: "t1", venueId: "v1", dayOfWeek: 6, startTime: "14:00" },
      { id: "r2", teamId: "t2", venueId: "v1", dayOfWeek: 6, startTime: "14:00" },
    ];
    renderWithProviders(<RecapStep />);
    expect(screen.queryByText(/créneau partagé|ne complétera pas/)).not.toBeInTheDocument();
  });
});
