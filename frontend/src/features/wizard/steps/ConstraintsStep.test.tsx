import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { Constraint } from "../api";

const h = vi.hoisted(() => ({
  createMut: vi.fn(),
  updateMut: vi.fn(),
  list: [] as Constraint[],
  resCreate: vi.fn(),
  resDelete: vi.fn(),
  reservations: [] as { id: string; calendarEntryId: string | null; teamId: string; venueId: string; dayOfWeek: number; startTime: string; durationMinutes: number }[],
  tags: [] as { id: string; name: string; color: string | null; isSystem: boolean }[],
  tagAssignments: [] as { id: string; teamId: string; tagId: string; seasonId: string }[],
}));

vi.mock("../queries", () => ({
  useWizardConstraints: () => ({ data: h.list }),
  useWizardTeams: () => ({
    data: [
      { id: "t1", name: "SM1", sportCategoryId: "cat", priorityTierId: 3, tierOrder: 0, gender: null, level: null, sessionsPerWeek: 2, isActive: true },
      { id: "t2", name: "Fanion", sportCategoryId: "cat", priorityTierId: 1, tierOrder: 0, gender: null, level: null, sessionsPerWeek: 2, isActive: true },
    ],
  }),
  usePriorityTiers: () => ({ data: [{ id: 1, label: "S", name: "Fanion", color: null }, { id: 3, label: "B", name: "Moyenne", color: null }] }),
  useWizardTeamTags: () => ({ data: h.tags }),
  useWizardTeamTagAssignments: () => ({ data: h.tagAssignments }),
  useWizardCoaches: () => ({ data: [{ id: "co1", firstName: "Jean", lastName: "Dupont", isEmployee: false, isActive: true, email: null }] }),
  useWizardCoachPlayers: () => ({ data: [] }),
  useWizardVenues: () => ({ data: [{ id: "v1", name: "Gymnase A", isActive: true }, { id: "v2", name: "Gymnase B", isActive: true }] }),
  useVenueSlots: () => ({ data: [{ id: "s1", venueId: "v1", dayOfWeek: 2, startTime: "20:30", durationMinutes: 120, capacity: 1 }] }),
  useCreateConstraint: () => ({ mutate: h.createMut, isPending: false }),
  useUpdateConstraint: () => ({ mutate: h.updateMut, isPending: false }),
  useDeleteConstraint: () => ({ mutate: vi.fn() }),
  useReservations: () => ({ data: h.reservations }),
  useCreateReservation: () => ({ mutate: h.resCreate, isPending: false }),
  useDeleteReservation: () => ({ mutate: h.resDelete }),
}));

import { ConstraintsStep } from "./ConstraintsStep";

/**
 * Freezes the UI OFFER side of the constraint matrix (audit P0.1): the rule
 * options and the emitted configs are locked to what
 * engine/tests/semantic/constraint_matrix.py declares as honored. Any change
 * here must update the matrix (and its generated engine test) first.
 */
