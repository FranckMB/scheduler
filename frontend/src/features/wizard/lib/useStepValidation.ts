import type { Reservation, Team, Venue, VenueTrainingSlot } from "../api";
import { useConstraintValidation, useReservations, useVenueSlots, useWizardCoachPlayers, useWizardCoaches, useWizardTeamCoaches, useWizardTeams, useWizardVenues } from "../queries";
import { useWizardStore } from "../store";
import { humanizeConstraintError } from "./constraintErrors";
import { okValidation, type StepValidation, type WizardStepId } from "./steps";

const DAY_LABELS = ["", "lundi", "mardi", "mercredi", "jeudi", "vendredi", "samedi", "dimanche"];

/**
 * Non-blocking warnings on pre-generation reservations (W6). These never gate
 * "Suivant": the solver stays the authority and returns INFEASIBLE + diagnostics
 * if a reservation set is truly unsatisfiable. We only surface likely mistakes
 * at entry time.
 *   1. A slot shared by more teams than the venue slot allows (canSplit + capacity).
 *   2. A team reserved more slots than its sessions/week.
 *   3. A team with two sessions on the same day.
 */
export function computeReservationWarnings(reservations: Reservation[], teams: Team[], venues: Venue[], slots: VenueTrainingSlot[]): string[] {
  const warnings: string[] = [];
  const teamName = new Map(teams.map((t) => [t.id, t.name]));
  const venueName = new Map(venues.map((v) => [v.id, v.name]));
  const venueSplit = new Map(venues.map((v) => [v.id, v.canSplit]));
  const slotKey = (venueId: string, day: number, start: string): string => `${venueId}|${day}|${start}`;
  const slotCapacity = new Map(slots.map((s) => [slotKey(s.venueId, s.dayOfWeek, s.startTime), s.capacity]));

  // Rule 1 — same slot shared by more distinct teams than allowed.
  const bySlot = new Map<string, Set<string>>();
  for (const r of reservations) {
    const key = slotKey(r.venueId, r.dayOfWeek, r.startTime);
    bySlot.set(key, (bySlot.get(key) ?? new Set()).add(r.teamId));
  }
  bySlot.forEach((teamIds, key) => {
    const [venueId, day, start] = key.split("|");
    const allowed = (venueSplit.get(venueId) ?? false) ? (slotCapacity.get(key) ?? 1) : 1;
    if (teamIds.size > allowed) {
      warnings.push(`Créneau partagé par ${teamIds.size} équipes (max ${allowed}) : ${venueName.get(venueId) ?? "?"} ${DAY_LABELS[Number(day)] ?? ""} ${start}.`);
    }
  });

  // Rules 2 & 3 — per team: total count vs sessions/week, and same-day duplicates.
  const byTeam = new Map<string, Reservation[]>();
  for (const r of reservations) {
    byTeam.set(r.teamId, [...(byTeam.get(r.teamId) ?? []), r]);
  }
  byTeam.forEach((group, teamId) => {
    const team = teams.find((t) => t.id === teamId);
    if (undefined !== team && group.length > team.sessionsPerWeek) {
      warnings.push(`${teamName.get(teamId) ?? "?"} : ${group.length} réservations pour ${team.sessionsPerWeek} séance(s)/semaine.`);
    }
    const perDay = new Map<number, number>();
    for (const r of group) {
      perDay.set(r.dayOfWeek, (perDay.get(r.dayOfWeek) ?? 0) + 1);
    }
    perDay.forEach((count, day) => {
      if (2 <= count) {
        warnings.push(`${teamName.get(teamId) ?? "?"} : ${count} séances le même jour (${DAY_LABELS[day] ?? ""}).`);
      }
    });
  });

  return warnings;
}

/** Venues that carry no availability slot — the "gym without slot" rule, shared
 * by the venues and recap gates. */
function venuesWithoutSlot(venues: Venue[], slots: VenueTrainingSlot[]): Venue[] {
  const withSlot = new Set(slots.map((s) => s.venueId));
  return venues.filter((v) => !withSlot.has(v.id));
}

/**
 * Validation of a step for the "Suivant" gate + nav badges. Blocking rules per
 * step: ≥1 team; every gym has ≥1 availability slot; ≥1 coach. The constraints
 * step only produces non-blocking reservation warnings (see above).
 */
