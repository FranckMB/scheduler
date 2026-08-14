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
 * « au moins N », « uniquement », bornes horaires, et le « qui » (équipe / coach / `Groupe X` /
 * « Toutes les équipes »). Les jours viennent du foyer unique `@/shared/lib/days`. Deux
 * vocabulaires divergents seraient une nouvelle duplication de vérité.
 *
 * Le wizard compose « qui · prédicat » (jour court, « pas Sam ») à la SAISIE. Ici on rend la
 * MÊME forme « <cible> · <prédicat> » (jour long, « samedi interdit ») : la cible NOMME l'objet
 * corrigeable (l'équipe / le coach visé) au lieu de le reléguer au nom libre en seconde ligne —
 * c'est ce qui rend la boucle de correction cliquable jusqu'au bon objet.
 *
 * 🔴 Le branchement sur `scope` (CLUB / TEAM / COACH) ci-dessous CHOISIT un LIBELLÉ de cible,
 * il ne DÉCIDE PAS si la règle s'applique (ça, c'est `applicableConstraints`, miroir déclaré).
 * De la présentation, donc — exempté à ce titre dans le garde de redérivation front (`.claude/rules/frontend.md`).
 *
 * PORTÉE : on ne décrit QUE ce qu'on sait rendre FIDÈLEMENT. Cible introuvable (équipe/coach
 * supprimé) → on rend le prédicat SEUL, jamais « ? · … ». Prédicat non couvert (clé inconnue,
 * gymnase introuvable, `forcedDays` LEGACY au sens ambigu — ENG-16) → `null` : l'appelant retombe
 * alors sur le `name`. Une description approximative serait le même mensonge sous une autre forme.
 */
type VenueNameFn = (venueId: string) => string | undefined;

/** Résolveurs de NOM pour la cible (`scopeTargetId`) — venant des lookups du planning. */
export interface ConstraintTargetLookups {
  venueName: VenueNameFn;
  teamName: (teamId: string) => string | undefined;
  coachName: (coachId: string) => string | undefined;
}

const capitalize = (s: string): string => ("" === s ? s : s.charAt(0).toUpperCase() + s.slice(1));

const asTime = (v: unknown): string | null => ("string" === typeof v && "" !== v ? v : null);

const asVenueId = (v: unknown): string | null => ("string" === typeof v && "" !== v ? v : null);

/** Jours ISO valides (1-7), triés — on ignore tout ce qui n'est pas un jour de semaine. */
const asDayNums = (v: unknown): number[] =>
  Array.isArray(v) ? [...new Set(v.map(Number).filter((n) => Number.isInteger(n) && n >= 1 && n <= 7))].sort((a, b) => a - b) : [];

const daysPhrase = (nums: number[]): string => nums.map(dayLabelLong).filter((label) => "" !== label).join(", ");

export function describeConstraint(constraint: Constraint, lookups: ConstraintTargetLookups): string | null {
  const predicate = buildPredicate(constraint, lookups.venueName);
  if (null === predicate) {
    return null;
  }
  const target = resolveTarget(constraint, lookups);

  // Cible connue → « <cible> · <prédicat> » (prédicat en clair, minuscule, comme le wizard).
  // Cible introuvable (équipe/coach supprimé) → prédicat SEUL, capitalisé — jamais « ? · … ».
  return null === target ? capitalize(predicate) : `${target} · ${predicate}`;
}

/**
 * Le « qui » d'une contrainte, vocabulaire du wizard (`ConstraintsStep.build()`) : équipe → son
 * nom ; coach → son nom ; CLUB+`targetTag` → `Groupe <tag>` ; CLUB nu → « Toutes les équipes ».
 * Équipe/coach dont le nom ne se résout pas (cible supprimée) → `null` : le prédicat ira seul.
 */
function resolveTarget(constraint: Constraint, lookups: ConstraintTargetLookups): string | null {
  if ("COACH" === constraint.scope) {
    return null !== constraint.scopeTargetId ? (lookups.coachName(constraint.scopeTargetId) ?? null) : null;
  }
  if ("TEAM" === constraint.scope) {
    return null !== constraint.scopeTargetId ? (lookups.teamName(constraint.scopeTargetId) ?? null) : null;
  }
  // CLUB : un groupe (tag) OU tout le club. `targetTag` porte le nom brut, comme le wizard.
  const tag = constraint.config?.targetTag;
  if ("string" === typeof tag && "" !== tag) {
    return `Groupe ${tag}`;
  }
  return "Toutes les équipes";
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
