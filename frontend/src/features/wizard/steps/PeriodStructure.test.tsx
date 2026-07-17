import { fireEvent, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, describe, expect, it, vi } from "vitest";

import type { Constraint } from "../api";

const createOverride = vi.fn();
const updateOverride = vi.fn();
const deleteOverride = vi.fn();
const createSlot = vi.fn();
const deleteSlot = vi.fn();
const createConstraintOverride = vi.fn();
const updateConstraintOverride = vi.fn();
const deleteConstraintOverride = vi.fn();
const overridesState: { data: Array<{ id: string; teamId: string; isActive: boolean; sessionsPerWeek: number | null; schedulePlanId: string }> } = { data: [] };
// Le VRAI type, pas une copie étroite : une seconde description du même objet finit
// toujours par diverger — celle d'avant ignorait family/config/isActive, que les tests
// envoient pourtant. Le helper porte les défauts, chaque test ne dit que ce qui compte.
const constraintsState: { data: Constraint[] } = { data: [] };

const constraint = (over: Partial<Constraint> & Pick<Constraint, "id" | "name">): Constraint => ({
  scope: "CLUB",
  scopeTargetId: null,
  family: "TIME",
  ruleType: "PREFERRED",
  config: {},
  isActive: true,
  ...over,
});
const constraintOverridesState: { data: Array<{ id: string; constraintId: string; isActive: boolean; schedulePlanId: string }> } = { data: [] };
const tagsState: { data: Array<{ id: string; name: string; color: string | null; isSystem: boolean; axis: "GENRE" | "NIVEAU" | "AGE" | null }> } = { data: [] };
const tagAssignmentsState: { data: Array<{ id: string; teamId: string; tagId: string; seasonId: string }> } = { data: [] };
const teamOverridesLoadingState = { value: false };
const teamOverridesErrorState = { value: false };
const constraintOverridesLoadingState = { value: false };
const constraintOverridesErrorState = { value: false };
const conflictState: { venueIds: string[] } = { venueIds: [] };
const entryState: { data: { periodType: string } | undefined } = { data: { periodType: "closure" } };
// ADR-0002 lot C: le garde de seed vit sur le PLAN, pas sur l'événement calendrier.
// Les ancres REÇUES par les hooks de lecture. Sans les capturer, aucun test ne peut voir
// qu'un composant lit par le déclencheur au lieu du plan : les deux sont des `string`, tsc
// est muet, et l'API répondrait 200 avec une liste vide — panne silencieuse (lot C2).
const teamOverridesAnchor: { value: string | null } = { value: null };
const periodSlotsAnchor: { value: string | null } = { value: null };
const periodSlotWriteAnchor: { value: string | null } = { value: null };
const constraintOverridesAnchor: { value: string | null } = { value: null };
const planState: { data: { id: string; teamSelectionInitialized: boolean } | null | undefined } = { data: { id: "plan-1", teamSelectionInitialized: false } };

