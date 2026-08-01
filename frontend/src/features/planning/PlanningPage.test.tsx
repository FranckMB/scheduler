import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { SchedulePlan } from "@/features/cockpit/api";
import { renderWithProviders } from "@/test/utils";

import { getDiagnostics, getSlots, getTrainingSlots, getVenues, listSchedules, OverlaysExistError, reopenSchedule } from "./api";
import type { Schedule } from "./api";
import { PlanningPage } from "./PlanningPage";
import { usePlanningStore } from "./store";

// The api layer is mocked (not HTTP): ky + jsdom + MSW disagree on AbortSignal,
// so we exercise the screen from the api boundary down — queries, PlanningPage
// logic, the grid + all its panels — with fixture data.
const SID = "sched-1";

vi.mock("./api", () => {
  // A real error class so PlanningPage's `error instanceof OverlaysExistError`
  // escalation branch fires from the mocked reopen/validate rejections.
  class OverlaysExistError extends Error {
    public count: number;
    public overlays: unknown[];

    constructor(count: number, overlays: unknown[]) {
      super("overlays");
      this.name = "OverlaysExistError";
      this.count = count;
      this.overlays = overlays;
    }
  }
  return {
  OverlaysExistError,
  reopenSchedule: vi.fn(),
  listSchedules: vi.fn(() => Promise.resolve([{ id: SID, name: "Planning A", status: "COMPLETED", score: 9051, createdAt: "2026-01-01T00:00:00Z", updatedAt: "2026-01-01T00:00:00Z", planType: "SEASON", schedulePlanId: "season-plan" }])),
  getSlots: vi.fn(() =>
    Promise.resolve([
      { id: "slot-1", scheduleId: SID, teamId: "team-1", venueId: "venue-1", coachId: null, dayOfWeek: 1, startTime: "18:00:00", durationMinutes: 90, lockLevel: "NONE", temporaryLock: false },
    ]),
  ),
  getDiagnostics: vi.fn(() =>
    Promise.resolve([
      { id: "diag-1", scheduleId: SID, type: "conflict", severity: "ERROR", teamId: null, venueId: "venue-1", coachId: null, message: "Conflit de gymnase.", suggestions: [] },
      // The solver's own "unused_slot" warning for the ts-2 empty window.
      { id: "diag-unused-slot-venue-1-2-19:00", scheduleId: SID, type: "unused_slot", severity: "WARNING", teamId: null, venueId: "venue-1", coachId: null, message: "Créneau disponible non utilisé : Gymnase Alpha (mardi de 19:00 à 20:30).", suggestions: [] },
    ]),
  ),
  getTeams: vi.fn(() => Promise.resolve([{ id: "team-1", name: "U11", sportCategoryId: "cat-1", priorityTierId: 1, tierOrder: 0 }])),
  getVenues: vi.fn(() => Promise.resolve([{ id: "venue-1", name: "Gymnase Alpha", color: "#00aa00" }])),
  // ts-1 matches slot-1 (filled) ; ts-2 is a defined-but-unfilled window → "vide".
  getTrainingSlots: vi.fn(() =>
    Promise.resolve([
      { id: "ts-1", venueId: "venue-1", dayOfWeek: 1, startTime: "18:00:00", durationMinutes: 90, capacity: 1 },
      { id: "ts-2", venueId: "venue-1", dayOfWeek: 2, startTime: "19:00:00", durationMinutes: 90, capacity: 1 },
    ]),
  ),
  getCoaches: vi.fn(() => Promise.resolve([{ id: "coach-1", firstName: "Jean", lastName: "Dupont" }])),
  getCategories: vi.fn(() => Promise.resolve([{ id: "cat-1", name: "U11" }])),
  getTeamCoaches: vi.fn(() => Promise.resolve([{ id: "tc-1", teamId: "team-1", coachId: "coach-1", role: "MAIN" }])),
  getCoachPlayers: vi.fn(() => Promise.resolve([])),
  lockSlot: vi.fn(),
  moveSlot: vi.fn(),
  generateSchedule: vi.fn(),
  validateSchedule: vi.fn(),
  STATUS_LABELS: { DRAFT: "Brouillon", PENDING: "En attente", GENERATING: "Génération…", COMPLETED: "Terminé", FAILED: "Échec" },
  };
});

