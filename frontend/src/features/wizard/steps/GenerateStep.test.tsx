import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

const h = {
  status: null as string | null,
  diagnostics: [] as unknown[],
  diagLoading: false,
};

vi.mock("@/features/auth/queries", () => ({ useMe: () => ({ data: { club: { name: "BCCL" } } }) }));
vi.mock("@/features/cockpit/queries", () => ({
  useCalendarEntry: () => ({ data: null }),
  usePeriodAnchor: () => ({ state: "base", planId: null }),
  anchorIsWritable: () => true,
}));
vi.mock("@/features/planning/queries", () => ({
  useSchedules: () => ({ data: [], isLoading: false }),
  useDiagnostics: () => ({ data: h.diagnostics, isLoading: h.diagLoading }),
}));
vi.mock("@/features/planning/store", () => ({ usePlanningStore: (sel: (s: { setSelectedScheduleId: () => void }) => unknown) => sel({ setSelectedScheduleId: () => {} }) }));
vi.mock("@/features/planning/PlanningPage", () => ({ PlanningPage: () => <div /> }));
vi.mock("@/features/planning/GenerationWaiting", () => ({ GenerationWaiting: () => <div /> }));
vi.mock("../lib/useStepValidation", () => ({ useStepValidation: () => ({ errors: [], warnings: [], pending: false }) }));
vi.mock("../store", () => ({ useWizardStore: () => ({ mode: "season", calendarEntryId: null }) }));
vi.mock("../queries", () => ({
  // Le lancement rend un id → le composant poll useScheduleStatus, que le
  // harnais pilote via h.status (FAILED = échec de SOLVE).
  useLaunchGeneration: () => ({ isPending: false, isError: false, mutateAsync: vi.fn().mockResolvedValue("sched-1"), reset: vi.fn() }),
  useScheduleStatus: () => ({ data: null === h.status ? undefined : { status: h.status } }),
}));

import { GenerateStep } from "./GenerateStep";

function renderStep() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <GenerateStep />
    </QueryClientProvider>,
  );
}

/**
 * NR (retour fondateur 2026-08-05, « en prod ça ne passera pas ») : un solve
 * FAILED a ses DIAGNOSTICS en base — l'écran d'échec doit les MONTRER, jamais
 * le générique « une erreur est survenue » qui laissait le gestionnaire sans
 * rien. ⚠ Le composant n'entre en échec de solve que si un scheduleId local
 * existe : on ne teste ici que le RENDU (les hooks sont mockés), pas le poll.
 */
describe("GenerateStep — écran d'échec expliqué", () => {
  beforeEach(() => {
    h.status = null;
    h.diagnostics = [];
    h.diagLoading = false;
  });

  it("le launcher s'affiche au repos (pas d'échec fantôme)", () => {
    renderStep();
    expect(screen.getByRole("button", { name: /Lancer la génération/i })).toBeInTheDocument();
    expect(screen.queryByText(/n'a pas abouti/)).not.toBeInTheDocument();
  });

  it("un solve FAILED montre le MOTIF du moteur et ses pistes — jamais le générique", async () => {
    h.status = "FAILED";
    h.diagnostics = [
      {
        id: "d1",
        severity: "ERROR",
        message: "Le planning n'a pas pu être généré : il faut placer 84 séances pour 82 créneaux.",
        suggestions: ["Ajoutez de la disponibilité de gymnase.", "Assouplissez une contrainte dure."],
      },
      { id: "d2", severity: "INFO", message: "détail secondaire", suggestions: null },
    ];
    // Le mock renvoie FAILED dès le montage : l'échec de solve s'affiche sans
    // passer par le bouton (c'est le RENDU qu'on épingle, pas le poll).
    renderStep();

    expect(await screen.findByText(/n'a pas abouti/)).toBeInTheDocument();
    expect(screen.getByText(/84 séances pour 82 créneaux/)).toBeInTheDocument();
    expect(screen.getByText("Ajoutez de la disponibilité de gymnase.")).toBeInTheDocument();
    // Le générique ne double jamais un motif réel, et l'INFO ne pollue pas.
    expect(screen.queryByText(/Une erreur est survenue/)).not.toBeInTheDocument();
    expect(screen.queryByText("détail secondaire")).not.toBeInTheDocument();
  });

  it("FAILED sans diagnostic lisible → repli générique (jamais un écran muet)", async () => {
    h.status = "FAILED";
    h.diagnostics = [];
    renderStep();

    expect(await screen.findByText(/n'a pas abouti/)).toBeInTheDocument();
    expect(screen.getByText(/Une erreur est survenue/)).toBeInTheDocument();
  });
});
