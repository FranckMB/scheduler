import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { CalendarEntry, PublicHoliday, SchoolHoliday } from "./api";
import { DayDialog } from "./DayDialog";

const deleteMutate = vi.fn();
const cutoffMutate = vi.fn();
const closureMutate = vi.fn();
// "Adapter" (create branch) uses mutateAsync so the wizard navigation survives a
// mid-POST modal dismiss — the mock resolves with the created period's id.
// Entrée COMPLÈTE (comme l'API réelle) : requestAdapt lit startDate/endDate pour
// calculer les semaines — un {id} nu ferait jeter weeksCovering en silence.
const holidayMutateAsync = vi.fn(() =>
  Promise.resolve({ id: "created-hol", kind: "period", periodType: "holiday", title: "Vacances de Noël", startDate: "2026-05-10", endDate: "2026-05-20", isDisruptive: false, schoolHolidayId: "sh1", parentEntryId: null, status: "active", createdBy: null }),
);
const navigate = vi.fn();
const startPeriodMode = vi.fn();
const setSelectedScheduleId = vi.fn();
const weekChildrenMutate = vi.fn();
// Plans couvrant le jour (B1) : DayList lit chosenScheduleId par calendarEntryId.
let allPlansMock: { id: string; calendarEntryId: string | null; chosenScheduleId: string | null }[] = [];

// ADR-0002 lot D-b : « overlay validé » (HolidayBlock « Voir le planning ») = plan de
// période avec chosenScheduleId ; « porte des versions » (garde destructive de suppression)
// = une Schedule pend au plan (schedulePlanId). Les deux se dérivent du plan, plus de
// pointeur sur l'entrée.
let plansByEntry: Record<string, { id: string; chosenScheduleId: string | null }> = {};
let schedulesData: { id: string; schedulePlanId: string | null }[] = [];
// undefined data = requêtes pas encore résolues (1er chargement ou 1er échec sans donnée) →
// fail-closed. Le code clé sur la PRÉSENCE de `data`, pas sur le statut (une donnée périmée
// après un refetch en échec reste exploitable).
let queriesNoData = false;
// #5 gating : socle (plan de saison) validé par défaut ; un test dédié le passe à null.
let meData: { seasonPlan: { chosenScheduleId: string | null } } = { seasonPlan: { chosenScheduleId: "s-season" } };

vi.mock("./queries", () => ({
  useCreateEvent: () => ({ mutate: vi.fn(), isPending: false }),
  useCreateVenueClosure: () => ({ mutate: closureMutate, isPending: false }),
  useCreateCutoff: () => ({ mutate: cutoffMutate, isPending: false }),
  useCreateHolidayPeriod: () => ({ mutate: vi.fn(), mutateAsync: holidayMutateAsync, isPending: false }),
  useCreateWeekChildren: () => ({ mutate: weekChildrenMutate, isPending: false }),
  useDeleteEntry: () => ({ mutate: deleteMutate, isPending: false }),
  useSchedulePlanForEntry: (id: string | null) => ({ data: null !== id && !queriesNoData ? (plansByEntry[id] ?? null) : undefined }),
  // P2-5 E1 : enfants de semaine — aucun par défaut dans ces tests.
  useCalendarEntries: () => ({ data: [] }),
  useSchedulePlans: () => ({ data: allPlansMock }),
}));
vi.mock("@/features/planning/queries", () => ({
  useVenues: () => ({ data: [{ id: "v1", name: "Gymnase A", color: null, canSplit: false, isActive: true }] }),
  useSchedules: () => ({ data: queriesNoData ? undefined : schedulesData }),
}));
vi.mock("react-router-dom", async (orig) => ({ ...(await orig<typeof import("react-router-dom")>()), useNavigate: () => navigate }));
vi.mock("@/features/wizard/store", () => ({ useWizardStore: (sel: (s: unknown) => unknown) => sel({ startPeriodMode }) }));
vi.mock("@/features/planning/store", () => ({ usePlanningStore: (sel: (s: unknown) => unknown) => sel({ setSelectedScheduleId }) }));
// Freeze "today" so the fixed test date (2026-05-12) is not in the past (start ≥ today).
// Partiel : clampRangeToSeason (clamp saison des créations, revue #260) reste le vrai.
vi.mock("./lib/date", async (orig) => ({ ...(await orig<typeof import("./lib/date")>()), todayISO: () => "2026-05-12" }));
// Saison de travail couvrant les dates de test : le clamp laisse créer.
vi.mock("@/features/auth/queries", () => ({
  useWorkingSeason: () => ({ id: "sn1", name: "2025-2026", startDate: "2025-08-01", endDate: "2026-07-31", isCurrent: true, isReadonly: false }),
  useMe: () => ({ data: meData }),
}));