vi.mock("../queries", () => ({
  useWizardTeams: () => ({ data: [
    { id: "t1", name: "SM1", sportCategoryId: "c", priorityTierId: 1, tierOrder: 0, gender: null, level: null, sessionsPerWeek: 2, isActive: true },
    { id: "t2", name: "U13", sportCategoryId: "c", priorityTierId: 2, tierOrder: 0, gender: null, level: null, sessionsPerWeek: 1, isActive: true },
  ] }),
  usePriorityTiers: () => ({ data: [{ id: 1, label: "S", name: "Fanion", color: null }, { id: 2, label: "A", name: "Importante", color: null }] }),
  useTeamPeriodOverrides: (anchor: string | null) => {
    teamOverridesAnchor.value = anchor;

    return { data: overridesState.data, isLoading: teamOverridesLoadingState.value, isError: teamOverridesErrorState.value };
  },
  useCreateTeamPeriodOverride: () => ({ mutate: createOverride, mutateAsync: createOverride, isPending: false }),
  useUpdateTeamPeriodOverride: () => ({ mutate: updateOverride, mutateAsync: updateOverride, isPending: false }),
  useDeleteTeamPeriodOverride: () => ({ mutate: deleteOverride, mutateAsync: deleteOverride, isPending: false }),
  useWizardVenues: () => ({ data: [{ id: "v1", name: "Gymnase A", color: "#ff0000", canSplit: false, isActive: true }] }),
  useVenueSlots: () => ({ data: [] }),
  usePeriodSlots: (anchor: string | null) => {
    periodSlotsAnchor.value = anchor;

    return { data: [{ id: "ps1", venueId: "v1", dayOfWeek: 3, startTime: "20:00:00", durationMinutes: 90, capacity: 1, schedulePlanId: "plan-1" }] };
  },
  useCreatePeriodSlot: (anchor: string | null) => {
    periodSlotWriteAnchor.value = anchor;

    return { mutate: createSlot, isPending: false };
  },
  useDeletePeriodSlot: () => ({ mutate: deleteSlot, isPending: false }),
  useWizardConstraints: () => ({ data: constraintsState.data, isLoading: false }),
  usePeriodConstraintOverrides: (anchor: string | null) => {
    constraintOverridesAnchor.value = anchor;

    return { data: constraintOverridesState.data, isLoading: constraintOverridesLoadingState.value, isError: constraintOverridesErrorState.value };
  },
  useWizardTeamTags: () => ({ data: tagsState.data, isLoading: false, isError: false }),
  useWizardTeamTagAssignments: () => ({ data: tagAssignmentsState.data, isLoading: false, isError: false }),
  useCreatePeriodConstraintOverride: () => ({ mutate: createConstraintOverride, isPending: false }),
  useUpdatePeriodConstraintOverride: () => ({ mutate: updateConstraintOverride, isPending: false }),
  useDeletePeriodConstraintOverride: () => ({ mutate: deleteConstraintOverride, isPending: false }),
}));
vi.mock("@/features/cockpit/queries", () => ({
  useEntryConflicts: () => ({ data: { venueIds: conflictState.venueIds } }),
  useCalendarEntry: () => ({ data: entryState.data, isLoading: false }),
  useSchedulePlanForEntry: () => ({ data: planState.data, isLoading: false }),
  // Dérivé de planState : un test qui met planState.data à undefined simule le plan pas
  // encore résolu, et `ready` bascule — c'est ce que le NR d'écriture exerce.
  usePeriodAnchor: (calendarEntryId: string | null) => {
    const planId = planState.data?.id ?? null;

    return { planId, ready: null === calendarEntryId || null !== planId, isLoading: false };
  },
}));
vi.mock("@/shared/stores/toastStore", () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

import { toast } from "@/shared/stores/toastStore";

import { PeriodConstraints, PeriodTeams, PeriodVenues } from "./PeriodStructure";
import { resetPeriodSeed } from "./periodSeed";

afterEach(() => {
  // Les vi.fn() ACCUMULENT leurs appels d'un test à l'autre : sans ça, un
  // `not.toHaveBeenCalled` voit l'appel d'un test précédent et échoue à tort (ou pire,
  // un `toHaveBeenCalled` passe grâce à lui).
  vi.clearAllMocks();
  resetPeriodSeed();
  overridesState.data = [];
  constraintsState.data = [];
  constraintOverridesState.data = [];
  tagsState.data = [];
  tagAssignmentsState.data = [];
  teamOverridesLoadingState.value = false;
  teamOverridesErrorState.value = false;
  constraintOverridesLoadingState.value = false;
  constraintOverridesErrorState.value = false;
  conflictState.venueIds = [];
  entryState.data = { periodType: "closure" };
    planState.data = { id: "plan-1", teamSelectionInitialized: false };
  createOverride.mockClear();
  updateOverride.mockClear();
  deleteOverride.mockClear();
  createSlot.mockClear();
  deleteSlot.mockClear();
  createConstraintOverride.mockClear();
  updateConstraintOverride.mockClear();
  deleteConstraintOverride.mockClear();
});

describe("PeriodTeams — Fanion-only default + toggles", () => {
  it("seeds a fresh period with only the top tier active (deactivates the rest)", () => {
    // Unique id: the module-level "already seeded" set persists across tests.
    render(<PeriodTeams calendarEntryId="fresh-seed" />);
    // U13 (tier 2) is deactivated by default; SM1 (Fanion) is not touched.
    expect(createOverride).toHaveBeenCalledWith({ schedulePlanId: "plan-1", teamId: "t2", isActive: false });
    expect(createOverride).toHaveBeenCalledTimes(1);
  });

  it("does NOT seed a period already configured server-side (teamSelectionInitialized)", () => {
    planState.data = { id: "plan-1", teamSelectionInitialized: true }; // e.g. manager reset it to all-active, then reloaded
    render(<PeriodTeams calendarEntryId="already-init" />);
    expect(createOverride).not.toHaveBeenCalled();
  });

  it("does NOT re-seed on a re-render (idempotent — guards the removed retry double-write)", () => {
    const { rerender } = render(<PeriodTeams calendarEntryId="fresh-seed" />);
    expect(createOverride).toHaveBeenCalledTimes(1);
    // A re-render (effect re-runs) must not fire a second seed for the claimed period.
    rerender(<PeriodTeams calendarEntryId="fresh-seed" />);
    expect(createOverride).toHaveBeenCalledTimes(1);
  });

  it("« Tout le club » activates every team", async () => {
    overridesState.data = [{ id: "o2", teamId: "t2", isActive: false, sessionsPerWeek: null, schedulePlanId: "plan-1" }];
    render(<PeriodTeams calendarEntryId="e1" />);
    await userEvent.click(screen.getByRole("button", { name: "Tout le club" }));
    // The deactivated U13 override is removed → back to seasonal (active).
    expect(deleteOverride).toHaveBeenCalledWith("o2");
  });
});

describe("PeriodTeams — session guard", () => {
  it("ignores an emptied sessions field instead of persisting 0", async () => {
    overridesState.data = [{ id: "o2", teamId: "t2", isActive: false, sessionsPerWeek: null, schedulePlanId: "plan-1" }]; // suppress the seed
    render(<PeriodTeams calendarEntryId="e1" />);
    const input = screen.getByLabelText("Séances de SM1 cette période");
    await userEvent.clear(input); // → Number("") === 0
    expect(createOverride).not.toHaveBeenCalled();
    expect(updateOverride).not.toHaveBeenCalled();
  });
});

describe("PeriodVenues — borrowed period slots", () => {
  it("lists the period's borrowed slots and adds a new one", async () => {
    render(<PeriodVenues calendarEntryId="e1" />);
    expect(screen.getByText(/Gymnase A — Mer 20:00/)).toBeInTheDocument();

    await userEvent.selectOptions(screen.getByLabelText("Gymnase"), "v1");
    await userEvent.click(screen.getByRole("button", { name: "Ajouter" }));
    expect(createSlot).toHaveBeenCalledWith(expect.objectContaining({ venueId: "v1", dayOfWeek: 1, capacity: 1 }), expect.anything());
  });

  it("marks a gym closed for the period as INTERDIT", () => {
    conflictState.venueIds = ["v1"];
    render(<PeriodVenues calendarEntryId="e1" />);
    expect(screen.getByText(/INTERDIT cette période/)).toBeInTheDocument();
  });
});

describe("PeriodStructure — l'ancre des réglages (ADR-0002 inv. 5, lot C2)", () => {
  // CE test est celui qui manquait : les deux composants lisaient par le DÉCLENCHEUR
  // pendant qu'ils écrivaient par le PLAN. Les deux ids sont des `string` → tsc muet,
  // et l'API répond 200 avec une liste vide → checklist silencieusement fausse, cases
  // qui reviennent au rechargement, 422 au re-clic. Aucun test ne pouvait le voir : les
  // mocks jetaient leur argument.
  it("PeriodTeams lit ses réglages par le PLAN, jamais par la période", () => {
    render(<PeriodTeams calendarEntryId="e1" />);
    expect(teamOverridesAnchor.value).toBe("plan-1");
  });

  it("PeriodConstraints lit ses deux jeux de réglages par le PLAN", () => {
    render(<PeriodConstraints calendarEntryId="e1" />);
    expect(constraintOverridesAnchor.value).toBe("plan-1");
    expect(teamOverridesAnchor.value).toBe("plan-1");
  });

  // Le NR de C2 ne couvrait QUE PeriodTeams et PeriodConstraints — PeriodVenues est
  // resté hors de vue, et le lot C3 l'a laissé lire par le déclencheur : le gymnase
  // prêté était confirmé à l'écran (l'UI relisait avec le MÊME mauvais id, donc
  // cohérente avec elle-même) mais n'atteignait jamais le solveur. D'où ce test.
  it("PeriodVenues lit ses créneaux prêtés par le PLAN", () => {
    render(<PeriodVenues calendarEntryId="e1" />);
    expect(periodSlotsAnchor.value).toBe("plan-1");
  });

  // Le NR du round 1 n'assertait que la LECTURE — et c'est par l'ÉCRITURE que le bug est
  // passé au round 2 : le hook d'écriture recevait bien le plan, mais rien n'empêchait de
  // muter AVANT qu'il soit résolu (ancre null = « base » → le gymnase prêté atterrissait
  // sur le socle du club). On assert donc les deux.
  it("PeriodVenues ÉCRIT ses créneaux prêtés par le PLAN", () => {
    render(<PeriodVenues calendarEntryId="e1" />);
    expect(periodSlotWriteAnchor.value).toBe("plan-1");
  });

  it("PeriodVenues n'écrit RIEN tant que le plan n'est pas résolu (sinon : sur le socle)", async () => {
    const user = userEvent.setup();
    planState.data = undefined; // plan en cours de chargement, ou GET en échec
    render(<PeriodVenues calendarEntryId="e1" />);
    await user.selectOptions(screen.getByLabelText("Gymnase"), "v1");

    // 1. protection visible : le bouton est refusé.
    expect(screen.getByRole("button", { name: /Ajouter/ })).toBeDisabled();

    // 2. protection de fond : le handler lui-même refuse. On soumet le formulaire
    //    DIRECTEMENT — un clic ne prouverait rien, le bouton étant déjà désactivé (c'est
    //    ce qui rendait la première version de ce test décorative : elle passait sans la
    //    garde). Enter dans un champ, un submit programmatique ou une régression du
    //    `disabled` passeraient par ici.
    fireEvent.submit(screen.getByLabelText("Gymnase").closest("form") as HTMLFormElement);
    expect(createSlot).not.toHaveBeenCalled();

    // 3. et une fois le plan résolu, l'écriture part — avec l'ancre du PLAN.
    planState.data = { id: "plan-1", teamSelectionInitialized: false };
  });

  it("PeriodTeams ne confirme PAS « Sélection appliquée » quand rien n'a été écrit", async () => {
    const user = userEvent.setup();
    planState.data = undefined; // plan pas encore résolu → aucune écriture possible
    render(<PeriodTeams calendarEntryId="e1" />);

    await user.click(screen.getByRole("button", { name: "Fanion seul" }));
    // Le piège : sans ancre, chaque upsert bail en Promise.resolve() → zéro rejet → le
    // toast se déclenchait. Un succès qui ment est pire qu'une erreur : le gestionnaire
    // croit sa sélection posée, et l'overlay part avec tout le club actif.
    expect(toast.success).not.toHaveBeenCalledWith("Sélection appliquée");
    expect(createOverride).not.toHaveBeenCalled();
    planState.data = { id: "plan-1", teamSelectionInitialized: false };
  });

  it("PeriodVenues écrit dès que le plan est résolu", async () => {
    const user = userEvent.setup();
    render(<PeriodVenues calendarEntryId="e1" />);
    await user.selectOptions(screen.getByLabelText("Gymnase"), "v1");
    await user.click(screen.getByRole("button", { name: /Ajouter/ }));
    expect(createSlot).toHaveBeenCalled();
    expect(periodSlotWriteAnchor.value).toBe("plan-1");
  });
});

describe("PeriodConstraints — inherited constraints toggle", () => {
  it("closure: lists the club's permanent constraints, all kept by default", () => {
    constraintsState.data = [constraint({ id: "k1", name: "Pas après 20h", ruleType: "PREFERRED" })];
    render(<PeriodConstraints calendarEntryId="e1" />);
    const checkbox = screen.getByRole("checkbox", { name: "Pas après 20h appliquée cette période" });
    expect(checkbox).toBeChecked();
    expect(createConstraintOverride).not.toHaveBeenCalled();
  });

  it("toggling a constraint off creates a disabling override", async () => {
    constraintsState.data = [constraint({ id: "k1", name: "Pas après 20h", ruleType: "PREFERRED" })];
    render(<PeriodConstraints calendarEntryId="e1" />);
    await userEvent.click(screen.getByRole("checkbox", { name: "Pas après 20h appliquée cette période" }));
    expect(createConstraintOverride).toHaveBeenCalledWith({ schedulePlanId: "plan-1", constraintId: "k1", isActive: false }, expect.anything());
  });

  it("toggling a disabled constraint back on deletes its override", async () => {
    constraintsState.data = [constraint({ id: "k1", name: "Pas après 20h", ruleType: "PREFERRED" })];
    constraintOverridesState.data = [{ id: "ov1", constraintId: "k1", isActive: false, schedulePlanId: "plan-1" }];
    render(<PeriodConstraints calendarEntryId="e1" />);
    // Rendered unchecked (disabled) → clicking re-activates by removing the row.
    await userEvent.click(screen.getByRole("checkbox", { name: "Pas après 20h appliquée cette période" }));
    expect(deleteConstraintOverride).toHaveBeenCalledWith("ov1", expect.anything());
  });

  it("a rapid second click does NOT fire a duplicate create (in-flight guard)", async () => {
    constraintsState.data = [constraint({ id: "k1", name: "Pas après 20h", ruleType: "PREFERRED" })];
    render(<PeriodConstraints calendarEntryId="e1" />);
    const checkbox = screen.getByRole("checkbox", { name: "Pas après 20h appliquée cette période" });
    // The mock mutate never calls onSettled, so the write stays "in flight": the
    // optimistic state disables the box and a second click is swallowed.
    await userEvent.click(checkbox);
    await userEvent.click(checkbox);
    expect(createConstraintOverride).toHaveBeenCalledTimes(1);
  });

  it("blocks a second constraint's toggle while the first write is in flight (one mutation at a time)", async () => {
    constraintsState.data = [
      constraint({ id: "k1", name: "Pas après 20h", ruleType: "PREFERRED" }),
      constraint({ id: "k2", name: "Jamais le lundi", ruleType: "HARD" }),
    ];
    render(<PeriodConstraints calendarEntryId="e1" />);
    // Toggle k1 off → it stays in flight (mock never settles), so every checkbox
    // is disabled and k2 cannot fire a concurrent write (which would rebind the
    // shared mutation observer and strand k1's onSettled).
    await userEvent.click(screen.getByRole("checkbox", { name: "Pas après 20h appliquée cette période" }));
    expect(screen.getByRole("checkbox", { name: "Jamais le lundi appliquée cette période" })).toBeDisabled();
    await userEvent.click(screen.getByRole("checkbox", { name: "Jamais le lundi appliquée cette période" }));
    expect(createConstraintOverride).toHaveBeenCalledTimes(1);
  });

  it("reprise (holiday): default follows the team selection", () => {
    entryState.data = { periodType: "holiday" };
    overridesState.data = [{ id: "to1", teamId: "t2", isActive: false, sessionsPerWeek: null, schedulePlanId: "plan-1" }]; // t2 en pause
    constraintsState.data = [
      constraint({ id: "kc", name: "Club rule", ruleType: "PREFERRED", scope: "CLUB", scopeTargetId: null }),
      constraint({ id: "kf", name: "Gym rule", ruleType: "PREFERRED", scope: "FACILITY", scopeTargetId: "v1" }),
      constraint({ id: "kt1", name: "SM1 rule", ruleType: "PREFERRED", scope: "TEAM", scopeTargetId: "t1" }), // équipe active
      constraint({ id: "kt2", name: "U13 rule", ruleType: "PREFERRED", scope: "TEAM", scopeTargetId: "t2" }), // équipe en pause
    ];
    render(<PeriodConstraints calendarEntryId="e1" />);
    expect(screen.getByRole("checkbox", { name: "Club rule appliquée cette période" })).toBeChecked();
    expect(screen.getByRole("checkbox", { name: "Gym rule appliquée cette période" })).not.toBeChecked();
    expect(screen.getByRole("checkbox", { name: "SM1 rule appliquée cette période" })).toBeChecked();
    expect(screen.getByRole("checkbox", { name: "U13 rule appliquée cette période" })).not.toBeChecked();
  });

  it("reprise: waits for team overrides before rendering (no wrong-default flash)", () => {
    entryState.data = { periodType: "holiday" };
    teamOverridesLoadingState.value = true; // team overrides still fetching
    constraintsState.data = [constraint({ id: "kt", name: "U13 rule", ruleType: "PREFERRED", scope: "TEAM", scopeTargetId: "t2" })];
    render(<PeriodConstraints calendarEntryId="e1" />);
    // The team-derived default isn't known yet → the checklist must not render (nor be clickable).
    expect(screen.queryByRole("checkbox", { name: "U13 rule appliquée cette période" })).not.toBeInTheDocument();
  });

  it("waits for the period overrides query too (no create→422 flash)", () => {
    constraintOverridesLoadingState.value = true; // period overrides still fetching
    constraintsState.data = [constraint({ id: "k1", name: "Pas après 20h", ruleType: "PREFERRED" })];
    render(<PeriodConstraints calendarEntryId="e1" />);
    expect(screen.queryByRole("checkbox", { name: "Pas après 20h appliquée cette période" })).not.toBeInTheDocument();
  });

  it("disables toggles (read-only) when the period overrides query errors — no create→422", async () => {
    constraintOverridesErrorState.value = true; // overrides list unavailable
    constraintsState.data = [constraint({ id: "k1", name: "Pas après 20h", ruleType: "PREFERRED" })];
    render(<PeriodConstraints calendarEntryId="e1" />); // closure, renders (not hidden)
    const checkbox = screen.getByRole("checkbox", { name: "Pas après 20h appliquée cette période" });
    expect(checkbox).toBeDisabled();
    await userEvent.click(checkbox);
    expect(createConstraintOverride).not.toHaveBeenCalled();
  });

  it("locks only TEAM constraints on a team-overrides fetch error (non-TEAM stay usable)", () => {
    teamOverridesErrorState.value = true; // can't tell which teams are paused
    constraintsState.data = [
      constraint({ id: "kt", name: "U13 rule", ruleType: "PREFERRED", scope: "TEAM", scopeTargetId: "t2" }),
      constraint({ id: "kc", name: "Club rule", ruleType: "PREFERRED", scope: "CLUB", scopeTargetId: null }),
    ];
    render(<PeriodConstraints calendarEntryId="e1" />); // closure
    expect(screen.getByRole("checkbox", { name: "U13 rule appliquée cette période" })).toBeDisabled();
    expect(screen.getByRole("checkbox", { name: "Club rule appliquée cette période" })).not.toBeDisabled();
  });

  it("a TEAM constraint of a paused team is non-applicable (disabled, struck, never toggled)", async () => {
    overridesState.data = [{ id: "to1", teamId: "t2", isActive: false, sessionsPerWeek: null, schedulePlanId: "plan-1" }]; // t2 en pause
    constraintsState.data = [constraint({ id: "kt2", name: "U13 rule", ruleType: "PREFERRED", scope: "TEAM", scopeTargetId: "t2" })];
    render(<PeriodConstraints calendarEntryId="e1" />); // closure by default
    const checkbox = screen.getByRole("checkbox", { name: "U13 rule appliquée cette période" });
    expect(checkbox).toBeDisabled();
    expect(checkbox).not.toBeChecked();
    expect(screen.getByText("(équipe en pause)")).toBeInTheDocument();
    await userEvent.click(checkbox);
    expect(createConstraintOverride).not.toHaveBeenCalled();
  });

  it("reprise: keeping a facility (off by default) creates an isActive=true override", async () => {
    entryState.data = { periodType: "holiday" };
    constraintsState.data = [constraint({ id: "kf", name: "Gym rule", ruleType: "PREFERRED", scope: "FACILITY", scopeTargetId: "v1" })];
    render(<PeriodConstraints calendarEntryId="e1" />);
    await userEvent.click(screen.getByRole("checkbox", { name: "Gym rule appliquée cette période" }));
    expect(createConstraintOverride).toHaveBeenCalledWith({ schedulePlanId: "plan-1", constraintId: "kf", isActive: true }, expect.anything());
  });

  it("re-toggling a redundant isActive=true override updates instead of duplicating (P4-12d, no 422)", async () => {
    // closure default = kept; a stale isActive=true row → box checked. Unchecking must PUT, not POST.
    constraintsState.data = [constraint({ id: "k1", name: "Pas après 20h", ruleType: "PREFERRED" })];
    constraintOverridesState.data = [{ id: "ov1", constraintId: "k1", isActive: true, schedulePlanId: "plan-1" }];
    render(<PeriodConstraints calendarEntryId="e1" />);
    expect(screen.getByRole("checkbox", { name: "Pas après 20h appliquée cette période" })).toBeChecked();
    await userEvent.click(screen.getByRole("checkbox", { name: "Pas après 20h appliquée cette période" }));
    expect(updateConstraintOverride).toHaveBeenCalledWith({ id: "ov1", body: { schedulePlanId: "plan-1", constraintId: "k1", isActive: false } }, expect.anything());
    expect(createConstraintOverride).not.toHaveBeenCalled();
  });

  it("hides a CLUB+targetTag constraint when every tagged team is paused", () => {
    entryState.data = { periodType: "holiday" };
    tagsState.data = [{ id: "tag-sen", name: "SENIOR", color: null, isSystem: true, axis: "AGE" }];
    tagAssignmentsState.data = [{ id: "a1", teamId: "t2", tagId: "tag-sen", seasonId: "s1" }];
    overridesState.data = [{ id: "to1", teamId: "t2", isActive: false, sessionsPerWeek: null, schedulePlanId: "plan-1" }];
    constraintsState.data = [constraint({ id: "cg", name: "Groupe SENIOR · pas après 21:00", ruleType: "PREFERRED", scope: "CLUB", scopeTargetId: null, family: "TIME", config: { targetTag: "SENIOR" }, isActive: true })];

    render(<PeriodConstraints calendarEntryId="e1" />);

    expect(screen.queryByRole("checkbox", { name: "Groupe SENIOR · pas après 21:00 appliquée cette période" })).not.toBeInTheDocument();
  });

  it("does not render on a non-overlay period type (cutoff) — no dead override rows", () => {
    entryState.data = { periodType: "cutoff" };
    constraintsState.data = [constraint({ id: "k1", name: "Pas après 20h", ruleType: "PREFERRED" })];
    render(<PeriodConstraints calendarEntryId="e1" />);
    expect(screen.queryByRole("checkbox", { name: "Pas après 20h appliquée cette période" })).not.toBeInTheDocument();
  });

  it("does not render while the calendar entry is still loading (no holiday flash / dead override)", () => {
    entryState.data = undefined; // entry not resolved yet
    constraintsState.data = [constraint({ id: "k1", name: "Pas après 20h", ruleType: "PREFERRED" })];
    render(<PeriodConstraints calendarEntryId="e1" />);
    expect(screen.queryByRole("checkbox", { name: "Pas après 20h appliquée cette période" })).not.toBeInTheDocument();
  });
});