export function useStepValidation(stepId: WizardStepId): StepValidation {
  const teamsQuery = useWizardTeams();
  const venuesQuery = useWizardVenues();
  const slotsQuery = useVenueSlots();
  const coachesQuery = useWizardCoaches();
  const { data: teams = [] } = teamsQuery;
  const { data: venues = [] } = venuesQuery;
  const { data: slots = [] } = slotsQuery;
  const { data: coaches = [] } = coachesQuery;
  const { data: teamCoaches = [] } = useWizardTeamCoaches();
  const { data: coachPlayers = [] } = useWizardCoachPlayers();
  // Period (secondary planning) mode — keyed on the mode itself, NOT the entry id:
  // a period with a not-yet-resolved calendarEntryId is still period mode, and the
  // slots there are inherited & read-only regardless.
  const periodMode = useWizardStore((s) => "period" === s.mode);
  const periodEntryId = useWizardStore((s) => (s.mode === "period" ? s.calendarEntryId : null));
  // Reservations are server-backed now (base vs period overlay), not the client store.
  const { data: reservations = [] } = useReservations(periodEntryId);
  // The pre-solve constraint check is only needed for the recap verdict, and only
  // while the user is actually on the recap OR generate step — firing it on every
  // earlier step is a wasted backend round-trip.
  const currentStepId = useWizardStore((s) => s.stepId);
  const constraintNeeded = "recap" === stepId && ("recap" === currentStepId || "generate" === currentStepId);
  const constraintQuery = useConstraintValidation(constraintNeeded, periodEntryId);
  const constraintValidation = constraintQuery.data;

  // On first load the queries default to [], which would flash a false blocking
  // error ("Ajoutez au moins une équipe") before the data arrives. Stay neutral
  // but mark pending so gates stay closed (isLoading = first load only).
  if (teamsQuery.isLoading || venuesQuery.isLoading || slotsQuery.isLoading || coachesQuery.isLoading) {
    return { errors: [], warnings: [], pending: true };
  }

  if ("teams" === stepId) {
    return { errors: 0 === teams.length ? ["Ajoutez au moins une équipe."] : [], warnings: [] };
  }
  if ("venues" === stepId) {
    const errors: string[] = [];
    if (0 === venues.length) {
      errors.push("Ajoutez au moins un gymnase.");
    }
    // In period mode the venues + their slots are inherited from the base plan and
    // read-only — a "sans créneau" blocker there is a false alarm (the user cannot
    // add slots on a secondary planning). The rule only applies to the base plan.
    if (!periodMode) {
      const empty = venuesWithoutSlot(venues, slots);
      if (empty.length > 0) {
        errors.push(`Gymnase(s) sans créneau : ${empty.map((v) => v.name).join(", ")}.`);
      }
    }
    return { errors, warnings: [] };
  }
  if ("coaches" === stepId) {
    const linked = new Set([...teamCoaches.map((l) => l.coachId), ...coachPlayers.map((l) => l.coachId)]);
    const unlinked = coaches.filter((c) => !linked.has(c.id));
    return {
      errors: 0 === coaches.length ? ["Ajoutez au moins un coach."] : [],
      warnings: unlinked.length > 0 ? [`Coach(s) sans équipe : ${unlinked.map((c) => `${c.firstName} ${c.lastName}`.trim()).join(", ")}.`] : [],
    };
  }
  if ("constraints" === stepId) {
    return { errors: [], warnings: computeReservationWarnings(reservations, teams, venues, slots) };
  }
  if ("recap" === stepId) {
    const errors: string[] = [];
    if (0 === teams.length) {
      errors.push("Ajoutez au moins une équipe.");
    }
    if (0 === coaches.length) {
      errors.push("Ajoutez au moins un coach.");
    }
    if (0 === venues.length) {
      errors.push("Ajoutez au moins un gymnase.");
    }
    // Period mode: slots are inherited & read-only — skip the "sans créneau" gate
    // (same rationale as the venues step above).
    if (!periodMode) {
      const empty = venuesWithoutSlot(venues, slots);
      if (empty.length > 0) {
        errors.push(`Gymnase(s) sans créneau : ${empty.map((v) => v.name).join(", ")}.`);
      }
    }
    if (constraintValidation && !constraintValidation.valid) {
      for (const messages of Object.values(constraintValidation.errors)) {
        errors.push(...messages.map(humanizeConstraintError));
      }
      for (const conflict of constraintValidation.conflicts) {
        errors.push(humanizeConstraintError(conflict.reason));
      }
    }
    // Until the pre-solve check resolves, report pending so the generate gate
    // stays closed rather than briefly allowing a launch on an invalid setup.
    return { errors, warnings: [], pending: constraintNeeded && constraintQuery.isLoading };
  }
  return okValidation();
}