describe("ConstraintsStep — constraint-matrix offer lock", () => {
  beforeEach(() => {
    h.createMut.mockClear();
    h.updateMut.mockClear();
    h.list = [];
    h.tags = [];
    h.tagAssignments = [];
  });

  it("only offers groups (tags) that have at least one assigned team", () => {
    h.tags = [
      { id: "tag-fem", name: "FEMININE", color: null, isSystem: true },
      { id: "tag-sen", name: "SENIOR", color: null, isSystem: true },
    ];
    // Only SENIOR is assigned to a team — FEMININE concerns no team.
    h.tagAssignments = [{ id: "a1", teamId: "t1", tagId: "tag-sen", seasonId: "s1" }];

    renderWithProviders(<ConstraintsStep />);
    const target = screen.getByRole("combobox", { name: "Cible" });
    const options = Array.from(target.querySelectorAll("option")).map((o) => o.textContent);
    expect(options).toContain("SENIOR");
    expect(options).not.toContain("FEMININE");
  });

  it("groups the constraints list: group (tag) sections first, then teams in rank order", () => {
    h.list = [
      { id: "cg", name: "Groupe SENIOR · pas après 21:00", scope: "CLUB", scopeTargetId: null, family: "TIME", ruleType: "PREFERRED", config: { targetTag: "SENIOR" }, isActive: true },
      { id: "cb", name: "SM1 · pas après 21:00", scope: "TEAM", scopeTargetId: "t1", family: "TIME", ruleType: "PREFERRED", config: {}, isActive: true }, // t1 = tier B
      { id: "cs", name: "Fanion · pas après 21:00", scope: "TEAM", scopeTargetId: "t2", family: "TIME", ruleType: "PREFERRED", config: {}, isActive: true }, // t2 = tier S
    ] as Constraint[];

    renderWithProviders(<ConstraintsStep />);

    // Groups first, then the teams in canonical rank order (Fanion=S before SM1=B).
    const sections = screen.getAllByTestId("constraint-section").map((e) => e.textContent);
    expect(sections).toEqual(["Groupe SENIOR", "Fanion", "SM1"]);
  });

  it("offers exactly Obligatoire/Préféré/Verrouillé — BONUS is gone (ENG-12)", () => {
    renderWithProviders(<ConstraintsStep />);
    const rule = screen.getByLabelText("Règle");
    const options = Array.from(rule.querySelectorAll("option")).map((o) => o.textContent);
    expect(options).toEqual(["Préféré", "Obligatoire", "Verrouillé"]);
  });

  it("forces HARD on coach availability (no rule selector — the engine always enforces it)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Dispo coach" }));
    expect(screen.queryByLabelText("Règle")).not.toBeInTheDocument();
    expect(screen.getByText("Obligatoire")).toBeInTheDocument();

    // Pick coach + a day, add → the payload pins ruleType HARD.
    await user.selectOptions(screen.getByLabelText("Coach"), "co1");
    await user.click(screen.getByRole("button", { name: "Lun" }));
    await user.click(screen.getByRole("button", { name: "Ajouter la contrainte" }));

    expect(h.createMut).toHaveBeenCalledOnce();
    expect(h.createMut.mock.calls[0][0]).toMatchObject({ family: "COACH_AVAILABILITY", ruleType: "HARD", config: { coachId: "co1", unavailableDays: [1] } });
  });

  it("groups the coach picker (a non-employee non-player coach lands under « Bénévoles »)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Dispo coach" }));
    const benevoles = screen.getByRole("group", { name: "Bénévoles" });
    expect(within(benevoles).getByRole("option", { name: "Jean Dupont" })).toBeInTheDocument();
  });

  it("coach 'disponible uniquement' emits HARD availableDays (whitelist — ALIGN, engine already honored it)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Dispo coach" }));
    await user.selectOptions(screen.getByLabelText("Coach"), "co1");
    await user.selectOptions(screen.getByLabelText("Disponibilité"), "available");
    await user.click(screen.getByRole("button", { name: "Mar" }));
    await user.click(screen.getByRole("button", { name: "Ajouter la contrainte" }));

    expect(h.createMut.mock.calls[0][0]).toMatchObject({ family: "COACH_AVAILABILITY", ruleType: "HARD", config: { coachId: "co1", availableDays: [2] } });
  });

  it("DAY emits forbiddenDays (the matrix key) whatever the ruleType", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Jours" }));
    await user.click(screen.getByRole("button", { name: "Mer" }));
    // default ruleType = PREFERRED (soft "avoid these days", ENG-10 fix engine-side)
    await user.click(screen.getByRole("button", { name: "Ajouter la contrainte" }));

    expect(h.createMut).toHaveBeenCalledOnce();
    expect(h.createMut.mock.calls[0][0]).toMatchObject({ family: "DAY", ruleType: "PREFERRED", config: { forbiddenDays: [3] } });
  });

  it("FACILITY emits preferredVenueId or forbiddenVenueId (matrix keys)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Gymnase" }));
    await user.selectOptions(screen.getByLabelText("Gymnase"), "v1");
    await user.click(screen.getByRole("button", { name: "Ajouter la contrainte" }));

    expect(h.createMut.mock.calls[0][0]).toMatchObject({ family: "FACILITY", config: { preferredVenueId: "v1" } });
  });

  it("FACILITY 'impose' emits a HARD forcedVenueId (matrix HONORED_HARD)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Gymnase" }));
    await user.selectOptions(screen.getByLabelText("Préférence"), "forced");
    await user.selectOptions(screen.getByLabelText("Gymnase"), "v1");
    await user.click(screen.getByRole("button", { name: "Ajouter la contrainte" }));

    expect(h.createMut.mock.calls[0][0]).toMatchObject({ family: "FACILITY", ruleType: "HARD", config: { forcedVenueId: "v1" } });
  });

  it("TIME 'Fini avant' emits a HARD maxEndTime (soft path can't honor an end-bound — ALIGN-04)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    // TIME is the default family. Setting an end-bound pins the rule HARD.
    await user.type(screen.getByLabelText("Fini avant"), "20:30");
    await user.click(screen.getByRole("button", { name: "Ajouter la contrainte" }));

    expect(h.createMut.mock.calls[0][0]).toMatchObject({ family: "TIME", ruleType: "HARD", config: { maxEndTime: "20:30" } });
  });

  it("FACILITY 'au moins' emits a HARD minAtVenueId + minAtVenueCount (floor count — ALIGN-05)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Gymnase" }));
    // "au moins N" is per-team → target a specific team (TEAM scope, the only shape the engine honors).
    await user.selectOptions(screen.getByLabelText("Cible"), "t1");
    await user.selectOptions(screen.getByLabelText("Préférence"), "min");
    await user.selectOptions(screen.getByLabelText("Gymnase"), "v1");
    await user.click(screen.getByRole("button", { name: "Ajouter la contrainte" }));

    expect(h.createMut.mock.calls[0][0]).toMatchObject({ family: "FACILITY", scope: "TEAM", ruleType: "HARD", config: { minAtVenueId: "v1", minAtVenueCount: 1 } });
  });

  it("DAY 'uniquement' emits HARD allowedDays (whitelist — only these days, ENG-16)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Jours" }));
    await user.selectOptions(screen.getByLabelText("Type de jour"), "forced");
    await user.click(screen.getByRole("button", { name: "Ven" }));
    await user.click(screen.getByRole("button", { name: "Ajouter la contrainte" }));

    expect(h.createMut.mock.calls[0][0]).toMatchObject({ family: "DAY", ruleType: "HARD", config: { allowedDays: [5] } });
  });

  it("keeps the target after a create so several constraints can be added in a row (F5)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Jours" }));
    await user.selectOptions(screen.getByLabelText("Cible"), "t1");
    await user.click(screen.getByRole("button", { name: "Mer" }));
    await user.click(screen.getByRole("button", { name: "Ajouter la contrainte" }));

    expect(h.createMut).toHaveBeenCalledOnce();
    // The target survives the add (only the value inputs are cleared).
    expect(screen.getByLabelText("Cible")).toHaveValue("t1");
  });
});