const entry = (overrides: Partial<CalendarEntry>): CalendarEntry => ({
  id: "e1",
  kind: "event",
  title: "AG du club",
  startDate: "2026-05-12",
  endDate: "2026-05-12",
  isDisruptive: false,
  periodType: null,
  schoolHolidayId: null,
  parentEntryId: null,
  status: "active",
  createdBy: null,
  ...overrides,
});

function renderDialog(entries: CalendarEntry[], holidays: { holiday?: SchoolHoliday; publicHoliday?: PublicHoliday } = {}) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <DayDialog iso="2026-05-12" entries={entries} holiday={holidays.holiday} publicHoliday={holidays.publicHoliday} onClose={vi.fn()} />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

const schoolHoliday = (over: Partial<SchoolHoliday> = {}): SchoolHoliday => ({ id: "sh1", label: "Vacances de Noël", holidayType: "noel", startDate: "2026-05-10", endDate: "2026-05-20", schoolYear: "2025-2026", ...over });

describe("DayDialog — deletion is always confirmed", () => {
  beforeEach(() => {
    deleteMutate.mockReset();
    cutoffMutate.mockReset();
    closureMutate.mockReset();
    holidayMutateAsync.mockClear();
    weekChildrenMutate.mockReset();
    meData = { seasonPlan: { chosenScheduleId: "s-season" } };
    allPlansMock = [];
    plansByEntry = {};
    schedulesData = [];
    queriesNoData = false;
  });

  it("asks for confirmation before deleting, then deletes on confirm", async () => {
    renderDialog([entry({})]);

    await userEvent.click(screen.getByRole("button", { name: "Supprimer AG du club" }));
    // Nothing deleted yet — a confirmation dialog opened instead.
    expect(deleteMutate).not.toHaveBeenCalled();
    expect(screen.getByText(/Supprimer « AG du club » \?/)).toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: "Supprimer" }));
    expect(deleteMutate).toHaveBeenCalledWith("e1", expect.anything());
  });

  it("cancel closes the confirmation without deleting", async () => {
    renderDialog([entry({})]);

    await userEvent.click(screen.getByRole("button", { name: "Supprimer AG du club" }));
    await userEvent.click(screen.getByRole("button", { name: "Annuler" }));

    expect(deleteMutate).not.toHaveBeenCalled();
    expect(screen.queryByText(/Supprimer « AG du club » \?/)).not.toBeInTheDocument();
  });

  it("warns that deleting a period cascades to its plan and all its versions", async () => {
    // Décision fondateur : la suppression emporte le plan ET toutes ses versions —
    // on avertit dès qu'une version pend au plan (brouillon inclus), pas seulement validée.
    plansByEntry = { p1: { id: "plan-p1", chosenScheduleId: null } };
    schedulesData = [{ id: "draft1", schedulePlanId: "plan-p1" }];
    renderDialog([entry({ id: "p1", kind: "period", periodType: "closure", title: "Gym fermé" })]);

    await userEvent.click(screen.getByRole("button", { name: "Supprimer Gym fermé" }));

    expect(screen.getByText(/son plan et toutes ses versions/i)).toBeInTheDocument();
  });

  it("keeps the benign message when the period plan carries no version yet", async () => {
    plansByEntry = { p2: { id: "plan-p2", chosenScheduleId: null } };
    schedulesData = []; // plan vide → la suppression ne perd rien
    renderDialog([entry({ id: "p2", kind: "period", periodType: "closure", title: "Vide" })]);

    await userEvent.click(screen.getByRole("button", { name: "Supprimer Vide" }));

    expect(screen.getByText("Cette entrée sera retirée du calendrier.")).toBeInTheDocument();
  });

  it("fail-closed: while the period's plan/versions are unresolved (no data yet), warns about the cascade (never under-warns)", async () => {
    // Le dialogue s'ouvre avant que le plan réponde (1er chargement, ou 1er échec sans donnée) :
    // sous-avertir ferait perdre des versions après un message bénin (régression P4-19).
    queriesNoData = true;
    plansByEntry = {}; // plan pas encore résolu
    schedulesData = [];
    renderDialog([entry({ id: "p3", kind: "period", periodType: "closure", title: "En cours" })]);

    await userEvent.click(screen.getByRole("button", { name: "Supprimer En cours" }));

    expect(screen.getByText(/son plan et toutes ses versions/i)).toBeInTheDocument();
  });

  it("resolved data stays benign for an empty plan even if a background refetch errors (keys on data, not status)", async () => {
    // TanStack passe en error sur un refetch d'arrière-plan en gardant la donnée : un plan
    // VIDE résolu doit rester bénin — s'y fier sur isSuccess sur-avertirait à chaque blip.
    plansByEntry = { p4: { id: "plan-p4", chosenScheduleId: null } };
    schedulesData = []; // plan résolu et vide → rien à perdre
    renderDialog([entry({ id: "p4", kind: "period", periodType: "holiday", title: "Vacances" })]);

    await userEvent.click(screen.getByRole("button", { name: "Supprimer Vacances" }));

    expect(screen.getByText("Cette entrée sera retirée du calendrier.")).toBeInTheDocument();
  });

  it("never flashes the cascade warning for a cutoff, even while unresolved (no plan, inv. 9)", async () => {
    // Régression évitée : le fail-closed ne doit pas s'armer sur un type non overlayable —
    // cutoff/mutualisation/custom ne portent jamais de plan, aucune cascade à annoncer.
    queriesNoData = true;
    renderDialog([entry({ id: "p5", kind: "period", periodType: "cutoff", title: "Coupure" })]);

    await userEvent.click(screen.getByRole("button", { name: "Supprimer Coupure" }));

    expect(screen.getByText("Cette entrée sera retirée du calendrier.")).toBeInTheDocument();
  });

  it("keeps the custom period button disabled (deferred palier B/C)", () => {
    renderDialog([]);

    expect(screen.getByRole("button", { name: "Créer une période…" })).toBeDisabled();
  });

  it("creates a cutoff with the default title when left empty", async () => {
    renderDialog([]);

    await userEvent.click(screen.getByRole("button", { name: "Coupure (pas d'entraînement)" }));
    await userEvent.click(screen.getByRole("button", { name: "Enregistrer" }));

    expect(cutoffMutate).toHaveBeenCalledWith({ title: "Coupure", startDate: "2026-05-12", endDate: "2026-05-12" }, expect.anything());
  });

  it("creates a cutoff with a custom title and end date", async () => {
    renderDialog([]);

    await userEvent.click(screen.getByRole("button", { name: "Coupure (pas d'entraînement)" }));
    await userEvent.type(screen.getByPlaceholderText(/Intitulé \(optionnel/), "Coupure de Noël");
    const endInput = screen.getByLabelText("Jusqu'au");
    await userEvent.clear(endInput);
    await userEvent.type(endInput, "2026-05-18");
    await userEvent.click(screen.getByRole("button", { name: "Enregistrer" }));

    expect(cutoffMutate).toHaveBeenCalledWith({ title: "Coupure de Noël", startDate: "2026-05-12", endDate: "2026-05-18" }, expect.anything());
  });

  it("builds a structured '{venue} — {reason}' closure title, defaulting the reason to 'fermé'", async () => {
    renderDialog([]);

    await userEvent.click(screen.getByRole("button", { name: "Signaler une indisponibilité" }));
    await userEvent.selectOptions(screen.getByRole("combobox"), "v1");
    await userEvent.click(screen.getByRole("button", { name: "Enregistrer" }));

    expect(closureMutate).toHaveBeenCalledWith(
      { title: "Gymnase A — fermé", startDate: "2026-05-12", endDate: "2026-05-12", venueId: "v1" },
      expect.anything(),
    );
  });

  it("puts the typed reason after the venue in the closure title", async () => {
    renderDialog([]);

    await userEvent.click(screen.getByRole("button", { name: "Signaler une indisponibilité" }));
    await userEvent.selectOptions(screen.getByRole("combobox"), "v1");
    await userEvent.type(screen.getByPlaceholderText(/Intitulé \(optionnel/), "Travaux");
    await userEvent.click(screen.getByRole("button", { name: "Enregistrer" }));

    expect(closureMutate).toHaveBeenCalledWith(
      { title: "Gymnase A — Travaux", startDate: "2026-05-12", endDate: "2026-05-12", venueId: "v1" },
      expect.anything(),
    );
  });

  it("does not repeat the venue when the typed reason already names it", async () => {
    renderDialog([]);

    await userEvent.click(screen.getByRole("button", { name: "Signaler une indisponibilité" }));
    await userEvent.selectOptions(screen.getByRole("combobox"), "v1");
    await userEvent.type(screen.getByPlaceholderText(/Intitulé \(optionnel/), "Gymnase A en travaux");
    await userEvent.click(screen.getByRole("button", { name: "Enregistrer" }));

    expect(closureMutate).toHaveBeenCalledWith(
      { title: "Gymnase A en travaux", startDate: "2026-05-12", endDate: "2026-05-12", venueId: "v1" },
      expect.anything(),
    );
  });

  // Lot B — item 2: the clicked day is only a DEFAULT start; both ends are editable.
  it("lets the start date be changed (clicked day is only the default)", async () => {
    renderDialog([]);

    await userEvent.click(screen.getByRole("button", { name: "Coupure (pas d'entraînement)" }));
    const startInput = screen.getByLabelText("Du");
    await userEvent.clear(startInput);
    await userEvent.type(startInput, "2026-05-15");
    const endInput = screen.getByLabelText("Jusqu'au");
    await userEvent.clear(endInput);
    await userEvent.type(endInput, "2026-05-18");
    await userEvent.click(screen.getByRole("button", { name: "Enregistrer" }));

    expect(cutoffMutate).toHaveBeenCalledWith({ title: "Coupure", startDate: "2026-05-15", endDate: "2026-05-18" }, expect.anything());
  });

  // Moving the start past the end must bump the end so the window never inverts.
  it("clamps the end forward when the start is moved past it", async () => {
    renderDialog([]);

    await userEvent.click(screen.getByRole("button", { name: "Coupure (pas d'entraînement)" }));
    const startInput = screen.getByLabelText("Du");
    await userEvent.clear(startInput);
    await userEvent.type(startInput, "2026-05-20"); // later than the default end (2026-05-12)
    await userEvent.click(screen.getByRole("button", { name: "Enregistrer" }));

    expect(cutoffMutate).toHaveBeenCalledWith({ title: "Coupure", startDate: "2026-05-20", endDate: "2026-05-20" }, expect.anything());
  });
});

describe("DayDialog — holiday awareness (Lot B)", () => {
  beforeEach(() => {
    holidayMutateAsync.mockClear();
    navigate.mockClear();
    startPeriodMode.mockClear();
    setSelectedScheduleId.mockClear();
    weekChildrenMutate.mockReset();
    meData = { seasonPlan: { chosenScheduleId: "s-season" } };
    allPlansMock = [];
    plansByEntry = {};
    schedulesData = [];
    queriesNoData = false;
  });

  // item 1: a public holiday (jour férié) shows read-only info.
  it("shows the public-holiday info banner", () => {
    renderDialog([], { publicHoliday: { id: "ph1", date: "2026-05-12", label: "Ascension", national: true } });
    expect(screen.getByText("Jour férié")).toBeInTheDocument();
    expect(screen.getByText(/Ascension/)).toBeInTheDocument();
  });

  // item 1 + 3: a school holiday shows info AND the "Adapter" entry point.
  // NR #1 (retour fondateur 2026-07-19) : ces vacances couvrent PLUSIEURS semaines
  // → « Adapter » ouvre le CHOIX DES SEMAINES SANS matérialiser la mère (annuler ne
  // doit laisser aucun événement fantôme). La mère naît à la confirmation.
  it("shows the school-holiday info + « Adapter » opens the week picker WITHOUT creating anything on a multi-week holiday", async () => {
    renderDialog([], { holiday: schoolHoliday() });
    expect(screen.getByText("Vacances")).toBeInTheDocument();
    expect(screen.getByText(/Vacances de Noël/)).toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: "Adapter" }));
    // Le picker s'ouvre immédiatement — AUCUNE création tant que non confirmé.
    expect(screen.getByText("Quelles semaines ajuster ?")).toBeInTheDocument();
    expect(holidayMutateAsync).not.toHaveBeenCalled();
    expect(startPeriodMode).not.toHaveBeenCalled();
    // Le chemin « d'un bloc » matérialise ALORS la mère puis mène au wizard.
    await userEvent.click(screen.getByRole("button", { name: /d'un bloc/i }));
    expect(holidayMutateAsync).toHaveBeenCalledWith({ schoolHolidayId: "sh1", label: "Vacances de Noël", startDate: "2026-05-10", endDate: "2026-05-20" });
    await waitFor(() => expect(startPeriodMode).toHaveBeenCalledWith("created-hol"));
    expect(navigate).toHaveBeenCalledWith("/wizard");
  });

  // La branche à UNE seule semaine calendaire va DIRECT au wizard (pas de picker) —
  // le test multi-semaines ci-dessus ne la couvre plus (revue #262 round 3).
  it("adapts a single-calendar-week holiday directly, without the week picker", async () => {
    // Vacances d'UNE semaine pleine (lun→dim) → weeksCovering rend 1 semaine.
    holidayMutateAsync.mockResolvedValueOnce({ id: "hol-1w", kind: "period", periodType: "holiday", title: "Court", startDate: "2026-05-11", endDate: "2026-05-15", isDisruptive: false, schoolHolidayId: "sh-1w", parentEntryId: null, status: "active", createdBy: null });
    renderDialog([], { holiday: schoolHoliday({ id: "sh-1w", label: "Court", startDate: "2026-05-11", endDate: "2026-05-15" }) });

    await userEvent.click(screen.getByRole("button", { name: "Adapter" }));
    await waitFor(() => expect(startPeriodMode).toHaveBeenCalledWith("hol-1w"));
    expect(screen.queryByText("Quelles semaines ajuster ?")).not.toBeInTheDocument();
    expect(navigate).toHaveBeenCalledWith("/wizard");
  });

  // finding [1]: an existing overlay stays viewable even for a summer holiday (legacy data).
  it("still offers « Voir le planning » for a summer holiday that already has an overlay", () => {
    plansByEntry = { pe: { id: "plan-pe", chosenScheduleId: "ov-ete" } };
    const periodEntry = entry({ id: "pe", kind: "period", periodType: "holiday", schoolHolidayId: "sh-ete", startDate: "2026-05-10", endDate: "2026-05-20" });
    renderDialog([periodEntry], { holiday: schoolHoliday({ id: "sh-ete", label: "Vacances d'Été", holidayType: "ete" }) });
    expect(screen.getByRole("button", { name: "Voir le planning" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Adapter" })).not.toBeInTheDocument();
  });

  // item 3: once the holiday overlay is generated, offer "Voir le planning" instead.
  it("offers « Voir le planning » when the holiday's overlay is already generated", () => {
    plansByEntry = { p9: { id: "plan-p9", chosenScheduleId: "ov9" } };
    const periodEntry = entry({ id: "p9", kind: "period", periodType: "holiday", schoolHolidayId: "sh1", startDate: "2026-05-10", endDate: "2026-05-20" });
    renderDialog([periodEntry], { holiday: schoolHoliday() });
    expect(screen.getByRole("button", { name: "Voir le planning" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Adapter" })).not.toBeInTheDocument();
  });

  // The modal reuses the calendar's emoji markers (same look in both places).
  it("marks day entries with the same calendar emojis (⛔ closure, 🛑 cutoff)", () => {
    renderDialog([entry({ id: "c1", kind: "period", periodType: "closure", title: "Gym fermé" }), entry({ id: "c2", kind: "period", periodType: "cutoff", title: "Coupure de Noël" })]);
    expect(screen.getByText("⛔")).toBeInTheDocument();
    expect(screen.getByText("🛑")).toBeInTheDocument();
  });

  // L'été s'adapte comme les autres vacances (planning de reprise — retour
  // fondateur 2026-07-18, P2-5 E2 : l'exclusion `ete` est levée).
  it("offers « Adapter » on a summer holiday (planning de reprise)", () => {
    renderDialog([], { holiday: schoolHoliday({ id: "sh-ete", label: "Vacances d'Été", holidayType: "ete" }) });
    expect(screen.getByText(/Vacances d'Été/)).toBeInTheDocument();
    expect(screen.queryByText(/hors saison/i)).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Adapter" })).toBeInTheDocument();
  });

  // Fenêtre entièrement hors de la saison de travail : un message, pas un
  // bouton mort inexpliqué (revue #260 round 2).
  it("explains instead of a dead button when the holiday is fully outside the season", () => {
    renderDialog([], { holiday: schoolHoliday({ id: "sh-out", label: "Vacances lointaines", startDate: "2027-10-01", endDate: "2027-10-15" }) });
    expect(screen.getByText(/Hors de la saison en cours/)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Adapter" })).not.toBeInTheDocument();
  });

  // NR #1 : la mère vacances est un ANCRAGE invisible — jamais listée comme entrée
  // supprimable (la vacance scolaire EST déjà l'événement). Les autres entrées, si.
  it("never lists the holiday mother as a deletable entry (invisible anchor, not a phantom event)", () => {
    const mother = entry({ id: "hm", kind: "period", periodType: "holiday", title: "Vacances de Noël", schoolHolidayId: "sh1", startDate: "2026-05-10", endDate: "2026-05-20" });
    const other = entry({ id: "ev", title: "AG du club" });
    renderDialog([mother, other], { holiday: schoolHoliday() });

    expect(screen.queryByRole("button", { name: /Supprimer Vacances de Noël/ })).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Supprimer AG du club" })).toBeInTheDocument();
    expect(screen.getByText("Vacances")).toBeInTheDocument();
  });

  // Revue F2 : sur un jour de vacances « bloc » dont la seule entrée est la mère
  // (masquée), la liste supprimable est vide — mais « Rien ce jour-là » NE doit PAS
  // s'afficher sous le bloc vacances (ce serait se contredire).
  it("never shows the « rien ce jour-là » message on a holiday day whose only entry is the hidden mother", () => {
    const mother = entry({ id: "hm", kind: "period", periodType: "holiday", title: "Vacances de Noël", schoolHolidayId: "sh1", startDate: "2026-05-10", endDate: "2026-05-20" });
    renderDialog([mother], { holiday: schoolHoliday() });

    expect(screen.queryByText(/Rien ce jour-là/)).not.toBeInTheDocument();
    expect(screen.getByText("Vacances")).toBeInTheDocument();
  });

  // NR #5 : plan de saison non validé → l'ajustement d'une vacance est désactivé.
  it("disables « Adapter » while the season plan is not validated (#5)", () => {
    meData = { seasonPlan: { chosenScheduleId: null } };
    renderDialog([], { holiday: schoolHoliday() });

    expect(screen.getByRole("button", { name: "Adapter" })).toBeDisabled();
  });

  // B1 (retour fondateur 2026-07-19) : clic-jour → les plannings couvrants (fermeture
  // + semaine de vacances) sont accessibles en AJUSTER (en cours) / Consulter (validé).
  it("lists the day's covering plannings (closure + holiday week) with AJUSTER / Consulter", async () => {
    allPlansMock = [
      { id: "pl-cl", calendarEntryId: "cl1", chosenScheduleId: null }, // fermeture en cours → Ajuster
      { id: "pl-w1", calendarEntryId: "w1", chosenScheduleId: "sched-9" }, // semaine validée → Consulter
    ];
    renderDialog([
      entry({ id: "cl1", kind: "period", periodType: "closure", title: "Gym fermé" }),
      entry({ id: "w1", kind: "period", periodType: "holiday", parentEntryId: "m1", title: "Toussaint S1" }),
    ]);

    await userEvent.click(screen.getByRole("button", { name: "Ajuster" }));
    expect(startPeriodMode).toHaveBeenCalledWith("cl1");
    expect(navigate).toHaveBeenCalledWith("/wizard");

    await userEvent.click(screen.getByRole("button", { name: "Consulter" }));
    expect(setSelectedScheduleId).toHaveBeenCalledWith("sched-9");
    expect(navigate).toHaveBeenCalledWith("/planning");
  });

  // B1 : après avoir choisi ≥2 semaines, le wizard s'ouvre sur la 1ʳᵉ semaine créée.
  it("opens the wizard on the FIRST created week after picking several weeks", async () => {
    weekChildrenMutate.mockImplementation((_payload: unknown, opts?: { onSuccess?: (r: { created: { id: string }[]; failedCount: number }) => void }) =>
      opts?.onSuccess?.({ created: [{ id: "wk-1" }, { id: "wk-2" }], failedCount: 0 }),
    );
    renderDialog([], { holiday: schoolHoliday() }); // vacances multi-semaines

    await userEvent.click(screen.getByRole("button", { name: "Adapter" })); // ouvre le picker (pending)
    await userEvent.click(screen.getByRole("button", { name: /^Créer les/ })); // confirme les semaines cochées
    await waitFor(() => expect(startPeriodMode).toHaveBeenCalledWith("wk-1"));
    expect(navigate).toHaveBeenCalledWith("/wizard");
  });
});
