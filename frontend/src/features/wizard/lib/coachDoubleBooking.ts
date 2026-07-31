import type { Reservation, TeamCoach } from "../api";
import { hhmm } from "./days";

/**
 * P2-9 PR C — « affecter cette équipe ici mettrait-il son coach à deux endroits ? »
 *
 * ⚠️ PARITÉ OBLIGATOIRE avec le backend : `App\Service\CoachDoubleBookingDetector`
 * (P2-9 PR B) applique EXACTEMENT les mêmes règles pour BLOQUER la génération au récap.
 * Ici on prévient au clic ; là-bas on refuse. Deux implémentations sont inévitables (la
 * modale doit répondre sans aller-retour réseau), donc elles sont tenues alignées par des
 * cas de test IDENTIQUES des deux côtés — `coachDoubleBooking.test.ts` ici,
 * `CoachDoubleBookingTest` là-bas. Toute modification d'une règle doit être portée dans
 * les deux, sinon la modale autorise ce que le récap refusera (ou l'inverse, pire : elle
 * interdit un geste légitime).
 *
 * Les trois règles (fondateur, 2026-07-31) :
 *  1. chevauchement sur INTERVALLES RÉELS `[début, début + durée[` — 17h00-18h30 et
 *     17h30-19h00 dédoublent bien le coach, l'égalité des débuts ne suffit pas ;
 *  2. gymnases DIFFÉRENTS seulement — même gymnase = mutualisation voulue (`canSplit`) ;
 *  3. coachs `MAIN` seulement — un `ASSISTANT` est optionnel pour le solveur, l'interdire
 *     refuserait une réservation légitime.
 */

/**
 * Minutes depuis minuit — `startTime` peut porter les secondes selon la source.
 * Rend `null` si l'heure est illisible : un `?? 0` ne protégerait de rien (`Number("x")`
 * vaut NaN, pas `undefined`) et toute comparaison avec NaN étant fausse, la garde
 * s'éteindrait en silence. L'appelant traite explicitement ce cas.
 */
function minutes(time: string): number | null {
  const [h, m] = hhmm(time).split(":").map(Number);
  if (undefined === h || undefined === m || Number.isNaN(h) || Number.isNaN(m)) {
    return null;
  }

  return h * 60 + m;
}

/** teamId → coachId du coach MAIN (les ASSISTANT sont ignorés, règle 3). */
export function mainCoachByTeam(links: TeamCoach[]): Map<string, string> {
  const map = new Map<string, string>();
  for (const link of links) {
    if ("MAIN" === link.role) {
      map.set(link.teamId, link.coachId);
    }
  }

  return map;
}

interface Candidate {
  teamId: string;
  venueId: string;
  dayOfWeek: number;
  startTime: string;
  durationMinutes: number;
}

/**
 * La réservation DÉJÀ posée qui empêche `candidate`, ou null si le geste est légitime.
 * Rendre la réservation fautive (et non un booléen) permet à l'appelant de nommer
 * l'équipe, le gymnase et l'heure — « Emerick coache déjà les U15F à 17h à Debarros ».
 */
export function conflictingReservation(candidate: Candidate, reservations: Reservation[], coachByTeam: Map<string, string>): Reservation | null {
  const coachId = coachByTeam.get(candidate.teamId);
  if (undefined === coachId) {
    return null; // pas de coach MAIN : personne à dédoubler
  }
  const start = minutes(candidate.startTime);
  if (null === start) {
    return null; // heure illisible : on ne peut rien affirmer, le récap tranchera (PR B)
  }

  return (
    reservations.find((other) => {
      // Même équipe : ce n'est pas un dédoublement de COACH (autre sujet).
      if (other.teamId === candidate.teamId) {
        return false;
      }
      if (coachByTeam.get(other.teamId) !== coachId) {
        return false;
      }
      // Règle 2 : même gymnase = mutualisation possible, le coach n'y est qu'une fois.
      if (other.venueId === candidate.venueId) {
        return false;
      }
      if (other.dayOfWeek !== candidate.dayOfWeek) {
        return false;
      }
      // Règle 1 : intervalles demi-ouverts — deux créneaux qui se touchent (fin de l'un =
      // début de l'autre) ne se chevauchent pas.
      const otherStart = minutes(other.startTime);
      if (null === otherStart) {
        return false;
      }

      return start < otherStart + other.durationMinutes && otherStart < start + candidate.durationMinutes;
    }) ?? null
  );
}
