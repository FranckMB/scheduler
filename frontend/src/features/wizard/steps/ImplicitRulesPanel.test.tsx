import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import type { ReactElement } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import type { ImplicitRuleSetting } from "../api";
import * as wizardApi from "../api";
import { WellbeingRulesPanel } from "./ImplicitRulesPanel";

vi.mock("../api", () => ({
  listImplicitRuleSettings: vi.fn(),
  updateImplicitRuleSetting: vi.fn(() => Promise.resolve({})),
  resetImplicitRuleSetting: vi.fn(() => Promise.resolve()),
}));

const workingSeason = vi.hoisted(() => ({ value: { isReadonly: false } as { isReadonly: boolean } | null }));
vi.mock("@/features/auth/queries", () => ({ useWorkingSeason: () => workingSeason.value }));

const RESOLVED = (over: Partial<Record<ImplicitRuleSetting["ruleKey"], Partial<ImplicitRuleSetting>>> = {}): ImplicitRuleSetting[] =>
  [
    { ruleKey: "coachRestDay", intensity: "HARD", minRestDays: 1, maxConsecutive: null, isDefault: true },
    { ruleKey: "salarieDistribution", intensity: "HARD", minRestDays: null, maxConsecutive: null, isDefault: true },
    { ruleKey: "maxConsecutiveSessions", intensity: "HARD", minRestDays: null, maxConsecutive: 3, isDefault: true },
    { ruleKey: "ageAscending", intensity: "HARD", minRestDays: null, maxConsecutive: null, isDefault: true },
  ].map((s) => ({ ...s, ...over[s.ruleKey as ImplicitRuleSetting["ruleKey"]] }) as ImplicitRuleSetting);

function renderPanel(ui: ReactElement) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(<QueryClientProvider client={queryClient}>{ui}</QueryClientProvider>);
}

const REST_TITLE = "Chaque coach garde un jour de repos";
const CONSEC_TITLE = "Jamais trop de créneaux dos-à-dos";

/**
 * P2-28 — les règles bien-être RÉGLABLES. « Muter la PROD, pas le mock » : on mocke la couche
 * réseau (`../api`) et on garde les vrais hooks react-query, si bien que l'invalidation se PROUVE
 * par un refetch (le GET rappelé), pas par une assertion sur un double.
 */
