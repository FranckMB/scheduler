import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import type { CalendarEntry, SchedulePlan, SchoolHoliday } from "./api";
import { setTodayOverride } from "@/shared/lib/clock";

import { addDays, frDateShort, mondayOf, todayISO } from "./lib/date";
import { RadarPanel } from "./RadarPanel";

const createHolidayMutate = vi.fn();
const createHolidayMutateAsync = vi.fn();
const createWeekChildrenMutate = vi.fn();
// #5 gating : socle (plan de saison) validé par défaut — les tests d'ajustement
// existants cliquent « Adapter ». Un test dédié le passe à null.
let meData: { seasonPlan: { chosenScheduleId: string | null } } = { seasonPlan: { chosenScheduleId: "s-season" } };
let conflictsData: { conflicts: { dates: string[] }[]; seasonPlanChosen?: boolean } | undefined;
let conflictsPending = false;
// ADR-0002 lot D-b : le radar dérive « version active » du plan de la période
// (chosenScheduleId), plus d'un pointeur sur l'entrée.
// undefined = plans pas encore résolus (aucune donnée). Le fail-closed du radar clé sur la
// PRÉSENCE de donnée, pas sur le statut (une donnée périmée après un refetch en échec reste
// affichable).
let plansData: SchedulePlan[] | undefined = [];
const plansState = { failed: false };
// Versions existantes par plan (retour fondateur 2026-07-18 : « planning en cours »
// = plan avec versions mais sans version validée → carte toujours visible).
let schedulesData: { schedulePlanId: string }[] | undefined = [];
// #10 C2 — les campagnes de doléances, indexées par période. Mockées ici parce qu'une
// vacance qui en porte une ÉCHAPPE à l'horizon 60 j (revue #344 : cette carte est la seule
// surface qui rende le badge « x à traiter »).
let campaignsData: unknown[] | undefined = [];

vi.mock("./queries", () => ({
  useCreateHolidayPeriod: () => ({ mutate: createHolidayMutate, mutateAsync: createHolidayMutateAsync, isPending: false }),
  useCreateWeekChildren: () => ({ mutate: createWeekChildrenMutate, isPending: false }),
  useCreatePeriodPlan: () => ({ mutateAsync: vi.fn().mockResolvedValue({}), isPending: false }),
  useEntryConflicts: () => ({ data: conflictsData }),
  // Le parent lit l'impact de TOUTES les fermetures pour masquer celles qui ne
  // demandent rien — même donnée que la carte enfant (le cache dédoublonne).
  useEntryConflictsList: (ids: string[]) => ids.map(() => ({ data: conflictsData, isPending: conflictsPending })),
  useSchedulePlans: () => ({ data: plansState.failed ? undefined : plansData, isError: plansState.failed }),
}));
vi.mock("@/features/planning/queries", () => ({ useSchedules: () => ({ data: schedulesData }) }));
vi.mock("@/features/coach-wishes/campaignQueries", () => ({ useCoachWishCampaigns: () => ({ data: campaignsData, isError: false }) }));
// Saison de travail couvrant les fixtures FUTURE (2999) : le clamp saison des
// créations de vacances (revue #260 round 1) laisse passer les dates de test.
vi.mock("@/features/auth/queries", () => ({
  useWorkingSeason: () => ({ id: "sn1", name: "2998-2999", startDate: "2998-08-01", endDate: "2999-07-31", isCurrent: true, isReadonly: false }),
  useMe: () => ({ data: meData }),
}));

/** Un plan de période VALIDÉ (chosenScheduleId non-null) pour l'entrée donnée. */
const validatedPlan = (calendarEntryId: string, chosenScheduleId: string): SchedulePlan => ({
  id: `pl-${calendarEntryId}`,
  type: "CLOSURE",
  name: "Plan",
  startDate: FUTURE,
  calendarEntryId,
  chosenScheduleId,
  teamSelectionInitialized: false, hasVersions: false,
});

const FUTURE = "2999-01-05";
const FUTURE_END = "2999-01-18";

const holiday: SchoolHoliday = { id: "h1", label: "Vacances de Noël", holidayType: "noel", startDate: FUTURE, endDate: FUTURE_END, schoolYear: "2998-2999" };

const closure = (overrides: Partial<CalendarEntry>): CalendarEntry => ({
  id: "c1",
  kind: "period",
  title: "Gym Barros fermé",
  startDate: FUTURE,
  endDate: FUTURE_END,
  isDisruptive: false,
  periodType: "closure",
  schoolHolidayId: null,
  parentEntryId: null,
  status: "active",
  createdBy: null,
  ...overrides,
});

function renderRadar(props: Partial<Parameters<typeof RadarPanel>[0]> = {}) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <RadarPanel entries={[]} holidays={[]} publicHolidays={[]} zone="A" {...props} />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

/**
 * P3-13 (d) — la carte de COUVERTURE est la seule repliée par défaut (ses N puces de
 * semaine sont ce qui allonge le radar). Ses puces se lisent donc après dépliage ; le
 * titre et le compteur « x/y couvertes », eux, restent visibles sans un clic.
 */
const expandCard = async (user: ReturnType<typeof userEvent.setup>, title: string) => {
  await user.click(screen.getByRole("button", { name: `Déplier ${title}` }));
};