/**
 * Editing an EXISTING constraint reuses the same form (PUT). The critical
 * guard: loading a forced-venue/day rule back into the form and saving must
 * NOT downgrade forcedVenueId→preferredVenueId (a silent §7.1 semantics break).
 */
describe("ConstraintsStep — edit an existing constraint", () => {
  const forcedFacility: Constraint = {
    id: "c-sm4",
    name: "SM1 · impose Gymnase A",
    scope: "TEAM",
    scopeTargetId: "t1",
    family: "FACILITY",
    ruleType: "HARD",
    config: { forcedVenueId: "v1" },
    isActive: true,
  };

  beforeEach(() => {
    h.createMut.mockClear();
    h.updateMut.mockClear();
    h.list = [forcedFacility];
  });

  it("round-trips a forced venue without downgrading it to preferred, and PUTs the same id", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Gymnase" }));
    expect(screen.getByText("SM1 · impose Gymnase A")).toBeInTheDocument();

    // Enter edit mode → the form pre-fills from config.
    await user.click(screen.getByRole("button", { name: "Modifier" }));
    expect(screen.getByLabelText("Préférence")).toHaveValue("forced");
    expect(screen.getByLabelText("Gymnase")).toHaveValue("v1");

    await user.click(screen.getByRole("button", { name: "Enregistrer la contrainte" }));

    expect(h.createMut).not.toHaveBeenCalled();
    expect(h.updateMut).toHaveBeenCalledOnce();
    const arg = h.updateMut.mock.calls[0][0] as { id: string; body: Constraint };
    expect(arg.id).toBe("c-sm4");
    expect(arg.body.config).toHaveProperty("forcedVenueId", "v1");
    expect(arg.body.config).not.toHaveProperty("preferredVenueId");
    expect(arg.body.ruleType).toBe("HARD");
  });

  it("softening a forced venue to 'préfère' emits a PREFERRED rule, not the inherited HARD (F1)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Gymnase" }));
    await user.click(screen.getByRole("button", { name: "Modifier" }));
    // Switch impose → préfère: the inherited HARD must NOT leak (HARD preferredVenueId
    // is still a forced venue engine-side — the opposite of what the user wants).
    await user.selectOptions(screen.getByLabelText("Préférence"), "preferred");
    await user.click(screen.getByRole("button", { name: "Enregistrer la contrainte" }));

    const arg = h.updateMut.mock.calls[0][0] as { body: Constraint };
    expect(arg.body.ruleType).toBe("PREFERRED");
    expect(arg.body.config).toEqual({ preferredVenueId: "v1" });
  });

  it("persists an edited venue choice under the forced key", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Gymnase" }));
    await user.click(screen.getByRole("button", { name: "Modifier" }));
    await user.selectOptions(screen.getByLabelText("Gymnase"), "v2");
    await user.click(screen.getByRole("button", { name: "Enregistrer la contrainte" }));

    const arg = h.updateMut.mock.calls[0][0] as { body: Constraint };
    expect(arg.body.config).toEqual({ forcedVenueId: "v2" });
  });

  it("loads a legacy forcedDays 'uniquement' rule and auto-migrates it to allowedDays on save (ENG-16 review)", async () => {
    const user = userEvent.setup();
    h.list = [
      {
        id: "c-legacy-day",
        name: "SM1 · uniquement Ven",
        scope: "TEAM",
        scopeTargetId: "t1",
        family: "DAY",
        ruleType: "HARD",
        config: { forcedDays: [5] }, // legacy key from #120
        isActive: true,
      },
    ];
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Jours" }));
    await user.click(screen.getByRole("button", { name: "Modifier" }));
    // Legacy forcedDays loads as the "uniquement" mode, day preselected.
    expect(screen.getByLabelText("Type de jour")).toHaveValue("forced");
    await user.click(screen.getByRole("button", { name: "Enregistrer la contrainte" }));

    const arg = h.updateMut.mock.calls[0][0] as { body: Constraint };
    expect(arg.body.config).toEqual({ allowedDays: [5] });
    expect(arg.body.config).not.toHaveProperty("forcedDays");
  });
});

