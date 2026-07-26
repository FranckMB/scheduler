import { renderHook } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

import type { Reservation, Team, Venue, VenueTrainingSlot } from "../api";
import { computeReservationWarnings, useStepValidation } from "./useStepValidation";
import { useWizardStore } from "../store";

// A gym with NO availability slot — the "sans créneau" rule fires on it in base
// mode but must stay silent in period mode (slots are inherited & read-only).
// Le plan de la période : ancre des réservations depuis le lot C3 (inv. 5).
vi.mock("@/features/cockpit/queries", () => ({
  useSchedulePlanForEntry: () => ({ data: { id: "plan-1" }, isLoading: false }),
  // Ancre résolue seulement si une entrée est fournie : mode période sans entrée = plan
  // non résolu (planId null), comme dans le vrai code.
  usePeriodAnchor: (entryId: string | null) => (null === entryId ? { planId: null, ready: false, isLoading: false, isError: false, refetch: () => {} } : { planId: "plan-1", ready: true, isLoading: false, isError: false, refetch: () => {} }),
}));
vi.mock("../queries", () => ({
  useWizardTeams: () => ({ data: [{ id: "t1", name: "SM1", sportCategoryId: "c", priorityTierId: 1, tierOrder: 0, gender: null, level: null, sessionsPerWeek: 1, isActive: true }], isLoading: false }),
  useWizardVenues: () => ({ data: [{ id: "v1", name: "Gymnase A", color: null, canSplit: false, isActive: true }], isLoading: false }),
  useVenueSlots: () => ({ data: [], isLoading: false }),
  useWizardCoaches: () => ({ data: [{ id: "co1", firstName: "Ana", lastName: "A", email: null, isEmployee: true, isActive: true }], isLoading: false }),
  useWizardTeamCoaches: () => ({ data: [] }),
  useWizardCoachPlayers: () => ({ data: [] }),
  useConstraintValidation: () => ({ data: undefined, isLoading: false }),
  useReservations: () => ({ data: [] }),
  usePeriodSlots: () => ({ data: periodSlotsState.data, isLoading: periodSlotsState.isLoading }),
  useVenuePeriodOverrides: () => ({ data: [] }),
}));

const periodSlotsState: { data: unknown[]; isLoading: boolean } = { data: [], isLoading: false };

const team = (id: string, name: string, sessionsPerWeek: number): Team => ({
  id,
  name,
  sportCategoryId: "c",
  priorityTierId: 1,
  tierOrder: 0,
  gender: null,
  level: null,
  sessionsPerWeek,
  isActive: true,
});

const venue = (id: string, name: string, canSplit: boolean): Venue => ({ id, name, color: null, canSplit, isActive: true });

const slot = (venueId: string, dayOfWeek: number, startTime: string, capacity: number): VenueTrainingSlot => ({
  id: `${venueId}-${dayOfWeek}-${startTime}`,
  venueId,
  dayOfWeek,
  startTime,
  durationMinutes: 90,
  capacity,
});

const reservation = (id: string, teamId: string, venueId: string, dayOfWeek: number, startTime: string): Reservation => ({
  id,
  schedulePlanId: null, // réservation de BASE (structure partagée, inv. 6)
  teamId,
  venueId,
  dayOfWeek,
  startTime,
  durationMinutes: 90,
});

