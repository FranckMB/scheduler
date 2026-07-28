import { anchorIsWritable, usePeriodAnchor } from "@/features/cockpit/queries";
import { readFailed, readLoading } from "@/shared/lib/readState";
import type { Reservation, Team, Venue, VenueTrainingSlot } from "../api";
import { useConstraintValidation, usePeriodSlots, useReservations, useTeamPeriodOverrides, useVenuePeriodOverrides, useVenueSlots, useWizardCoachPlayers, useWizardCoaches, useWizardTeamCoaches, useWizardTeams, useWizardVenues } from "../queries";
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
  // Elles pendent au PLAN (inv. 5, lot C3) : le composant tient le déclencheur, il en
  // résout l'ancre.
  // Hors ancre certaine, ne PAS lire : on servirait le socle.
  const periodAnchor = usePeriodAnchor(periodEntryId);
  // En mode période, seul l'état `period` autorise la lecture : `base` (mode période
  // rehydraté sans entrée) lirait le SOCLE et le présenterait comme la période.
  const { data: reservations = [] } = useReservations(periodAnchor.planId, periodMode ? "period" === periodAnchor.state : anchorIsWritable(periodAnchor));
  // #8 PR-B — une période POSSÈDE désormais sa grille (elle n'est plus héritée en lecture
  // seule) : la règle « gymnase sans créneau » doit donc s'y appliquer AUSSI, mais sur les
  // créneaux de la PÉRIODE et en épargnant les gymnases explicitement désactivés (dont
  // l'absence de créneau servi est voulue). Sans elle, vider une grille passait « Suivant »
  // sans un mot et la période se générait à vide (revue #8 PR-B).
  const periodSlotsQuery = usePeriodSlots(periodMode ? periodAnchor.planId : null);
  const periodSlots = periodSlotsQuery.data ?? [];
  const periodOverridesQuery = useVenuePeriodOverrides(periodMode ? periodAnchor.planId : null);
  const periodOverrides = periodOverridesQuery.data ?? [];
  // La SÉLECTION d'équipes de la période : le verdict ne la lisait pas, donc le récap
  // pouvait rester vert pendant que le panneau Équipes affichait « Impossible de charger
  // la sélection ». Deux écrans, deux vérités — le défaut que cette PR corrige ailleurs.
  // Même clé react-query que le panneau : pas de requête supplémentaire en pratique.
  const periodTeamOverridesQuery = useTeamPeriodOverrides(periodMode ? periodAnchor.planId : null);
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

  // P4-20 — l'ancre de la période n'a PAS pu être chargée : on ne sait rien de sa
  // grille ni de ses réglages. Un verdict « prêt » serait un mensonge vert sur une
  // période cassée (décision fondateur : dans le doute, on bloque et on le DIT) ;
  // `pending` seul ressemblerait à « ça charge », le travers qu'on corrige.
  if ("failed" === periodAnchor.state) {
    return { errors: ["La période n'a pas pu être chargée — impossible de vérifier ses réglages."], warnings: [] };
  }
  if (periodMode && "absent" === periodAnchor.state) {
    return { errors: ["Cette période n'a pas encore d'espace de travail — utilisez « Adapter » pour en créer un."], warnings: [] };
  }
  // Mode période SANS entrée résolue (store rehydraté partiellement) : l'ancre vaut
  // `base`. Sans ce blocage, le verdict sortait VERT — calculé sur le socle — pendant
  // que ConstraintsStep affichait « Aucune période sélectionnée » : deux vérités.
  if (periodMode && "base" === periodAnchor.state) {
    return { errors: ["Aucune période sélectionnée — revenez au calendrier et rouvrez la période."], warnings: [] };
  }
  // Ancre encore en vol : neutre mais bloquant, comme les autres premiers chargements.
  if (periodMode && "loading" === periodAnchor.state) {
    return { errors: [], warnings: [], pending: true };
  }
  // P4-1 au niveau du VERDICT : la grille de période est ces deux requêtes. Un GET raté
  // laissait `[]` passer pour une grille vide et fabriquait un blocage « Gymnase(s) sans
  // créneau » nommant TOUS les gymnases — sur une grille en fait pleine. Le panneau, lui,
  // affichait correctement l'échec : deux écrans, deux vérités.
  if (periodMode && (readFailed(periodSlotsQuery) || readFailed(periodOverridesQuery))) {
    return { errors: ["La grille de la période n'a pas pu être chargée — impossible de vérifier ses créneaux."], warnings: [] };
  }
  if (periodMode && readFailed(periodTeamOverridesQuery)) {
    return { errors: ["La sélection d'équipes de la période n'a pas pu être chargée — impossible de la vérifier."], warnings: [] };
  }
  // Grille encore en vol : neutre mais PENDING — le gate de génération reste fermé.
  // Sans ça, `periodGridReady` faux vidait simplement `emptyVenues` : verdict vert et
  // « Générer » cliquable pendant la fenêtre de chargement d'une grille peut-être vide —
  // la faille exacte que la règle « gymnase sans créneau » existe pour fermer.
  if (periodMode && "period" === periodAnchor.state && (readLoading(periodSlotsQuery) || readLoading(periodOverridesQuery))) {
    return { errors: [], warnings: [], pending: true };
  }

  if ("teams" === stepId) {
    return { errors: 0 === teams.length ? ["Ajoutez au moins une équipe."] : [], warnings: [] };
  }
  // Les gymnases qui bloquent : sur le socle, tout gymnase sans créneau ; en période,
  // tout gymnase ACTIF (non désactivé) sans créneau de la période — un gymnase désactivé
  // n'a volontairement rien à servir.
  const disabledVenueIds = new Set(periodOverrides.filter((o) => "DISABLED" === o.mode).map((o) => o.venueId));
  // En période, on n'évalue la règle QUE lorsque le plan est résolu : sans lui les
  // créneaux de la période ne sont pas encore lus (query désactivée → []), et bloquer là
  // dessus serait un faux « sans créneau » pendant le chargement. Un gymnase désactivé est
  // épargné : son absence de créneau servi est voulue.
  // period_slots vient d'une query SÉPARÉE qui ne démarre qu'une fois planId connu : il
  // faut attendre qu'elle ait CHARGÉ, pas seulement que le plan soit résolu, sinon on
  // confond « pas encore chargé » (undefined→[]) et « vraiment vide » et on crie « sans
  // créneau » sur une grille pleine (revue #8 PR-B round 2).
  // Les DEUX queries portent la grille : `periodSlots` dit les créneaux,
  // `periodOverrides` dit quels gymnases sont désactivés (donc légitimement sans
  // créneau). Armer la règle sur l'une sans l'autre criait « gymnase sans créneau »
  // sur un gymnase volontairement désactivé, le temps que les overrides arrivent.
  const periodGridReady =
    "period" === periodAnchor.state && !readLoading(periodSlotsQuery) && !readLoading(periodOverridesQuery);
  const emptyVenues = periodMode
    ? (periodGridReady ? venuesWithoutSlot(venues, periodSlots).filter((v) => !disabledVenueIds.has(v.id)) : [])
    : venuesWithoutSlot(venues, slots);

  if ("venues" === stepId) {
    const errors: string[] = [];
    if (0 === venues.length) {
      errors.push("Ajoutez au moins un gymnase.");
    }
    if (emptyVenues.length > 0) {
      errors.push(`Gymnase(s) sans créneau : ${emptyVenues.map((v) => v.name).join(", ")}.`);
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
    if (emptyVenues.length > 0) {
      errors.push(`Gymnase(s) sans créneau : ${emptyVenues.map((v) => v.name).join(", ")}.`);
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
    if (constraintNeeded && constraintQuery.isError) {
      // Le pré-solve n'a pas pu tourner : sans ce blocage le verdict sortait vert et la
      // génération partait sans la vérification que ce gate existe pour imposer — pour
      // échouer minutes plus tard en FAILED/INFEASIBLE inexpliqué.
      errors.push("La vérification des contraintes n'a pas pu être effectuée — réessayez avant de générer.");
    }

    return { errors, warnings: [], pending: constraintNeeded && constraintQuery.isLoading };
  }
  return okValidation();
}