const navigate = vi.fn();
vi.mock("react-router", async (orig) => ({ ...(await orig<typeof import("react-router")>()), useNavigate: () => navigate }));

const { meState, renameSpy, plansState, venueOverridesState } = vi.hoisted(() => ({
  meState: { chosenScheduleId: null as string | null },
  renameSpy: vi.fn(),
  // Typé sur le VRAI contrat : un type inline recopié laisserait passer un champ ajouté à
  // `SchedulePlan` sans que ces fixtures soient recalées (revue #339 round 3).
  plansState: { plans: [] as SchedulePlan[] },
  venueOverridesState: { rows: [] as { id: string; venueId: string; mode: string }[], isError: false },
}));

// P2-15 : les réglages de gymnases de la période — un gymnase DÉSACTIVÉ garde ses
// créneaux en base, l'écran doit malgré tout cesser de l'afficher.
vi.mock("@/features/wizard/queries", async (orig) => ({
  ...(await orig<typeof import("@/features/wizard/queries")>()),
  useVenuePeriodOverrides: () => ({ data: venueOverridesState.rows, isError: venueOverridesState.isError }),
}));

// Partiel : seul `useSchedulePlans` est simulé (l'en-tête y lit le nom du plan
// affiché) — le reste du module cockpit reste réel.
vi.mock("@/features/cockpit/queries", async (orig) => ({
  ...(await orig<typeof import("@/features/cockpit/queries")>()),
  useSchedulePlans: () => ({ data: plansState.plans }),
}));

vi.mock("@/features/auth/queries", () => ({
  useMe: () => ({
    data: {
      id: "u1", membershipStatus: "active", role: "admin", club: { id: "c", name: "C" },
      seasonPlan: { id: "plan-1", name: "Planning A", chosenScheduleId: meState.chosenScheduleId, hasFinishedVersion: true },
      seasons: [{ id: "sn1", name: "2025-2026", startDate: "2025-09-01", endDate: "2026-06-30", isCurrent: true, isReadonly: false }], currentSeasonId: "sn1",
    },
  }),
  useRenamePlanning: () => ({ mutate: renameSpy, isPending: false }),
  useWorkingSeason: () => ({ id: "sn1", name: "2025-2026", startDate: "2025-09-01", endDate: "2026-06-30", isCurrent: true, isReadonly: false }),
}));

const workVersion: Schedule[] = [{ id: SID, name: "Planning A", status: "COMPLETED", score: 9051, createdAt: "2026-01-01T00:00:00Z", updatedAt: "2026-01-01T00:00:00Z", planType: "SEASON", schedulePlanId: "season-plan" }];

beforeEach(() => {
  meState.chosenScheduleId = null;
  renameSpy.mockClear();
  plansState.plans = [];
  venueOverridesState.rows = [];
  venueOverridesState.isError = false;
  // Ré-armement explicite : ces trois-là sont réécrits par des cas (couche de période,
  // gymnase désactivé) et `mockResolvedValue` SURVIT au test suivant — une fuite qui
  // rendrait un échec ultérieur incompréhensible.
  vi.mocked(getSlots).mockResolvedValue([
    { id: "slot-1", scheduleId: SID, teamId: "team-1", venueId: "venue-1", coachId: null, dayOfWeek: 1, startTime: "18:00:00", durationMinutes: 90, lockLevel: "NONE", temporaryLock: false },
  ]);
  vi.mocked(getVenues).mockResolvedValue([{ id: "venue-1", name: "Gymnase Alpha", color: "#00aa00" }]);
  vi.mocked(getTrainingSlots).mockResolvedValue([
    { id: "ts-1", venueId: "venue-1", dayOfWeek: 1, startTime: "18:00:00", durationMinutes: 90, capacity: 1 },
    { id: "ts-2", venueId: "venue-1", dayOfWeek: 2, startTime: "19:00:00", durationMinutes: 90, capacity: 1 },
  ]);
  // Default: an editable work version. Re-armed per test so a case that swaps in
  // an in-force version (read-only → panels hidden) cannot leak into the next.
  vi.mocked(listSchedules).mockResolvedValue(workVersion);
  navigate.mockClear();
  usePlanningStore.setState({ viewMode: "gymnase", selectedScheduleId: null, selectedSlotId: null, resourceFilter: [] });
});