describe("computeReservationWarnings (W6)", () => {
  it("returns no warning for a clean set", () => {
    const teams = [team("t1", "U13", 1), team("t2", "U15", 1)];
    const venues = [venue("v1", "Gymnase A", false)];
    const slots = [slot("v1", 2, "18:00", 1), slot("v1", 4, "18:00", 1)];
    const reservations = [reservation("r1", "t1", "v1", 2, "18:00"), reservation("r2", "t2", "v1", 4, "18:00")];
    expect(computeReservationWarnings(reservations, teams, venues, slots)).toEqual([]);
  });

  it("warns when a non-splittable slot is shared by two teams", () => {
    const teams = [team("t1", "U13", 1), team("t2", "U15", 1)];
    const venues = [venue("v1", "Gymnase A", false)];
    const slots = [slot("v1", 2, "18:00", 1)];
    const reservations = [reservation("r1", "t1", "v1", 2, "18:00"), reservation("r2", "t2", "v1", 2, "18:00")];
    const warnings = computeReservationWarnings(reservations, teams, venues, slots);
    expect(warnings).toHaveLength(1);
    expect(warnings[0]).toContain("Créneau partagé par 2 équipes (max 1)");
  });

  it("does not warn when a splittable slot with capacity 2 holds two teams", () => {
    const teams = [team("t1", "U13", 1), team("t2", "U15", 1)];
    const venues = [venue("v1", "Gymnase A", true)];
    const slots = [slot("v1", 2, "18:00", 2)];
    const reservations = [reservation("r1", "t1", "v1", 2, "18:00"), reservation("r2", "t2", "v1", 2, "18:00")];
    expect(computeReservationWarnings(reservations, teams, venues, slots)).toEqual([]);
  });

  it("warns when a team reserves more slots than its sessions/week", () => {
    const teams = [team("t1", "U13", 2)];
    const venues = [venue("v1", "Gymnase A", false)];
    const slots = [slot("v1", 1, "18:00", 1), slot("v1", 3, "18:00", 1), slot("v1", 5, "18:00", 1)];
    const reservations = [
      reservation("r1", "t1", "v1", 1, "18:00"),
      reservation("r2", "t1", "v1", 3, "18:00"),
      reservation("r3", "t1", "v1", 5, "18:00"),
    ];
    const warnings = computeReservationWarnings(reservations, teams, venues, slots);
    expect(warnings).toContainEqual("U13 : 3 réservations pour 2 séance(s)/semaine.");
  });

  it("warns when a team has two sessions on the same day", () => {
    const teams = [team("t1", "U13", 2)];
    const venues = [venue("v1", "Gymnase A", false)];
    const slots = [slot("v1", 2, "18:00", 1), slot("v1", 2, "20:00", 1)];
    const reservations = [reservation("r1", "t1", "v1", 2, "18:00"), reservation("r2", "t1", "v1", 2, "20:00")];
    const warnings = computeReservationWarnings(reservations, teams, venues, slots);
    expect(warnings).toContainEqual("U13 : 2 séances le même jour (mardi).");
  });
});

describe("useStepValidation — venue slot rule (période : #8 PR-B, la grille est éditable)", () => {
  afterEach(() => useWizardStore.setState({ mode: "season", calendarEntryId: null, stepId: "teams" }));

  it("flags a gym without a slot in base (season) mode", () => {
    useWizardStore.setState({ mode: "season", calendarEntryId: null, stepId: "venues" });
    const { result } = renderHook(() => useStepValidation("venues"));
    expect(result.current.errors.some((e) => /sans créneau/.test(e))).toBe(true);
  });

  it("flags an ACTIVE gym without a period slot — la période possède sa grille, plus d'héritage lecture seule", () => {
    // Avant #8 la règle était neutralisée en période (grille héritée). Depuis PR-B la
    // période édite sa grille : vider la grille du seul gymnase doit bloquer « Suivant »,
    // sinon la période se génère à vide sans un mot (revue #8 PR-B, finding #5).
    useWizardStore.setState({ mode: "period", calendarEntryId: "e1", stepId: "venues" });
    const { result } = renderHook(() => useStepValidation("venues"));
    expect(result.current.errors.some((e) => /sans créneau/.test(e))).toBe(true);
  });

  it("ne bloque PAS tant que le plan de la période n'est pas résolu (faux positif de chargement)", () => {
    // planId null (via calendarEntryId null → l'ancre n'est pas résolue dans le vrai code) :
    // les créneaux de la période ne sont pas encore lus, on reste neutre plutôt que de
    // crier « sans créneau » sur une grille qu'on n'a pas chargée.
    useWizardStore.setState({ mode: "period", calendarEntryId: null, stepId: "venues" });
    const { result } = renderHook(() => useStepValidation("venues"));
    expect(result.current.errors.some((e) => /sans créneau/.test(e))).toBe(false);
  });

  it("ne bloque PAS pendant que la query des créneaux de période charge (faux positif)", () => {
    // Round 2 finding #4 : le plan peut être résolu (ready) alors que la query period_slots
    // n'a pas encore chargé (data []). Armer la règle là dessus criait « sans créneau » sur
    // une grille en fait pleine. On attend que la query ait chargé.
    periodSlotsState.isLoading = true;
    useWizardStore.setState({ mode: "period", calendarEntryId: "e1", stepId: "venues" });
    const { result } = renderHook(() => useStepValidation("venues"));
    expect(result.current.errors.some((e) => /sans créneau/.test(e))).toBe(false);
    periodSlotsState.isLoading = false;
  });
});;