describe("RadarPanel", () => {
  // P3-13 — l'horloge injectable, dès son premier usage. Les fixtures sont ancrées en
  // 2999 pour ne jamais périmer ; sans horloge pilotable, l'horizon 60 j les masquerait
  // TOUTES et il faudrait repasser les dates en relatif — donc des tests dont on ne peut
  // plus lire la situation. Ici on déplace « aujourd'hui » à 21 jours des vacances.
  beforeEach(() => {
    setTodayOverride("2998-12-15");
    createHolidayMutate.mockReset();
    createHolidayMutateAsync.mockReset();
    createWeekChildrenMutate.mockReset();
    meData = { seasonPlan: { chosenScheduleId: "s-season" } };
    conflictsData = undefined;
    conflictsPending = false;
    plansData = [];
    schedulesData = [];
    campaignsData = [];
    plansState.failed = false;
  });
  afterEach(() => setTodayOverride(null));

  it("a HOLIDAY period whose plan has versions but no validated one shows an always-on « en cours » card", async () => {
    const user = userEvent.setup();
    const started = new Date();
    started.setDate(started.getDate() - 2);
    const startedIso = started.toISOString().slice(0, 10);
    // Période DÉJÀ COMMENCÉE (startDate < today) : le filtre « à venir » l'écarterait —
    // la carte « en cours » doit survivre tant que la période n'est pas finie.
    plansData = [{ id: "pl-h1", type: "HOLIDAY", name: "Plan", startDate: FUTURE, calendarEntryId: "h1", chosenScheduleId: null, teamSelectionInitialized: false, hasVersions: false }];
    schedulesData = [{ schedulePlanId: "pl-h1" }];
    renderRadar({ entries: [closure({ id: "h1", periodType: "holiday", title: "Vacances de Noël", startDate: startedIso, endDate: addDays(todayISO(), 3) })] });
    expect(screen.getByText("Planning en cours — à finaliser")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Reprendre" }));
  });

  // NR #5 / revue F3 : le gating bloque la CRÉATION, pas la REPRISE. « Reprendre » un
  // planning EN COURS reste actif même quand le plan de saison n'est pas validé
  // (sinon on figerait un travail commencé si la saison est rouverte).
  it("keeps « Reprendre » enabled on an in-progress planning even when the season plan is not validated", () => {
    meData = { seasonPlan: { chosenScheduleId: null } };
    const started = new Date();
    started.setDate(started.getDate() - 2);
    plansData = [{ id: "pl-h1", type: "HOLIDAY", name: "Plan", startDate: FUTURE, calendarEntryId: "h1", chosenScheduleId: null, teamSelectionInitialized: false, hasVersions: false }];
    schedulesData = [{ schedulePlanId: "pl-h1" }];
    renderRadar({ entries: [closure({ id: "h1", periodType: "holiday", title: "Vacances de Noël", startDate: started.toISOString().slice(0, 10), endDate: addDays(todayISO(), 3) })] });

    expect(screen.getByRole("button", { name: "Reprendre" })).toBeEnabled();
  });

  // B1 (retour fondateur 2026-07-19) : une vacance ajustée « d'un bloc » mais PAS
  // encore générée (0 version) doit rester visible « en cours » — sinon le
  // gestionnaire ne peut plus la reprendre.
  it("a whole-block holiday plan with ZERO generated version still shows an « en cours » card", () => {
    plansData = [{ id: "pl-h1", type: "HOLIDAY", name: "Plan", startDate: FUTURE, calendarEntryId: "h1", chosenScheduleId: null, teamSelectionInitialized: false, hasVersions: false }];
    schedulesData = []; // aucune version générée
    renderRadar({ entries: [closure({ id: "h1", periodType: "holiday", title: "Vacances de Noël", startDate: todayISO(), endDate: addDays(todayISO(), 5) })] });

    expect(screen.getByText("Planning en cours — à finaliser")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Reprendre" })).toBeInTheDocument();
  });

  // Revue B1 F1 : « Reprendre » d'une carte 0 version reste GATÉ tant que la saison
  // n'est pas validée (démarrer un secondaire = interdit) ; un plan AVEC versions,
  // lui, reste reprenable (couvert par le test « keeps Reprendre enabled » plus haut).
  it("disables « Reprendre » on a ZERO-version card while the season plan is not validated", () => {
    meData = { seasonPlan: { chosenScheduleId: null } };
    plansData = [{ id: "pl-h1", type: "HOLIDAY", name: "Plan", startDate: FUTURE, calendarEntryId: "h1", chosenScheduleId: null, teamSelectionInitialized: false, hasVersions: false }];
    schedulesData = []; // 0 version
    renderRadar({ entries: [closure({ id: "h1", periodType: "holiday", title: "Vacances de Noël", startDate: todayISO(), endDate: addDays(todayISO(), 5) })] });

    expect(screen.getByRole("button", { name: "Reprendre" })).toBeDisabled();
  });

  it("a CLOSURE with an in-progress plan keeps its rich impact card (sessions count) with « Reprendre »", () => {
    // La carte générique gommerait le détail des séances touchées (revue #260) :
    // la fermeture garde ClosureRadarItem, marquée « en cours », CTA Reprendre.
    plansData = [{ id: "pl-c1", type: "CLOSURE", name: "Plan", startDate: FUTURE, calendarEntryId: "c1", chosenScheduleId: null, teamSelectionInitialized: false, hasVersions: false }];
    schedulesData = [{ schedulePlanId: "pl-c1" }];
    conflictsData = { conflicts: [{ dates: ["2999-01-06", "2999-01-07"] }], seasonPlanChosen: true };
    renderRadar({ entries: [closure({})] });
    expect(screen.getByText(/2 séances à replacer · planning en cours — à finaliser/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Reprendre" })).toBeInTheDocument();
    expect(screen.queryByText("Planning en cours — à finaliser")).not.toBeInTheDocument();
  });

  it("no « en cours » card when the plan is validated or has no versions (fail-closed on missing data)", () => {
    // Plan validé → carte « en cours » absente (le flux normal Voir/Adapter prend le relais).
    plansData = [validatedPlan("c1", "s1")];
    schedulesData = [{ schedulePlanId: "pl-c1" }];
    conflictsData = { conflicts: [], seasonPlanChosen: true };
    renderRadar({ entries: [closure({})] });
    expect(screen.queryByText("Planning en cours — à finaliser")).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Reprendre" })).not.toBeInTheDocument();
  });

  // Revue C F1 : une vacance démarrant vendredi écarte sa semaine partielle de
  // début — MAIS un enfant DÉJÀ créé sur cette semaine (données existantes / adapt
  // d'un bloc) doit rester visible/gérable (filet #262), jamais disparaître.
  it("keeps an existing week-child on a holiday's skipped partial first week visible in the coverage card", async () => {
    const user = userEvent.setup();
    const w1s = mondayOf("2999-01-15"); // lundi
    const w2s = addDays(w1s, 7);
    const friday = addDays(w1s, 4); // vendredi de la 1ʳᵉ semaine
    renderRadar({
      entries: [
        // Mère VACANCE démarrant vendredi, couvrant 2 semaines calendaires.
        closure({ id: "hm", periodType: "holiday", title: "Toussaint", startDate: friday, endDate: addDays(w2s, 1) }),
        closure({ id: "w1", periodType: "holiday", title: "S1", parentEntryId: "hm", startDate: w1s, endDate: addDays(w1s, 6) }),
        closure({ id: "w2", periodType: "holiday", title: "S2", parentEntryId: "hm", startDate: w2s, endDate: addDays(w2s, 6) }),
      ],
    });
    // Les DEUX semaines-enfants restent affichées (la partielle n'est pas masquée).
    await expandCard(user, "Toussaint");
    expect(screen.getAllByRole("button", { name: /sem\. du/ })).toHaveLength(2);
  });

  // P2-5 E1 : une période DÉCOUPÉE porte une carte de COUVERTURE (chips par
  // semaine), et sa carte classique disparaît. Semaines ALIGNÉES sur le vrai
  // calendrier (mondayOf) : la carte calcule les slots via weeksCovering.
  it("a split mother shows one coverage card with per-week chips, no classic closure card", async () => {
    const user = userEvent.setup();
    const w1s = mondayOf("2999-01-15");
    const w2s = addDays(w1s, 7);
    plansData = [
      { id: "pl-w1", type: "CLOSURE", name: "S1", startDate: FUTURE, calendarEntryId: "w1", chosenScheduleId: "ov1", teamSelectionInitialized: false, hasVersions: false },
      { id: "pl-w2", type: "CLOSURE", name: "S2", startDate: FUTURE, calendarEntryId: "w2", chosenScheduleId: null, teamSelectionInitialized: false, hasVersions: false },
    ];
    renderRadar({
      entries: [
        // Mère du jeudi S1 au mardi S2 → weeksCovering rend exactement 2 semaines.
        closure({ id: "m1", title: "Barros en travaux", startDate: addDays(w1s, 3), endDate: addDays(w2s, 1) }),
        closure({ id: "w1", title: "S1", parentEntryId: "m1", startDate: w1s, endDate: addDays(w1s, 6) }),
        closure({ id: "w2", title: "S2", parentEntryId: "m1", startDate: w2s, endDate: addDays(w2s, 6) }),
      ],
    });
    expect(screen.getByText("1/2 semaines à venir couverte")).toBeInTheDocument();
    // Semaine validée → ✅ (Voir) ; à faire → Reprendre (adapt).
    await expandCard(user, "Barros en travaux");
    expect(screen.getByRole("button", { name: /✅/ })).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: /· à faire/ }));
    // Une seule carte pour la mère : pas de ClosureRadarItem classique en plus.
    expect(screen.queryByText(/à replacer/)).not.toBeInTheDocument();
  });

  // Une semaine DÉCOCHÉE au picker (ou perdue sur échec partiel) reste
  // planifiable : chip « + créer » (dead-end de la revue #262 round 1).
  it("a missing week shows a « + créer » chip on the coverage card", async () => {
    const user = userEvent.setup();
    const w1s = mondayOf("2999-01-15");
    plansData = [
      { id: "pl-w1", type: "CLOSURE", name: "S1", startDate: FUTURE, calendarEntryId: "w1", chosenScheduleId: "ov1", teamSelectionInitialized: false, hasVersions: false },
    ];
    renderRadar({
      entries: [
        closure({ id: "m1", title: "Barros en travaux", startDate: addDays(w1s, 3), endDate: addDays(w1s, 8) }),
        closure({ id: "w1", title: "S1", parentEntryId: "m1", startDate: w1s, endDate: addDays(w1s, 6) }),
        // Semaine 2 jamais créée — décochée au picker.
      ],
    });
    expect(screen.getByText("1/2 semaines à venir couverte")).toBeInTheDocument();
    await expandCard(user, "Barros en travaux");
    expect(screen.getByRole("button", { name: /\+ sem\. du/ })).toBeInTheDocument();
  });

  it("a fully covered split mother leaves the radar (to-do, not inventory)", () => {
    const w1s = mondayOf("2999-01-15");
    plansData = [
      { id: "pl-w1", type: "CLOSURE", name: "S1", startDate: FUTURE, calendarEntryId: "w1", chosenScheduleId: "ov1", teamSelectionInitialized: false, hasVersions: false },
    ];
    conflictsData = { conflicts: [], seasonPlanChosen: true };
    renderRadar({
      entries: [
        // Mère entièrement DANS la semaine 1 → une seule semaine, couverte + validée.
        closure({ id: "m1", title: "Barros en travaux", startDate: addDays(w1s, 1), endDate: addDays(w1s, 4) }),
        closure({ id: "w1", title: "S1", parentEntryId: "m1", startDate: w1s, endDate: addDays(w1s, 6) }),
      ],
    });
    expect(screen.queryByText(/semaines? à venir couverte/)).not.toBeInTheDocument();
    expect(screen.getByText(/Rien à l'horizon/)).toBeInTheDocument();
  });

  // ── P3-13 (retour terrain 2026-07-31) : le radar ne montre que l'avenir ACTIONNABLE ──

  // (a) « En été, Noël et la Toussaint apparaissent alors que c'est TROP loin pour que je
  // m'en occupe de suite. » Les fériés avaient un horizon depuis toujours, les vacances non.
  it("masque une vacance au-delà de l'horizon, garde celle qui approche", () => {
    setTodayOverride("2998-10-01"); // à 96 j des vacances fixture
    renderRadar({ holidays: [holiday] });
    expect(screen.queryByText("Vacances de Noël")).not.toBeInTheDocument();
    expect(screen.getByText(/Rien à l'horizon/)).toBeInTheDocument();

    cleanup();
    setTodayOverride("2998-12-15"); // à 21 j
    renderRadar({ holidays: [holiday] });
    expect(screen.getByText("Vacances de Noël")).toBeInTheDocument();
  });

  // La non-régression qui compte : l'horizon ne masque que les vacances INTACTES. Dès
  // qu'un plan existe, la période est un travail commencé — la cacher ferait perdre au
  // gestionnaire ce qu'il a déjà entamé, ce qui serait bien pire que le bruit corrigé.
  it("garde une vacance lointaine dès que son planning est commencé", () => {
    setTodayOverride("2998-10-01"); // hors horizon
    plansData = [{ id: "pl-h1", type: "HOLIDAY", name: "Plan", startDate: FUTURE, calendarEntryId: "h1", chosenScheduleId: null, teamSelectionInitialized: false, hasVersions: false }];
    schedulesData = [{ schedulePlanId: "pl-h1" }];
    renderRadar({
      holidays: [holiday],
      entries: [closure({ id: "h1", periodType: "holiday", title: "Vacances de Noël", schoolHolidayId: "h1", startDate: FUTURE, endDate: FUTURE_END })],
    });

    expect(screen.getByText("Planning en cours — à finaliser")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Reprendre" })).toBeInTheDocument();
  });

  // (b) « 0/7 semaines couvertes alors que 3 sont derrière — on gère l'avenir, pas le
  // présent. » Les semaines RÉVOLUES sortent du dénominateur ; la semaine ENTAMÉE y reste,
  // il lui reste des jours (revue #344 : « commencé » n'est pas « fini »).
  it("sort les semaines révolues du compte, garde celle qui est entamée", async () => {
    const user = userEvent.setup();
    const w1s = mondayOf("2999-01-04"); // lundi
    const weeks = [0, 1, 2, 3].map((i) => addDays(w1s, 7 * i));
    // « Aujourd'hui » = mercredi de la 2ᵉ semaine : S1 est révolue, S2 en cours,
    // S3 et S4 à venir → 2 semaines gérables, pas 4.
    setTodayOverride(addDays(weeks[1], 2));
    renderRadar({
      entries: [
        closure({ id: "m1", title: "Barros en travaux", startDate: w1s, endDate: addDays(weeks[3], 6) }),
        ...weeks.map((monday, i) => closure({ id: `w${i}`, title: `S${i}`, parentEntryId: "m1", startDate: monday, endDate: addDays(monday, 6) })),
      ],
    });

    // S1 est révolue ; S2 (en cours), S3 et S4 restent — pas 4, pas 2.
    expect(screen.getByText("0/3 semaines à venir couverte")).toBeInTheDocument();
    await expandCard(user, "Barros en travaux");
    expect(screen.getAllByRole("button", { name: /sem\. du/ })).toHaveLength(3);
  });

  it("efface la carte de couverture quand toutes ses semaines sont derrière", () => {
    const w1s = mondayOf("2999-01-04");
    setTodayOverride(addDays(w1s, 21)); // trois semaines plus tard
    renderRadar({
      entries: [
        closure({ id: "m1", title: "Barros en travaux", startDate: w1s, endDate: addDays(w1s, 13) }),
        closure({ id: "w0", title: "S0", parentEntryId: "m1", startDate: w1s, endDate: addDays(w1s, 6) }),
      ],
    });

    expect(screen.queryByText(/semaines? à venir couverte/)).not.toBeInTheDocument();
    expect(screen.getByText(/Rien à l'horizon/)).toBeInTheDocument();
  });

  // (d) « Le radar devient très long » — repli CIBLÉ (arbitrage fondateur 2026-08-01) :
  // seule la carte à détail volumineux démarre repliée ; une carte à une action garde son
  // bouton, sinon chaque geste du radar coûterait un clic de plus.
  it("replie la carte de couverture par défaut, mais pas l'action d'une carte simple", async () => {
    const user = userEvent.setup();
    const w1s = mondayOf("2999-01-15");
    renderRadar({
      holidays: [holiday],
      entries: [
        closure({ id: "m1", title: "Barros en travaux", startDate: addDays(w1s, 3), endDate: addDays(w1s, 8) }),
        closure({ id: "w1", title: "S1", parentEntryId: "m1", startDate: w1s, endDate: addDays(w1s, 6) }),
      ],
    });

    // Carte simple : son action est là, sans clic.
    expect(screen.getByRole("button", { name: "Adapter" })).toBeInTheDocument();
    // Carte de couverture : l'en-tête parle, les puces attendent.
    expect(screen.getByText(/semaines? à venir couverte/)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /sem\. du/ })).toBeNull();

    await expandCard(user, "Barros en travaux");
    expect(screen.getAllByRole("button", { name: /sem\. du/ }).length).toBeGreaterThan(0);
  });

  // ── Revue #344 : les pièges que « la semaine a commencé » avait ouverts ──

  // Une fermeture qui démarre MERCREDI devenait implanifiable dès le lundi : la puce
  // « + créer » de sa semaine disparaissait, et le DayDialog ne reproduit ces puces que
  // pour les vacances. Une semaine entamée porte encore du travail.
  it("garde la puce « + créer » d'une semaine entamée dont la fermeture est encore devant", async () => {
    const user = userEvent.setup();
    const w1s = mondayOf("2999-01-04");
    setTodayOverride(w1s); // le lundi même ; la fermeture ne commence que le mercredi
    plansData = [{ id: "pl-w2", type: "CLOSURE", name: "S2", startDate: FUTURE, calendarEntryId: "w2", chosenScheduleId: "ov", teamSelectionInitialized: false, hasVersions: false }];
    renderRadar({
      entries: [
        closure({ id: "m1", title: "Barros en travaux", startDate: addDays(w1s, 2), endDate: addDays(w1s, 9) }),
        // Seule la 2ᵉ semaine existe : celle du lundi courant est MANQUANTE (décochée).
        closure({ id: "w2", title: "S2", parentEntryId: "m1", startDate: addDays(w1s, 7), endDate: addDays(w1s, 13) }),
      ],
    });

    await expandCard(user, "Barros en travaux");
    expect(screen.getByRole("button", { name: /\+ sem\. du/ })).toBeInTheDocument();
  });

  // Le pire des deux : masquer la tâche était la décision du fondateur, AFFIRMER « tout
  // roule » par-dessus ne l'était pas. Une semaine orpheline encore en cours et non
  // validée doit garder sa carte — sa « seule surface » (revue #262).
  it("n'affirme jamais « Tout roule » sur une semaine en cours non validée", () => {
    const w1s = mondayOf("2999-01-04");
    setTodayOverride(addDays(w1s, 2)); // mercredi de cette semaine
    renderRadar({
      // Mère absente du radar (ignorée) → l'enfant est orphelin d'affichage.
      entries: [closure({ id: "w1", title: "S1", parentEntryId: "absente", startDate: w1s, endDate: addDays(w1s, 6) })],
    });

    expect(screen.getByText("Planning de semaine à finaliser")).toBeInTheDocument();
    expect(screen.queryByText(/Rien à l'horizon/)).not.toBeInTheDocument();
  });

  // L'horizon 60 j effaçait la SEULE surface qui rende le badge « x à traiter » et le
  // bouton « Solliciter les coachs » — or la collecte vit précisément du délai.
  it("garde une vacance lointaine qui porte déjà une campagne de doléances", () => {
    setTodayOverride("2998-10-01"); // à 96 j : hors horizon
    campaignsData = [{ id: "camp1", calendarEntryId: "h1", deadline: "2998-12-01", weeks: [], teamIds: [], totalCoachCount: 4, respondedCoachCount: 1, openWishCount: 2, lastReminderAt: null, coaches: [] }];
    renderRadar({
      holidays: [holiday],
      entries: [closure({ id: "h1", periodType: "holiday", title: "Vacances de Noël", schoolHolidayId: "h1", startDate: FUTURE, endDate: FUTURE_END })],
    });

    // ⚠ On épingle le BADGE, pas le titre : l'exemption existe pour lui, et un test sur le
    // titre resterait vert si la carte survivait sans son suivi (revue #344 round 2).
    expect(screen.getByText("Vacances de Noël")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Solliciter les coachs|Suivre la collecte|traiter/ })).toBeInTheDocument();
  });

  // Le badge de suivi n'existe nulle part ailleurs : le replier le rendait invisible, et
  // le gestionnaire refermait le cockpit en croyant n'avoir rien à traiter.
  it("laisse « Doléances » hors du repli sur la carte de couverture", () => {
    const w1s = mondayOf("2999-01-15");
    renderRadar({
      entries: [
        closure({ id: "hm", periodType: "holiday", title: "Toussaint", startDate: addDays(w1s, 3), endDate: addDays(w1s, 8) }),
        closure({ id: "w1", periodType: "holiday", title: "S1", parentEntryId: "hm", startDate: w1s, endDate: addDays(w1s, 6) }),
      ],
    });

    // Carte repliée (les puces sont absentes) mais l'action de suivi est là.
    expect(screen.queryByRole("button", { name: /sem\. du/ })).toBeNull();
    expect(screen.getByRole("button", { name: /Doléances/ })).toBeInTheDocument();
  });

  // ── Revue #344 round 2 : la règle vaut à TOUS ses sites, pas seulement là où je l'ai vue ──

  // Le picker offrait — et cochait — les semaines révolues. La semaine ainsi créée était
  // ensuite filtrée partout par le radar : un plan sans carte, sans puce, sans retour.
  it("n'offre pas une semaine révolue dans le picker de semaines", async () => {
    const user = userEvent.setup();
    const w1s = mondayOf("2999-01-04");
    // Vacance sur 3 semaines ; « aujourd'hui » = lundi de la 2ᵉ → la 1ʳᵉ est révolue.
    setTodayOverride(addDays(w1s, 7));
    renderRadar({ holidays: [{ id: "h9", label: "Vacances longues", holidayType: "noel", startDate: w1s, endDate: addDays(w1s, 20), schoolYear: "2998-2999" }] });

    await user.click(screen.getByRole("button", { name: "Adapter" }));
    expect(screen.getByText("Quelles semaines ajuster ?")).toBeInTheDocument();
    expect(screen.queryByText(new RegExp(`Semaine du ${frDateShort(w1s)}`))).toBeNull();
    expect(screen.getByText(new RegExp(`Semaine du ${frDateShort(addDays(w1s, 7))}`))).toBeInTheDocument();
  });

  // « Commencé » n'est pas « fini », à l'échelle VACANCE aussi : le filtre de période
  // gardait `startDate >= today` et faisait disparaître, dès le samedi de son début, le
  // seul point d'entrée vers « Adapter » et « Solliciter les coachs ».
  it("garde la carte d'une vacance déjà commencée dont les jours restent devant", () => {
    setTodayOverride(addDays(FUTURE, 2)); // deux jours après le début, la vacance dure 13 j
    renderRadar({ holidays: [holiday] });

    expect(screen.getByText("Vacances de Noël")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Adapter" })).toBeInTheDocument();
  });

  // Le cap borne le BRUIT, pas ce qu'on a décidé de garder : trié par date, la vacance
  // exemptée est toujours la dernière, donc la première coupée — le cap annulait
  // silencieusement l'exemption qu'il côtoie.
  it("ne laisse pas le cap de 3 vacances manger celle qui porte une campagne", () => {
    setTodayOverride("2998-12-01");
    const near = [0, 1, 2].map((i) => ({ id: `n${i}`, label: `Proche ${i}`, holidayType: "noel", startDate: addDays("2998-12-05", i), endDate: addDays("2998-12-12", i), schoolYear: "2998-2999" }));
    campaignsData = [{ id: "camp1", calendarEntryId: "h1", deadline: "2999-01-01", weeks: [], teamIds: [], totalCoachCount: 4, respondedCoachCount: 1, openWishCount: 2, lastReminderAt: null, coaches: [] }];
    renderRadar({
      holidays: [...near, holiday], // + la lointaine, exemptée par sa campagne
      entries: [closure({ id: "h1", periodType: "holiday", title: "Vacances de Noël", schoolHolidayId: "h1", startDate: FUTURE, endDate: FUTURE_END })],
    });

    expect(screen.getByText("Vacances de Noël")).toBeInTheDocument();
  });

  // Une lecture RATÉE n'est ni « ça charge » ni « tout roule » : le squelette bâti sur
  // « pas de donnée » transformait un échec en « Chargement… » perpétuel.
  it("dit l'échec au lieu de charger indéfiniment quand une lecture a échoué", () => {
    plansState.failed = true;
    renderRadar({});

    expect(screen.getByRole("alert")).toHaveTextContent(/Impossible de charger les éléments à traiter/);
    expect(screen.queryByText(/Chargement des éléments à traiter/)).toBeNull();
    expect(screen.queryByText(/Rien à l'horizon/)).toBeNull();
  });

  // L'exemption fait dépendre l'EXISTENCE d'une carte de la lecture des campagnes : tant
  // qu'elle n'a pas répondu, « Tout roule » serait un vide crédible.
  it("n'affirme pas « Tout roule » tant que les campagnes ne sont pas lues", () => {
    campaignsData = undefined;
    renderRadar({});

    expect(screen.queryByText(/Rien à l'horizon/)).toBeNull();
  });

  // P3-11 : le panneau restait NU pendant que les plans arrivaient — un cadre « À traiter »
  // vide se lit comme « rien à faire ». Le squelette dit la seule chose vraie : on ne sait
  // pas encore. Et il ne coexiste JAMAIS avec « Tout roule ».
  it("montre un squelette tant que les lectures sont en vol, jamais « Tout roule »", () => {
    plansData = undefined;
    renderRadar({});

    expect(screen.getByText(/Chargement des éléments à traiter/)).toBeInTheDocument();
    expect(screen.queryByText(/Rien à l'horizon/)).not.toBeInTheDocument();
  });

  it("asks for the school zone when unknown", () => {
    renderRadar({ zone: null });
    expect(screen.getByText("Zone scolaire à renseigner")).toBeInTheDocument();
  });

  it("does not flash the zone card while holidays are loading", () => {
    renderRadar({ zone: null, zoneLoading: true });
    expect(screen.queryByText("Zone scolaire à renseigner")).not.toBeInTheDocument();
  });

  // NR #1 (retour fondateur 2026-07-19) : adapter une vacance MULTI-SEMAINES ouvre
  // le picker SANS matérialiser la mère — annuler ne doit laisser AUCUN événement
  // fantôme. La mère naît seulement à la confirmation des semaines.
  it("a multi-week holiday opens the week picker WITHOUT creating any entry, and cancelling leaves nothing", async () => {
    const user = userEvent.setup();
    // FUTURE → FUTURE_END (5 → 18 janv.) couvre plusieurs semaines calendaires.
    renderRadar({ holidays: [holiday] });

    expect(screen.getByText("Vacances de Noël")).toBeInTheDocument();
    expect(screen.getByText(/pas de planning/)).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Adapter" }));
    // Le picker s'ouvre…
    expect(screen.getByText("Quelles semaines ajuster ?")).toBeInTheDocument();
    // …mais RIEN n'a été créé (ni mère, ni semaines).
    expect(createHolidayMutate).not.toHaveBeenCalled();
    expect(createHolidayMutateAsync).not.toHaveBeenCalled();
    expect(createWeekChildrenMutate).not.toHaveBeenCalled();

    // Annuler → toujours rien.
    await user.click(screen.getByRole("button", { name: /fermer/i }));
    expect(createHolidayMutateAsync).not.toHaveBeenCalled();
    expect(createWeekChildrenMutate).not.toHaveBeenCalled();
  });

  it("a single-week holiday materialises the period then adapts directly (no picker)", async () => {
    const user = userEvent.setup();
    const mon = mondayOf("2999-01-15");
    // Lundi → mercredi : une seule semaine calendaire → pas de picker.
    const oneWeek: SchoolHoliday = { id: "h2", label: "Pont", holidayType: "noel", startDate: mon, endDate: addDays(mon, 2), schoolYear: "2998-2999" };
    renderRadar({ holidays: [oneWeek] });

    await user.click(screen.getByRole("button", { name: "Adapter" }));
    expect(screen.queryByText("Quelles semaines ajuster ?")).not.toBeInTheDocument();
    expect(createHolidayMutate).toHaveBeenCalledWith({ schoolHolidayId: "h2", label: "Pont", startDate: mon, endDate: addDays(mon, 2) }, expect.anything());
  });

  // NR #5 : plan de saison non validé → encart rouge + ajustements désactivés.
  it("blocks adjustments and shows a red banner while the season plan is not validated", () => {
    meData = { seasonPlan: { chosenScheduleId: null } };
    renderRadar({ holidays: [holiday] });

    expect(screen.getByText("Planning de la saison à valider")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Adapter" })).toBeDisabled();
  });

  it("counts the sessions to replace on a closure without overlay", () => {
    conflictsData = { conflicts: [{ dates: [FUTURE, "2999-01-12"] }, { dates: ["2999-01-06"] }], seasonPlanChosen: true };
    renderRadar({ entries: [closure({})] });

    expect(screen.getByText(/3 séances à replacer · planning secondaire absent/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Adapter" })).toBeInTheDocument();
  });

  it("shows the season plan as incomplete when it points at nothing", () => {
    // Le serveur ne rend AUCUN conflit faute de calendrier à comparer. Sans
    // distinguer ce cas de « zéro conflit », le gestionnaire déclare une fermeture
    // de gymnase, lit que tout va bien, et n'adapte rien.
    conflictsData = { conflicts: [], seasonPlanChosen: false };
    renderRadar({ entries: [closure({})] });

    expect(screen.getByText(/Planning de la saison incomplet · impact non évalué/)).toBeInTheDocument();
    expect(screen.queryByText("Rien à signaler")).not.toBeInTheDocument();
  });

  it("never announces « tout roule » while a closure's impact is still loading", () => {
    // Masquer une carte parce qu'on ne sait pas ENCORE, tout en annonçant que tout va
    // bien, c'est le silence qui ment — juste déplacé du libellé vers le filtre.
    conflictsPending = true;
    conflictsData = undefined;
    renderRadar({ entries: [closure({ title: "Gymnase fermé" })] });

    expect(screen.queryByText("Gymnase fermé")).not.toBeInTheDocument();
    expect(screen.queryByText("Rien à l'horizon. Tout roule.")).not.toBeInTheDocument();
  });

  it("surfaces a closure whose impact could NOT be read (failed request) instead of silently dropping it", () => {
    // Requête en échec : `data` reste undefined pour toujours. La masquer cacherait
    // définitivement une fermeture qui, en vrai, tombe sur 6 séances.
    conflictsPending = false;
    conflictsData = undefined;
    renderRadar({ entries: [closure({ title: "Gymnase fermé" })] });

    expect(screen.getByText("Gymnase fermé")).toBeInTheDocument();
    // …et sans PRÉTENDRE savoir pourquoi : « planning incomplet » serait affirmer un
    // fait sur le plan qu'on n'a justement pas pu vérifier.
    expect(screen.getByText("Impact non évalué · réessayez")).toBeInTheDocument();
    expect(screen.queryByText(/Planning de la saison incomplet/)).not.toBeInTheDocument();
  });

  it("hides a closure that hits nothing on a validated plan — the radar is a to-do list, not an inventory", () => {
    // Décision fondateur : « un planning sans conflit qui a été validé, je veux rien
    // voir ». Le radar montre ce qui CHANGE par rapport au quotidien.
    conflictsData = { conflicts: [], seasonPlanChosen: true };
    renderRadar({ entries: [closure({ title: "Gymnase fermé" })] });

    expect(screen.queryByText("Gymnase fermé")).not.toBeInTheDocument();
    // …et le panneau redevient franchement vide, au lieu d'un cadre « À traiter » désert.
    expect(screen.getByText("Rien à l'horizon. Tout roule.")).toBeInTheDocument();
  });

  it("fail-closed: while plans are unresolved (no data yet), neither flashes the all-clear nor offers a misleading 'Adapter'", () => {
    // État d'une période INCONNU tant qu'on n'a pas les plans (1er chargement, ou 1er échec sans
    // donnée) : ne pas dire « tout roule » (masquerait une fermeture validée) ni proposer
    // « Adapter » (régénérerait un plan validé).
    plansData = undefined;
    renderRadar({ entries: [closure({ title: "Gymnase fermé" })] });

    expect(screen.queryByText("Rien à l'horizon. Tout roule.")).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Adapter" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Voir le planning" })).not.toBeInTheDocument();
  });

  it("keeps rendering stale plans after a background refetch error (keys on data presence, not query status)", () => {
    // TanStack passe en error sur un refetch d'arrière-plan tout en gardant la donnée : le radar
    // ne doit PAS disparaître — la donnée présente reste affichable (« Voir le planning »).
    plansData = [validatedPlan("c1", "ov1")];
    renderRadar({ entries: [closure({})] });

    expect(screen.getByRole("button", { name: "Voir le planning" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Adapter" })).not.toBeInTheDocument();
  });

  it("switches to consult/adjust once the plan is validated", () => {
    // ADR-0002 lot D-b : « l'overlay existe » = le plan de la période est VALIDÉ.
    plansData = [validatedPlan("c1", "ov1")];
    renderRadar({ entries: [closure({})] });

    expect(screen.getByText("Planning secondaire validé")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Voir le planning" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Ajuster" })).toBeInTheDocument();
  });

  it("shows the all-clear when there is nothing to handle", () => {
    renderRadar();
    expect(screen.getByText("Rien à l'horizon. Tout roule.")).toBeInTheDocument();
  });

  it("shows an upcoming cutoff as a plain reminder without any CTA", () => {
    renderRadar({ entries: [closure({ id: "cut1", periodType: "cutoff", title: "Coupure de Noël" })] });

    expect(screen.getByText("Coupure de Noël")).toBeInTheDocument();
    expect(screen.getByText(/aucun entraînement/)).toBeInTheDocument();
    // Reminder only: no plan to prepare for a cutoff (no Adapter / Voir le planning).
    expect(screen.queryByRole("button", { name: "Adapter" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Voir le planning" })).not.toBeInTheDocument();
  });

  it("formats cutoff dates in short French, never raw ISO", () => {
    renderRadar({ entries: [closure({ id: "cut1", periodType: "cutoff", title: "Coupure de Noël" })] });

    // FUTURE window = 2999-01-05 → 2999-01-18: rendered as French short dates.
    expect(screen.getByText(/Du 5 janv\. 2999 au 18 janv\. 2999 · aucun entraînement/)).toBeInTheDocument();
    expect(screen.queryByText(/2999-01-05/)).not.toBeInTheDocument();
  });

  it("does not flash the all-clear while public holidays are still loading", () => {
    renderRadar({ publicHolidaysLoading: true });

    expect(screen.queryByText("Rien à l'horizon. Tout roule.")).not.toBeInTheDocument();
  });

  it("reminds about public holidays within 30 days, ignores farther ones", () => {
    const today = todayISO();
    renderRadar({
      publicHolidays: [
        { id: "ph1", date: addDays(today, 10), label: "Férié proche", national: true },
        { id: "ph2", date: addDays(today, 60), label: "Férié lointain", national: true },
      ],
    });

    expect(screen.getByText("Férié proche")).toBeInTheDocument();
    expect(screen.getByText(/jour férié/)).toBeInTheDocument();
    expect(screen.queryByText("Férié lointain")).not.toBeInTheDocument();
  });
});