describe("PlanningPage (integration)", () => {
  // The "validate to unlock the cockpit" banner moved to the cockpit itself
  // (state 2) — PlanningPage no longer carries it (see CockpitPage.test).

  it("renders the base planning grid: team + coach on the slot, on an editable work version", async () => {
    renderWithProviders(<PlanningPage />);

    expect(await screen.findByText("U11")).toBeInTheDocument();
    expect(await screen.findByText("Jean Dupont")).toBeInTheDocument();
    // Standalone /planning (consultation) hides the toolbar's version selector and
    // status badge — see PlanningToolbar.test. ⚠ L'assertion sur le score a été retirée
    // ici : P4-39 l'ayant supprimé partout, elle ne distinguait plus rien (elle serait
    // restée verte même si le standalone se mettait à tout afficher). Le score est gardé
    // là où il vivait, dans PlanningToolbar.test.
    expect(screen.queryByRole("combobox", { name: /version du planning/i })).not.toBeInTheDocument();
    // « principal » qualifie LE planning de la saison — il s'affiche donc aussi sur
    // une version de travail, qui reste offerte à la validation.
    expect(screen.getByText("principal")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /valider/i })).toBeInTheDocument();
  });

  it("lit les créneaux du SOCLE quand la version affichée est celle de la saison", async () => {
    // Revue #8 round 4 — l'écran calculait ses cases vides sur la grille de SAISON quelle
    // que soit la version affichée, alors que l'export PDF remis aux coachs lit celle de
    // la version affichée. Deux vues du même planning qui ne coïncidaient pas, alors que
    // le code affirme qu'elles montrent la même chose.
    vi.mocked(getTrainingSlots).mockClear();
    renderWithProviders(<PlanningPage />);

    expect(await screen.findByText("U11")).toBeInTheDocument();
    expect(vi.mocked(getTrainingSlots)).toHaveBeenCalledWith(null);
  });

  it("lit la grille DE LA PÉRIODE quand la version affichée est un planning de période", async () => {
    vi.mocked(getTrainingSlots).mockClear();
    vi.mocked(listSchedules).mockResolvedValue([
      { id: SID, name: "Toussaint V1", status: "COMPLETED", score: 9051, createdAt: "2026-01-01T00:00:00Z", updatedAt: "2026-01-01T00:00:00Z", planType: "HOLIDAY", schedulePlanId: "toussaint-plan" },
    ]);
    // Le cockpit navigue vers un planning de période avec la version en sélection —
    // l'atterrissage par défaut, lui, vise le socle.
    usePlanningStore.setState({ selectedScheduleId: SID });
    renderWithProviders(<PlanningPage />);

    // La couche du socle peut être demandée le temps que la liste des versions arrive ;
    // ce qui compte est celle sur laquelle l'écran se STABILISE.
    await vi.waitFor(() => expect(vi.mocked(getTrainingSlots).mock.lastCall).toEqual(["toussaint-plan"]));
  });

  // P2-20 (retour fondateur 2026-07-31) — LE défaut : le stylo « Renommer » passait
  // `me.seasonPlan.id` EN DUR (PlanningPage.tsx), donc renommer un planning de période
  // renommait le planning de la SAISON. Le fondateur a renommé sa reprise du 17 août et
  // a retrouvé son planning de saison rebaptisé. ADR-0002 inv. 12 : le nom vit sur LE
  // plan — celui de la version affichée.
  it("renomme le plan de la PÉRIODE affichée, jamais celui de la saison", async () => {
    vi.mocked(listSchedules).mockResolvedValue([
      { id: SID, name: "Version de période", status: "COMPLETED", score: null, createdAt: "2026-01-01T00:00:00Z", updatedAt: "2026-01-01T00:00:00Z", planType: "HOLIDAY", schedulePlanId: "ete-plan" },
    ]);
    plansState.plans = [{ id: "ete-plan", type: "HOLIDAY", name: "Reprise d'été S1", startDate: "2026-08-17", calendarEntryId: "e-ete", chosenScheduleId: null, teamSelectionInitialized: true }];
    usePlanningStore.setState({ selectedScheduleId: SID });
    renderWithProviders(<PlanningPage />);

    // Le titre montre le nom du PLAN de période — il affichait « Planning A » (la saison).
    expect(await screen.findByRole("heading", { name: "Reprise d'été S1" })).toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: /renommer le planning/i }));
    const field = screen.getByRole("textbox", { name: /nom du planning/i });
    // Le champ se pré-remplissait du nom de la SAISON : on prouve qu'il porte celui du plan affiché.
    expect(field).toHaveValue("Reprise d'été S1");
    await userEvent.clear(field);
    await userEvent.type(field, "Reprise d'été S2{Enter}");

    expect(renameSpy).toHaveBeenCalledWith({ planId: "ete-plan", name: "Reprise d'été S2" });
  });

  it("renomme bien le plan de SAISON quand c'est lui qui est affiché", async () => {
    renderWithProviders(<PlanningPage />);

    expect(await screen.findByRole("heading", { name: "Planning A" })).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: /renommer le planning/i }));
    await userEvent.clear(screen.getByRole("textbox", { name: /nom du planning/i }));
    await userEvent.type(screen.getByRole("textbox", { name: /nom du planning/i }), "Saison 26-27{Enter}");

    expect(renameSpy).toHaveBeenCalledWith({ planId: "plan-1", name: "Saison 26-27" });
  });

  // Revue #339 : un club qui n'a JAMAIS généré n'a aucune version, donc aucun plan
  // « affiché » — l'en-tête perdait le nom du planning de saison ET son stylo, si bien que
  // le gestionnaire ne pouvait plus le nommer avant d'avoir généré. Le contexte par défaut
  // EST la saison. PREUVE DE CHUTE : sans le repli, le titre vaut « Planning ».
  it("garde le nom et le stylo du plan de saison quand le club n'a aucune version", async () => {
    vi.mocked(listSchedules).mockResolvedValue([]);
    renderWithProviders(<PlanningPage />);

    expect(await screen.findByRole("heading", { name: "Planning A" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /renommer le planning/i })).toBeInTheDocument();
  });

  // Un nom vidé n'est pas un renommage : on n'écrase pas une identité par du vide (la
  // colonne est NOT NULL). Le champ se referme, le titre reste — c'est le retour.
  it("n'écrit rien quand on valide un nom vidé", async () => {
    renderWithProviders(<PlanningPage />);

    expect(await screen.findByRole("heading", { name: "Planning A" })).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: /renommer le planning/i }));
    await userEvent.clear(screen.getByRole("textbox", { name: /nom du planning/i }));
    await userEvent.type(screen.getByRole("textbox", { name: /nom du planning/i }), "{Enter}");

    expect(renameSpy).not.toHaveBeenCalled();
    expect(screen.getByRole("heading", { name: "Planning A" })).toBeInTheDocument();
  });

  // Le plan d'une période pas encore chargé (collection en vol) : on ne propose pas un
  // geste dont on n'a pas la cible — c'est cette absence de cible qui faisait retomber
  // l'écriture sur le plan de saison.
  it("masque le stylo tant que le plan de la période affichée n'est pas résolu", async () => {
    vi.mocked(listSchedules).mockResolvedValue([
      { id: SID, name: "Version de période", status: "COMPLETED", score: null, createdAt: "2026-01-01T00:00:00Z", updatedAt: "2026-01-01T00:00:00Z", planType: "HOLIDAY", schedulePlanId: "ete-plan" },
    ]);
    usePlanningStore.setState({ selectedScheduleId: SID });
    renderWithProviders(<PlanningPage />);

    expect(await screen.findByText("U11")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /renommer le planning/i })).not.toBeInTheDocument();
  });

  // P2-15 (retour fondateur) : « à la génération je vois TOUS les gymnases alors que je ne
  // travaille qu'avec un seul, ça fait du bruit pour rien ». Un gymnase désactivé pour la
  // période garde ses créneaux en base — le backend les écarte du payload, il ne les
  // supprime pas — donc l'écran doit filtrer à la SOURCE.
  // P2-15 round 2 — on filtre ce qui est OFFERT, jamais ce qui EXISTE. Une séance déjà
  // placée dans un gymnase depuis désactivé reste à l'écran ET dans l'export (rendu côté
  // serveur, il ne connaît pas ce filtre) : la cacher faisait diverger le PDF remis aux
  // coachs de ce que le gestionnaire voyait, et rendait une grille blanche sans un mot
  // quand toute la version y était placée. On l'annonce, on n'escamote pas.
  it("garde les séances déjà placées dans un gymnase désactivé, et le dit", async () => {
    vi.mocked(listSchedules).mockResolvedValue([
      { id: SID, name: "Période", status: "COMPLETED", score: null, createdAt: "2026-01-01T00:00:00Z", updatedAt: "2026-01-01T00:00:00Z", planType: "HOLIDAY", schedulePlanId: "ete-plan" },
    ]);
    plansState.plans = [{ id: "ete-plan", type: "HOLIDAY", name: "Été", startDate: "2026-08-17", calendarEntryId: "e", chosenScheduleId: null, teamSelectionInitialized: true }];
    // La séance par défaut (slot-1) est placée dans venue-1, que l'on désactive ensuite.
    venueOverridesState.rows = [{ id: "o1", venueId: "venue-1", mode: "DISABLED" }];
    usePlanningStore.setState({ selectedScheduleId: SID, viewMode: "gymnase" });
    renderWithProviders(<PlanningPage />);

    expect(await screen.findByText(/1 séance\(s\) de ce planning sont placées dans un gymnase désactivé/)).toBeInTheDocument();
    // La séance est TOUJOURS là : l'export la contient, l'écran ne doit pas la nier.
    expect(screen.getByText("Gymnase Alpha")).toBeInTheDocument();
  });

  it("n'affiche pas un gymnase désactivé pour la période", async () => {
    vi.mocked(listSchedules).mockResolvedValue([
      { id: SID, name: "Période", status: "COMPLETED", score: null, createdAt: "2026-01-01T00:00:00Z", updatedAt: "2026-01-01T00:00:00Z", planType: "HOLIDAY", schedulePlanId: "ete-plan" },
    ]);
    plansState.plans = [{ id: "ete-plan", type: "HOLIDAY", name: "Été", startDate: "2026-08-17", calendarEntryId: "e", chosenScheduleId: null, teamSelectionInitialized: true }];
    // DEUX gymnases : sans un gymnase qui RESTE, la grille est vide et l'assertion
    // négative passerait pour la mauvaise raison (rien ne s'affiche).
    vi.mocked(getVenues).mockResolvedValue([
      { id: "venue-1", name: "Gymnase Alpha", color: "#00aa00" },
      { id: "venue-2", name: "Gymnase Beta", color: "#0000aa" },
    ]);
    vi.mocked(getTrainingSlots).mockResolvedValue([
      { id: "ts-1", venueId: "venue-1", dayOfWeek: 1, startTime: "18:00:00", durationMinutes: 90, capacity: 1 },
      { id: "ts-3", venueId: "venue-2", dayOfWeek: 3, startTime: "17:00:00", durationMinutes: 90, capacity: 1 },
    ]);
    // La séance placée est déplacée sur le gymnase qui RESTE : ce test épingle le sort des
    // FENÊTRES LIBRES d'un gymnase désactivé (le bruit du retour terrain), pas celui des
    // séances déjà placées — c'est le test au-dessus qui les garde.
    vi.mocked(getSlots).mockResolvedValue([
      { id: "slot-1", scheduleId: SID, teamId: "team-1", venueId: "venue-2", coachId: null, dayOfWeek: 3, startTime: "17:00:00", durationMinutes: 90, lockLevel: "NONE", temporaryLock: false },
    ]);
    venueOverridesState.rows = [{ id: "o1", venueId: "venue-1", mode: "DISABLED" }];
    usePlanningStore.setState({ selectedScheduleId: SID, viewMode: "gymnase" });
    renderWithProviders(<PlanningPage />);

    // ⚠ On ATTEND d'abord que l'écran soit chargé (une assertion négative passe sinon dès
    // le premier tick, sur le spinner — ce test était un FAUX VERT, il restait vert avec le
    // filtre supprimé : constaté en revue #342). On s'ancre sur un contenu qui ne dépend
    // PAS du gymnase, puis on affirme l'absence.
    // Le gymnase ACTIF s'affiche — c'est lui qui prouve que l'écran a bien chargé (une
    // assertion négative seule passerait dès le premier tick, sur le spinner : ce test
    // était un FAUX VERT, vert même avec le filtre supprimé — constaté en revue #342).
    expect(await screen.findByText("Gymnase Beta")).toBeInTheDocument();
    expect(screen.queryByText("Gymnase Alpha")).not.toBeInTheDocument();
  });

  it("drops « Valider » on the version the plan points at (it is in force) and offers « Rouvrir »", async () => {
    vi.mocked(listSchedules).mockResolvedValue([{ id: SID, name: "Planning A", status: "COMPLETED", score: 9051, createdAt: "2026-01-01T00:00:00Z", updatedAt: "2026-01-01T00:00:00Z", planType: "SEASON", schedulePlanId: "season-plan", isChosen: true }]);
    renderWithProviders(<PlanningPage />);

    expect(await screen.findByText("U11")).toBeInTheDocument();
    // Being pointed at IS being validated: the only way forward is « Rouvrir ».
    expect(screen.queryByRole("button", { name: /valider/i })).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: /rouvrir/i })).toBeInTheDocument();
  });

  it("switches to the coach view (coach resolved from the team)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<PlanningPage />);
    await screen.findByText("U11");

    await user.click(screen.getByRole("button", { name: "Par coach" }));

    // The coach becomes a column header; the slot's secondary line is now the venue.
    expect(await screen.findAllByText("Jean Dupont")).not.toHaveLength(0);
    expect(await screen.findByText("Gymnase Alpha")).toBeInTheDocument();
  });

  it("opens the slot detail on click", async () => {
    const user = userEvent.setup();
    renderWithProviders(<PlanningPage />);
    await user.click(await screen.findByText("U11"));

    expect(await screen.findByText("Catégorie")).toBeInTheDocument();
    expect(screen.getByText("90 min")).toBeInTheDocument();
  });

  it("groups diagnostics by severity", async () => {
    const user = userEvent.setup();
    renderWithProviders(<PlanningPage />);
    // Diagnostics collapsed by default → open the panel first (user request).
    await user.click(await screen.findByRole("button", { name: /Diagnostics du solveur/ }));
    const group = await screen.findByRole("button", { name: /Erreurs/ });
    expect(within(group).getByText("1")).toBeInTheDocument();
  });

  it("ouvre les diagnostics au sortir du WIZARD, et les laisse repliés en boucle de travail (P4-40)", async () => {
    // Retour terrain : « sinon on risque de ne pas le voir si on n'est pas familier avec
    // l'écran génération ». La règle est CONTEXTUELLE — elle ne contredit pas la demande
    // d'origine (replié par défaut), elle nomme un contexte qu'elle n'avait pas distingué.
    // ⚠ On teste le CÂBLAGE : `DiagnosticsPanel` a ses propres tests, mais rien ne
    // garantissait que `PlanningPage` lui passe bien le contexte.
    renderWithProviders(<PlanningPage embedded />);

    // Panneau déplié : son titre est un en-tête, pas le bouton de la barre repliée.
    expect(await screen.findByRole("heading", { name: "Diagnostics du solveur" })).toBeInTheDocument();

    // ⚠ ET le groupe le plus sévère est DÉPLIÉ. Sans cette seconde assertion, le test
    // restait vert en supprimant `openMostSevere` : le titre du panneau est rendu quoi
    // qu'il arrive, donc il ne prouvait que le repli de l'aside, pas le câblage qu'il
    // prétendait garder (revue #350). C'est le message d'un diagnostic qui le prouve.
    expect(await screen.findByText("Conflit de gymnase.")).toBeInTheDocument();
  });

  it("laisse l'aside REPLIÉ en embedded quand la génération est PROPRE", async () => {
    // Ouvrir l'aside sans rien à montrer volait 20rem de largeur à la grille, dans une
    // hauteur embarquée déjà courte, pour afficher « le planning est propre » (revue #350).
    // ⚠ L'amorce est indexée sur la VERSION : elle referme aussi bien qu'elle ouvre.
    vi.mocked(getDiagnostics).mockResolvedValueOnce([]);
    renderWithProviders(<PlanningPage embedded />);

    // La grille est bien là — donc la page a fini de charger…
    expect(await screen.findByText("U11")).toBeInTheDocument();
    // …et l'aside est resté la barre compacte, pas le panneau.
    expect(screen.queryByRole("heading", { name: "Diagnostics du solveur" })).not.toBeInTheDocument();
  });

  it("laisse les diagnostics REPLIÉS en boucle de travail", async () => {
    renderWithProviders(<PlanningPage />);

    // Barre compacte : un bouton qui rouvre, pas le panneau lui-même.
    expect(await screen.findByRole("button", { name: /Diagnostics du solveur/ })).toBeInTheDocument();
    expect(screen.queryByRole("heading", { name: "Diagnostics du solveur" })).not.toBeInTheDocument();
  });

  it("renders defined-but-unfilled windows as 'vide' cells alongside the solver's unused_slot warning", async () => {
    const user = userEvent.setup();
    renderWithProviders(<PlanningPage />);
    // ts-2 (Gymnase Alpha, Mardi 19:00) has no placement → a `vide` cell in the grid.
    expect(await screen.findByText("vide")).toBeInTheDocument();
    // The solver's own unused_slot warning is listed under "Alertes" (panel opened).
    await user.click(await screen.findByRole("button", { name: /Diagnostics du solveur/ }));
    const warnGroup = await screen.findByRole("button", { name: /Alertes/ });
    expect(within(warnGroup).getByText("1")).toBeInTheDocument();
  });

  // planning lifecycle (§7.1): reopening the version the plan POINTS at, when the
  // season has period overlays, 409s; the UI escalates to a proportional confirm,
  // then re-sends with the flag.
  describe("reopen escalation (the plan in force, with overlays)", () => {
    const validated: Schedule[] = [{ id: SID, name: "Planning A", status: "COMPLETED", score: 9051, createdAt: "2026-01-01T00:00:00Z", updatedAt: "2026-01-01T00:00:00Z", planType: "SEASON", schedulePlanId: "season-plan", isChosen: true }];

    beforeEach(() => {
      vi.mocked(reopenSchedule).mockReset(); // per-test call count + queued once-values
    });

    it("409 → confirm naming the overlay count → re-sends confirmDeleteOverlays", async () => {
      const user = userEvent.setup();
      vi.mocked(listSchedules).mockResolvedValue(validated);
      vi.mocked(reopenSchedule).mockRejectedValueOnce(new OverlaysExistError(2, [])).mockResolvedValueOnce({});
      renderWithProviders(<PlanningPage />);
      await screen.findByText("U11");

      await user.click(screen.getByRole("button", { name: /rouvrir/i }));
      // First reopen (no flag) → 409 → proportional confirm dialog.
      expect(await screen.findByText(/supprimera 2 plannings secondaires/i)).toBeInTheDocument();

      // P2-7 : le geste lourd exige de taper la phrase — le bouton reste grisé sans elle.
      const confirmButton = screen.getByRole("button", { name: "Rouvrir et supprimer" });
      expect(confirmButton).toBeDisabled();
      await user.type(within(screen.getByRole("dialog")).getByRole("textbox"), "modifier mon planning de saison");
      expect(confirmButton).toBeEnabled();

      await user.click(confirmButton);
      expect(vi.mocked(reopenSchedule)).toHaveBeenCalledTimes(2);
      expect(vi.mocked(reopenSchedule).mock.calls[1]).toEqual([SID, { confirmDeleteOverlays: true }]);
      // Reopened → back to the wizard's generation step.
      expect(navigate).toHaveBeenCalledWith("/wizard");
    });

    it("« Rouvrir » (no overlays) → wizard generation step", async () => {
      const user = userEvent.setup();
      vi.mocked(listSchedules).mockResolvedValue(validated);
      vi.mocked(reopenSchedule).mockResolvedValueOnce({});
      renderWithProviders(<PlanningPage />);
      await screen.findByText("U11");

      await user.click(screen.getByRole("button", { name: /rouvrir/i }));
      expect(vi.mocked(reopenSchedule)).toHaveBeenCalledTimes(1);
      expect(navigate).toHaveBeenCalledWith("/wizard");
    });
  });

  it("« Valider » → lands on the planning view", async () => {
    const user = userEvent.setup();
    renderWithProviders(<PlanningPage />);
    await screen.findByText("U11");

    // Toolbar "Valider" opens the confirm dialog; confirm inside it.
    await user.click(screen.getByRole("button", { name: /valider/i }));
    await user.click(within(screen.getByRole("dialog")).getByRole("button", { name: "Valider" }));
    expect(navigate).toHaveBeenCalledWith("/planning");
  });
});
