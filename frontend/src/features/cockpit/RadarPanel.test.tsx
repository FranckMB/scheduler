import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { CalendarEntry, SchedulePlan, SchoolHoliday } from "./api";
import { addDays, todayISO } from "./lib/date";
import { RadarPanel } from "./RadarPanel";

const createHolidayMutate = vi.fn();
let conflictsData: { conflicts: { dates: string[] }[]; seasonPlanChosen?: boolean } | undefined;
let conflictsPending = false;
// ADR-0002 lot D-b : le radar dérive « version active » du plan de la période
// (chosenScheduleId), plus d'un pointeur sur l'entrée.
let plansData: SchedulePlan[] = [];
let plansLoading = false;

vi.mock("./queries", () => ({
  useCreateHolidayPeriod: () => ({ mutate: createHolidayMutate, isPending: false }),
  useEntryConflicts: () => ({ data: conflictsData }),
  // Le parent lit l'impact de TOUTES les fermetures pour masquer celles qui ne
  // demandent rien — même donnée que la carte enfant (le cache dédoublonne).
  useEntryConflictsList: (ids: string[]) => ids.map(() => ({ data: conflictsData, isPending: conflictsPending })),
  useSchedulePlans: () => ({ data: plansLoading ? undefined : plansData, isLoading: plansLoading }),
}));

/** Un plan de période VALIDÉ (chosenScheduleId non-null) pour l'entrée donnée. */
const validatedPlan = (calendarEntryId: string, chosenScheduleId: string): SchedulePlan => ({
  id: `pl-${calendarEntryId}`,
  type: "CLOSURE",
  name: "Plan",
  calendarEntryId,
  chosenScheduleId,
  teamSelectionInitialized: false,
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

describe("RadarPanel", () => {
  beforeEach(() => {
    createHolidayMutate.mockReset();
    conflictsData = undefined;
    conflictsPending = false;
    plansData = [];
    plansLoading = false;
  });

  it("asks for the school zone when unknown", () => {
    renderRadar({ zone: null });
    expect(screen.getByText("Zone scolaire à renseigner")).toBeInTheDocument();
  });

  it("does not flash the zone card while holidays are loading", () => {
    renderRadar({ zone: null, zoneLoading: true });
    expect(screen.queryByText("Zone scolaire à renseigner")).not.toBeInTheDocument();
  });

  it("offers to adapt an upcoming school holiday (never auto-applies)", async () => {
    createHolidayMutate.mockImplementation((_vars, opts) => opts?.onSuccess?.({ id: "created" }));
    renderRadar({ holidays: [holiday] });

    expect(screen.getByText("Vacances de Noël")).toBeInTheDocument();
    expect(screen.getByText(/pas de planning/)).toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: "Adapter" }));
    expect(createHolidayMutate).toHaveBeenCalledWith(
      { schoolHolidayId: "h1", label: "Vacances de Noël", startDate: FUTURE, endDate: FUTURE_END },
      expect.anything(),
    );
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

  it("fail-closed: while plans are loading, neither flashes the all-clear nor offers a misleading 'Adapter'", () => {
    // État d'une période INCONNU tant que les plans chargent : ne pas dire « tout roule »
    // (masquerait une fermeture validée) ni proposer « Adapter » (régénérerait un plan validé).
    plansLoading = true;
    renderRadar({ entries: [closure({ title: "Gymnase fermé" })] });

    expect(screen.queryByText("Rien à l'horizon. Tout roule.")).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Adapter" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Voir le planning" })).not.toBeInTheDocument();
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
