import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

type StubSchedule = { id: string; status: string; createdAt: string; planType: string | null; schedulePlanId: string | null };

const h = {
  status: null as string | null,
  diagnostics: [] as unknown[],
  diagLoading: false,
  // Mode d'entrée du wizard + résolution de l'ancre de période (calendarEntryId → plan).
  mode: "season" as "season" | "period",
  entryId: null as string | null,
  planId: null as string | null,
  // La liste des versions (bug fondateur 2026-08-19 — `showPlanning` en dérive en période).
  schedules: [] as StubSchedule[],
  setSelected: vi.fn(),
  // AUD-FRT-09 — spy STABLE : le harnais en recréait un à chaque rendu, donc l'argument
  // du lancement était inobservable. On ne pouvait pas voir ce que l'écran demandait.
  launch: vi.fn().mockResolvedValue("sched-1"),
};

vi.mock("@/features/auth/queries", () => ({ useMe: () => ({ data: { club: { name: "BCCL" } } }) }));
vi.mock("@/features/cockpit/queries", () => ({
  useCalendarEntry: () => ({ data: null === h.entryId ? null : { id: h.entryId } }),
  usePeriodAnchor: () => ("period" === h.mode && null !== h.planId ? { state: "period", planId: h.planId } : { state: "base", planId: null }),
  anchorIsWritable: () => true,
}));
vi.mock("@/features/planning/queries", () => ({
  useSchedules: () => ({ data: h.schedules, isLoading: false }),
  useDiagnostics: () => ({ data: h.diagnostics, isLoading: h.diagLoading }),
}));
vi.mock("@/features/planning/store", () => ({ usePlanningStore: (sel: (s: { setSelectedScheduleId: (id: string | null) => void }) => unknown) => sel({ setSelectedScheduleId: h.setSelected }) }));
// Le stub RECORD la portée reçue (data-scope) : c'est le CÂBLAGE GenerateStep→PlanningPage
// qu'on épingle ici (que la portée passée == le plan de période). Le RENDU scopé lui-même
// — atterrissage, titre, toolbar — est gardé sur le composant RÉEL dans PlanningPage.test.tsx.
vi.mock("@/features/planning/PlanningPage", () => ({ PlanningPage: (props: { embedded?: boolean; scopePlanId?: string | null; calendarEntryId?: string | null }) => <div data-testid="planning" data-scope={props.scopePlanId ?? ""} data-entry={props.calendarEntryId ?? ""} /> }));
vi.mock("@/features/planning/GenerationWaiting", () => ({ GenerationWaiting: () => <div /> }));
vi.mock("../lib/useStepValidation", () => ({ useStepValidation: () => ({ errors: [], warnings: [], pending: false }) }));
vi.mock("../store", () => ({ useWizardStore: () => ({ mode: h.mode, calendarEntryId: h.entryId }) }));
vi.mock("../queries", () => ({
  // Le lancement rend un id → le composant poll useScheduleStatus, que le
  // harnais pilote via h.status (FAILED = échec de SOLVE).
  useLaunchGeneration: () => ({ isPending: false, isError: false, mutateAsync: h.launch, reset: vi.fn() }),
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

// Reset GLOBAL du harnais : la période / le plan / les versions ne doivent pas fuir d'un
// test à l'autre (les describes historiques posent leurs propres resets — celui-ci couvre
// les champs qu'ils ne touchent pas, pour tout cas d'ordre d'exécution).
beforeEach(() => {
  h.status = null;
  h.diagnostics = [];
  h.diagLoading = false;
  h.mode = "season";
  h.entryId = null;
  h.planId = null;
  h.schedules = [];
  h.setSelected.mockClear();
  h.launch.mockClear();
});

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
    h.launch.mockClear();
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

/**
 * AUD-FRT-09 — « Réessayer » ne doit pas empiler une version de plus.
 *
 * Le mode période réutilisait déjà l'overlay en vol ; le mode saison créait une version
 * neuve à CHAQUE clic. Pas de corruption — l'unicité du socle est tenue par un 409
 * serveur — mais un historique de versions FAILED que le gestionnaire doit trier, et qui
 * grossit précisément quand ça va mal.
 *
 * ⚠ Le test vérifie les DEUX sens, parce qu'ils se contredisent : premier lancement =
 * version neuve (sinon on écraserait), reprise après échec = même version. Un test qui
 * n'épinglerait que la reprise laisserait passer « réutiliser toujours ».
 */
describe("GenerateStep — la reprise après échec ne crée pas de version (AUD-FRT-09)", () => {
  beforeEach(() => {
    h.status = null;
    h.diagnostics = [];
    h.diagLoading = false;
    h.launch.mockClear();
  });

  it("premier lancement : version neuve ; reprise après FAILED : la même", async () => {
    const user = userEvent.setup();
    const { rerender } = renderStep();

    await user.click(screen.getByRole("button", { name: /Lancer la génération/ }));
    expect(h.launch).toHaveBeenCalledWith({ existingScheduleId: undefined });

    // Le solve échoue : l'écran passe en échec, et le scheduleId local pointe la version.
    h.status = "FAILED";
    rerender(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <GenerateStep />
      </QueryClientProvider>,
    );

    await user.click(await screen.findByRole("button", { name: /Réessayer/ }));
    expect(h.launch).toHaveBeenLastCalledWith({ existingScheduleId: "sched-1" });
  });
});

/**
 * bug fondateur 2026-08-19 — l'étape Génération d'une ADAPTATION de période doit :
 *  1) montrer l'écran embarqué dès qu'une version de LA PÉRIODE existe (terminée OU en vol),
 *     même au RETOUR sur l'étape sans scheduleId local — sinon un écran « Générer » vierge
 *     masquait les versions déjà générées ;
 *  2) PORTER cet écran sur le plan de la période (`scopePlanId`), pas sur le socle.
 * On épingle le CÂBLAGE (portée passée + condition d'affichage) ; le rendu scopé lui-même
 * vit sur le composant réel dans PlanningPage.test.tsx.
 */
describe("GenerateStep — mode période : l'écran embarqué est scopé au plan de période", () => {
  const overlay = (id: string, status: string, createdAt: string): StubSchedule => ({ id, status, createdAt, planType: "CLOSURE", schedulePlanId: "plan-p" });

  beforeEach(() => {
    h.mode = "period";
    h.entryId = "entry-1";
    h.planId = "plan-p";
  });

  it("de retour sur l'étape avec 2 versions COMPLETED de la période (aucun scheduleId local) : l'écran embarqué s'affiche, porté sur le plan de période", () => {
    // RED avant le fix : `overlayDone` dérivait du statut LOCAL (nul ici) → showPlanning
    // faux → le lanceur restait affiché, l'écran embarqué absent.
    h.schedules = [overlay("ov-1", "COMPLETED", "2026-09-10T00:00:00Z"), overlay("ov-2", "COMPLETED", "2026-09-11T00:00:00Z")];
    renderStep();

    expect(screen.getByTestId("planning")).toHaveAttribute("data-scope", "plan-p");
    // P2-43 volet (v) — l'entrée de calendrier de la période est passée : PlanningPage y lit
    // l'état de fermeture des gymnases pour MARQUER les fenêtres vides fermées.
    expect(screen.getByTestId("planning")).toHaveAttribute("data-entry", "entry-1");
    expect(screen.queryByRole("button", { name: /Générer le planning de période/i })).not.toBeInTheDocument();
  });

  it("une version EN VOL de la période affiche aussi l'écran embarqué porté (retour mi-génération)", () => {
    h.schedules = [overlay("ov-1", "GENERATING", "2026-09-10T00:00:00Z")];
    renderStep();

    expect(screen.getByTestId("planning")).toHaveAttribute("data-scope", "plan-p");
  });

  it("plan de période SANS version : le lanceur reste, jamais l'écran embarqué (aucun repli saison)", () => {
    // Une version de SAISON existe (le repli fautif d'avant), mais pas de version de la période.
    h.schedules = [{ id: "season-v1", status: "COMPLETED", createdAt: "2026-01-01T00:00:00Z", planType: "SEASON", schedulePlanId: "season-plan" }];
    renderStep();

    expect(screen.queryByTestId("planning")).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Générer le planning de période/i })).toBeInTheDocument();
  });

  it("la portée ne dépend pas de la largeur : un enfant-SEGMENT scope au même plan qu'un mono-semaine", () => {
    // Enfant-segment (autre entryId) — l'ancre résout le MÊME type de plan de période :
    // la portée se dérive du plan, pas de la largeur de la fenêtre.
    h.entryId = "segment-3";
    h.schedules = [overlay("ov-seg", "COMPLETED", "2026-09-10T00:00:00Z")];
    renderStep();

    expect(screen.getByTestId("planning")).toHaveAttribute("data-scope", "plan-p");
  });
});