describe("ImplicitRulesPanel — règles de bien-être réglables", () => {
  beforeEach(() => {
    vi.mocked(wizardApi.listImplicitRuleSettings).mockResolvedValue(RESOLVED());
    vi.mocked(wizardApi.updateImplicitRuleSetting).mockClear().mockResolvedValue({} as ImplicitRuleSetting);
    vi.mocked(wizardApi.resetImplicitRuleSetting).mockClear().mockResolvedValue(undefined);
    workingSeason.value = { isReadonly: false };
  });
  afterEach(() => vi.mocked(wizardApi.listImplicitRuleSettings).mockReset());

  it("rend les 4 règles réglables une fois la collection résolue", async () => {
    renderPanel(<WellbeingRulesPanel />);

    expect(await screen.findByRole("group", { name: `Intensité — ${REST_TITLE}` })).toBeInTheDocument();
    for (const title of [REST_TITLE, "Au moins un salarié présent chaque jour ouvré", CONSEC_TITLE, "Les jeunes avant les grands"]) {
      expect(screen.getByRole("group", { name: `Intensité — ${title}` })).toBeInTheDocument();
    }
  });

  it("« Objectif » PUT PREFERRED en préservant le seuil, puis refetch (invalidation)", async () => {
    const user = userEvent.setup();
    renderPanel(<WellbeingRulesPanel />);
    await screen.findByRole("group", { name: `Intensité — ${REST_TITLE}` });

    await user.click(screen.getByRole("button", { name: `Objectif — ${REST_TITLE}` }));

    await waitFor(() => expect(wizardApi.updateImplicitRuleSetting).toHaveBeenCalledWith("coachRestDay", { intensity: "PREFERRED", minRestDays: 1 }));
    // L'effet de l'invalidation : le GET est rappelé (au moins 2 fois).
    await waitFor(() => expect(vi.mocked(wizardApi.listImplicitRuleSettings).mock.calls.length).toBeGreaterThanOrEqual(2));
  });

  it("changer le seuil PUT la nouvelle valeur avec l'intensité courante", async () => {
    const user = userEvent.setup();
    renderPanel(<WellbeingRulesPanel />);
    await screen.findByRole("group", { name: `Intensité — ${REST_TITLE}` });

    await user.selectOptions(screen.getByRole("combobox", { name: `Jours de repos minimum — ${REST_TITLE}` }), "3");

    await waitFor(() => expect(wizardApi.updateImplicitRuleSetting).toHaveBeenCalledWith("coachRestDay", { intensity: "HARD", minRestDays: 3 }));
  });

  it("le seuil est BORNÉ : repos 1-4, consécutifs 2-6 (le sélecteur n'offre que ça)", async () => {
    renderPanel(<WellbeingRulesPanel />);
    await screen.findByRole("group", { name: `Intensité — ${REST_TITLE}` });

    const rest = screen.getByRole("combobox", { name: `Jours de repos minimum — ${REST_TITLE}` });
    expect(Array.from(rest.querySelectorAll("option")).map((o) => o.textContent)).toEqual(["1", "2", "3", "4"]);

    const consec = screen.getByRole("combobox", { name: `Créneaux consécutifs maximum — ${CONSEC_TITLE}` });
    expect(Array.from(consec.querySelectorAll("option")).map((o) => o.textContent)).toEqual(["2", "3", "4", "5", "6"]);
  });

  it("« Réinitialiser » n'apparaît qu'hors défaut et DELETE la règle, puis refetch", async () => {
    vi.mocked(wizardApi.listImplicitRuleSettings).mockResolvedValue(RESOLVED({ coachRestDay: { isDefault: false, intensity: "PREFERRED" } }));
    const user = userEvent.setup();
    renderPanel(<WellbeingRulesPanel />);
    await screen.findByRole("group", { name: `Intensité — ${REST_TITLE}` });

    // Une seule règle hors défaut → un seul bouton « Réinitialiser ».
    const resetButtons = screen.getAllByRole("button", { name: "Réinitialiser" });
    expect(resetButtons).toHaveLength(1);

    await user.click(resetButtons[0]);
    await waitFor(() => expect(wizardApi.resetImplicitRuleSetting).toHaveBeenCalledWith("coachRestDay"));
    await waitFor(() => expect(vi.mocked(wizardApi.listImplicitRuleSettings).mock.calls.length).toBeGreaterThanOrEqual(2));
  });

  it("aucune règle hors défaut → aucun bouton « Réinitialiser »", async () => {
    renderPanel(<WellbeingRulesPanel />);
    await screen.findByRole("group", { name: `Intensité — ${REST_TITLE}` });
    expect(screen.queryByRole("button", { name: "Réinitialiser" })).toBeNull();
  });

  it("saison archivée : intensité, seuil et réinitialiser sont DÉSACTIVÉS", async () => {
    workingSeason.value = { isReadonly: true };
    vi.mocked(wizardApi.listImplicitRuleSettings).mockResolvedValue(RESOLVED({ coachRestDay: { isDefault: false } }));
    renderPanel(<WellbeingRulesPanel />);
    await screen.findByRole("group", { name: `Intensité — ${REST_TITLE}` });

    expect(screen.getByRole("button", { name: `Objectif — ${REST_TITLE}` })).toBeDisabled();
    expect(screen.getByRole("combobox", { name: `Jours de repos minimum — ${REST_TITLE}` })).toBeDisabled();
    expect(screen.getByRole("button", { name: "Réinitialiser" })).toBeDisabled();
  });

  it("un échec de lecture le DIT (pas un panneau faussement vide)", async () => {
    vi.mocked(wizardApi.listImplicitRuleSettings).mockRejectedValue(new Error("réseau"));
    renderPanel(<WellbeingRulesPanel />);
    expect(await screen.findByText(/Impossible de lire les réglages des règles de bien-être/)).toBeInTheDocument();
  });
});

/**
 * P2-28 — l'atterrissage d'un diagnostic : `ruleTarget` surligne la règle et l'amène à l'écran
 * (patron `data-*` + `scrollIntoView` en `requestAnimationFrame`, comme P4-95). L'ouverture de
 * l'onglet Bien-être est gérée par `ConstraintsStep` ; ici on garde le surlignage + le scroll.
 */
describe("ImplicitRulesPanel — atterrissage ciblé (ruleTarget)", () => {
  let scrolled: Element[];
  let originalScroll: typeof Element.prototype.scrollIntoView;

  beforeEach(() => {
    vi.mocked(wizardApi.listImplicitRuleSettings).mockResolvedValue(RESOLVED());
    workingSeason.value = { isReadonly: false };
    scrolled = [];
    originalScroll = Element.prototype.scrollIntoView;
    Element.prototype.scrollIntoView = function (this: Element) {
      scrolled.push(this);
    };
  });
  afterEach(() => {
    Element.prototype.scrollIntoView = originalScroll;
    vi.mocked(wizardApi.listImplicitRuleSettings).mockReset();
  });

  it("surligne la règle ciblée et la scrolle (block center)", async () => {
    const raf = vi.spyOn(window, "requestAnimationFrame").mockImplementation((cb) => (cb(0), 0));
    const { container } = renderPanel(<WellbeingRulesPanel ruleTarget="maxConsecutiveSessions" />);
    await screen.findByRole("group", { name: `Intensité — ${CONSEC_TITLE}` });

    const row = container.querySelector('[data-rule-key="maxConsecutiveSessions"]');
    expect(row?.className).toContain("ring-accent");
    expect(scrolled).toContain(row);
    raf.mockRestore();
  });

  it("ruleTarget inconnu → rien de surligné, aucun scroll, aucun crash", async () => {
    const { container } = renderPanel(<WellbeingRulesPanel ruleTarget="pasUneRegle" />);
    await screen.findByRole("group", { name: `Intensité — ${CONSEC_TITLE}` });

    expect(container.querySelector('[data-rule-key="maxConsecutiveSessions"]')?.className).not.toContain("ring-accent");
    expect(scrolled).toEqual([]);
  });
});