/**
 * Réserver tab is now a per-venue slot grid: click a slot → modal to pin/remove
 * teams (server-backed Reservation entity, base/overlay). The rank-sorted summary
 * moved to the Récap step. These lock the grid+modal interaction + the team-cap.
 */
describe("ConstraintsStep — Réserver tab (slot grid + modal)", () => {
  beforeEach(() => {
    h.resCreate.mockClear();
    h.resDelete.mockClear();
    h.reservations = [];
  });

  const openSlot = async (user: ReturnType<typeof userEvent.setup>) => {
    await user.click(screen.getAllByRole("button", { name: /Réserver/ })[0]); // the family tab
    await user.click(screen.getByRole("button", { name: /Gymnase A.*cliquer pour gérer/ })); // the slot in the grid
  };

  it("clicking a reserved slot opens the modal with a removable team → useDeleteReservation", async () => {
    h.reservations = [{ id: "r1", calendarEntryId: null, teamId: "t1", venueId: "v1", dayOfWeek: 2, startTime: "20:30", durationMinutes: 120 }];
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await openSlot(user);
    const remove = screen.getByRole("button", { name: "Retirer SM1" });
    await user.click(remove);
    expect(h.resDelete).toHaveBeenCalledWith("r1");
  });

  it("adding a team from the modal → useCreateReservation with the slot payload + base calendarEntryId", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await openSlot(user);
    // Picker is rank-ordered (Fanion=S before SM1=B); pick the fanion.
    await user.selectOptions(screen.getByLabelText("Ajouter une équipe"), "t2");
    expect(h.resCreate).toHaveBeenCalledWith(expect.objectContaining({ teamId: "t2", venueId: "v1", dayOfWeek: 2, startTime: "20:30", durationMinutes: 120, calendarEntryId: null }));
  });

  it("hides a team that reached its sessionsPerWeek from the picker", async () => {
    // t2 (Fanion) has 2 sessions and 2 reservations elsewhere → maxed, absent from the picker.
    h.reservations = [
      { id: "ra", calendarEntryId: null, teamId: "t2", venueId: "v1", dayOfWeek: 3, startTime: "18:00", durationMinutes: 90 },
      { id: "rb", calendarEntryId: null, teamId: "t2", venueId: "v1", dayOfWeek: 4, startTime: "18:00", durationMinutes: 90 },
    ];
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await openSlot(user);
    const picker = screen.getByLabelText("Ajouter une équipe");
    expect(within(picker).queryByRole("option", { name: "Fanion" })).toBeNull();
    expect(within(picker).getByRole("option", { name: "SM1" })).toBeInTheDocument();
  });
});