/**
 * bug fondateur 2026-08-19 (trou antérieur) — le VERDICT d'échec doit survivre au retour sur
 * l'étape. `failed`/`failedDiagnostics`/`waiting` dérivaient du `scheduleId` LOCAL, nul au
 * remontage : un run FAILED redevenait un lanceur MUET, le motif du moteur perdu. On les fait
 * dériver du dernier run FAILED du plan en portée, lu de la LISTE (saison comprise).
 */
describe("GenerateStep — un run FAILED survit au retour sur l'étape (dérivé de la LISTE)", () => {
  const errDiag = [{ id: "d1", severity: "ERROR", message: "Le planning n'a pas pu être généré : capacité insuffisante.", suggestions: ["Ajoutez de la disponibilité de gymnase."] }];

  beforeEach(() => {
    h.status = null; // aucun run LOCAL ce montage — c'est tout l'enjeu
    h.diagnostics = errDiag;
  });

  it("SAISON : un run FAILED en liste (sans scheduleId local) rouvre l'écran d'échec expliqué, pas le lanceur", () => {
    // RED avant le fix : failed dérivait du statut local (nul) → le lanceur restait, muet.
    h.mode = "season";
    h.schedules = [{ id: "s-fail", status: "FAILED", createdAt: "2026-02-01T00:00:00Z", planType: "SEASON", schedulePlanId: "season-plan" }];
    renderStep();

    expect(screen.getByText(/n'a pas abouti/)).toBeInTheDocument();
    expect(screen.getByText(/capacité insuffisante/)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Lancer la génération/i })).not.toBeInTheDocument();
  });

  it("PÉRIODE : le run FAILED de la période en liste rouvre l'écran d'échec au retour", () => {
    h.mode = "period";
    h.entryId = "entry-1";
    h.planId = "plan-p";
    h.schedules = [{ id: "p-fail", status: "FAILED", createdAt: "2026-09-10T00:00:00Z", planType: "CLOSURE", schedulePlanId: "plan-p" }];
    renderStep();

    expect(screen.getByText(/n'a pas abouti/)).toBeInTheDocument();
    expect(screen.getByText(/capacité insuffisante/)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Générer le planning de période/i })).not.toBeInTheDocument();
  });
});

describe("GenerateStep — mode saison : rien ne change (NR)", () => {
  it("l'écran embarqué de saison n'a pas de portée (scopePlanId nul)", () => {
    h.mode = "season";
    h.schedules = [{ id: "s-v1", status: "COMPLETED", createdAt: "2026-01-01T00:00:00Z", planType: "SEASON", schedulePlanId: "season-plan" }];
    renderStep();

    expect(screen.getByTestId("planning")).toHaveAttribute("data-scope", "");
    // En saison, aucune entrée de période n'est passée (pas de fermetures de période à lire).
    expect(screen.getByTestId("planning")).toHaveAttribute("data-entry", "");
  });
});
