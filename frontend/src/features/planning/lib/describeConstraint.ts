import { dayLabelLong } from "@/shared/lib/days";

import type { Constraint } from "../api";

/**
 * Ce qu'une contrainte FAIT, dérivé de `family` + `config` — jamais de son `name`, qui est un
 * texte LIBRE saisi par le gestionnaire (périmé, faux, ou copié d'une autre règle). Le panneau
 * de créneau (`SlotDetail`) l'affiche pour que la vérification soit POSSIBLE sans confiance : le
 * fondateur lisait « SM2 au moins 1 seance a Mateo » sur un créneau U11 (une règle DAY/samedi
 * interdit mal nommée) et concluait, à raison, que l'app mentait.
 *
 * 🔴 PRÉSENTATION, PAS RÈGLE MÉTIER (`.claude/rules/frontend.md`) : décrire ce qu'une règle fait
 * est autorisé ; décider SI elle s'applique est interdit au front (c'est `applicableConstraints`,
 * miroir déclaré du backend). Ce module ne fait que traduire une config en français.
 *
 * ⚠ Le vocabulaire est le MÊME que celui de l'auto-nommage du wizard
 * (`features/wizard/steps/ConstraintsStep.tsx` `build()`) — verbes gymnase (préfère/évite/impose),
 * « au moins N », « uniquement », bornes horaires — et les jours viennent du foyer unique
 * `@/shared/lib/days`. Deux vocabulaires divergents seraient une nouvelle duplication de vérité.
 * Le wizard compose « qui · prédicat » (jour court, « pas Sam ») à la SAISIE ; ici on rend le
 * prédicat seul, en clair (jour long, « samedi interdit »), le « qui » étant porté par le groupe
 * du panneau (« Cette équipe » / « Tout le club »).
 *
 * PORTÉE : on ne décrit QUE ce qu'on sait rendre FIDÈLEMENT. Toute combinaison non couverte (clé
 * inconnue, gymnase introuvable, `forcedDays` LEGACY au sens ambigu — ENG-16) rend `null` :
 * l'appelant retombe alors sur le `name`. Une description approximative serait le même mensonge
 * sous une autre forme.
 */
type VenueNameFn = (venueId: string) => string | undefined;

const capitalize = (s: string): string => ("" === s ? s : s.charAt(0).toUpperCase() + s.slice(1));

const asTime = (v: unknown): string | null => ("string" === typeof v && "" !== v ? v : null);

const asVenueId = (v: unknown): string | null => ("string" === typeof v && "" !== v ? v : null);

/** Jours ISO valides (1-7), triés — on ignore tout ce qui n'est pas un jour de semaine. */
const asDayNums = (v: unknown): number[] =>
  Array.isArray(v) ? [...new Set(v.map(Number).filter((n) => Number.isInteger(n) && n >= 1 && n <= 7))].sort((a, b) => a - b) : [];

const daysPhrase = (nums: number[]): string => nums.map(dayLabelLong).filter((label) => "" !== label).join(", ");

export function describeConstraint(constraint: Constraint, venueName: VenueNameFn): string | null {
  const predicate = buildPredicate(constraint, venueName);

  return null === predicate ? null : capitalize(predicate);
}

function buildPredicate(constraint: Constraint, venueName: VenueNameFn): string | null {
  const cfg = constraint.config ?? {};
  switch (constraint.family) {
    case "TIME":
      return timePredicate(cfg);
    case "DAY":
      return dayPredicate(cfg);
    case "FACILITY":
      return facilityPredicate(cfg, venueName);
    case "COACH_AVAILABILITY":
      return coachPredicate(cfg);
    default:
      return null;
  }
}

function timePredicate(cfg: Record<string, unknown>): string | null {
  // Même ordre que le wizard : « pas après » d'abord (la borne la plus lue), puis « pas
  // avant », puis « fini avant » (fin de séance, toujours dure côté engine).
  const parts = [
    asTime(cfg.maxStartTime) && `pas après ${asTime(cfg.maxStartTime)}`,
    asTime(cfg.minStartTime) && `pas avant ${asTime(cfg.minStartTime)}`,
    asTime(cfg.maxEndTime) && `fini avant ${asTime(cfg.maxEndTime)}`,
  ].filter((p): p is string => "string" === typeof p);

  return 0 === parts.length ? null : parts.join(", ");
}

function dayPredicate(cfg: Record<string, unknown>): string | null {
  const forbidden = asDayNums(cfg.forbiddenDays);
  if (forbidden.length > 0) {
    return `${daysPhrase(forbidden)} interdit${forbidden.length > 1 ? "s" : ""}`;
  }
  // whitelist (`allowedDays`) : seuls ces jours sont permis. Le LEGACY `forcedDays` (avant
  // ENG-16) a le MÊME sens côté wizard mais désigne « au moins une séance ces jours » côté
  // engine — ambigu, donc non décrit : on retombe sur le nom plutôt que de risquer le contresens.
  const allowed = asDayNums(cfg.allowedDays);

  return allowed.length > 0 ? `uniquement ${daysPhrase(allowed)}` : null;
}

function facilityPredicate(cfg: Record<string, unknown>, venueName: VenueNameFn): string | null {
  const named = (id: string | null, verb: (name: string) => string): string | null => {
    if (null === id) {
      return null;
    }
    const name = venueName(id);

    // Gymnase introuvable (désactivé, données antérieures) → null : jamais « préfère undefined ».
    return undefined === name ? null : verb(name);
  };

  const forced = asVenueId(cfg.forcedVenueId);
  if (null !== forced) {
    return named(forced, (name) => `impose ${name}`);
  }
  const minAt = asVenueId(cfg.minAtVenueId);
  if (null !== minAt) {
    const count = Number.isInteger(cfg.minAtVenueCount) && (cfg.minAtVenueCount as number) > 0 ? (cfg.minAtVenueCount as number) : 1;

    return named(minAt, (name) => `au moins ${count} séance${count > 1 ? "s" : ""} à ${name}`);
  }
  const forbidden = asVenueId(cfg.forbiddenVenueId);
  if (null !== forbidden) {
    return named(forbidden, (name) => `évite ${name}`);
  }
  const preferred = asVenueId(cfg.preferredVenueId);

  return null !== preferred ? named(preferred, (name) => `préfère ${name}`) : null;
}

function coachPredicate(cfg: Record<string, unknown>): string | null {
  const from = asTime(cfg.fromTime);
  const until = asTime(cfg.untilTime);
  const window = from && until ? ` de ${from} à ${until}` : from ? ` à partir de ${from}` : until ? ` jusqu'à ${until}` : "";

  const available = asDayNums(cfg.availableDays);
  if (available.length > 0) {
    return `disponible uniquement ${daysPhrase(available)}${window}`;
  }
  const unavailable = asDayNums(cfg.unavailableDays);

  return unavailable.length > 0 ? `indisponible ${daysPhrase(unavailable)}${window}` : null;
}
