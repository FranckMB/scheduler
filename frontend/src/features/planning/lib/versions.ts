import type { Schedule } from "../api";

/**
 * ADR-0002 C4 : une version est le SOCLE ssi son plan est de type SEASON. Source unique de la
 * comparaison — un overlay est tout ce qui n'est pas SEASON (null = anomalie non liée). Défini
 * ICI (module pur, jamais mocké) et non dans ./api que plusieurs tests remplacent par un mock manuel.
 */
export const isSeasonPlanType = (planType: string | null | undefined): boolean => "SEASON" === planType;

/** Structural subset shared with PlanningPage's LandingSchedule (single predicate owner). */
interface VersionLike {
  id: string;
  status: string;
  createdAt: string;
  /** ADR-0002 C4: "SEASON" = socle, else a period overlay (replaces calendarEntryId). */
  planType: string | null;
  /** ADR-0002: versions of one plan share it — the grouping key (replaces calendarEntryId). */
  schedulePlanId: string | null;
  /** Server pointer (season plans only) — the loaded-context version (★). */
  isLiveContext?: boolean;
}

/** ADR-0002 C4: a version is the SOCLE iff its plan is the SEASON plan. */
const isSeasonVersion = (v: VersionLike): boolean => isSeasonPlanType(v.planType);

/** Une version en cours de solve bloque la validation (409) au lieu d'être supprimée. */
const IN_FLIGHT_STATUS = ["PENDING", "GENERATING"];

/**
 * Les versions que valider `selected` va SUPPRIMER — miroir exact de la règle du
 * serveur (`ValidateScheduleController`) : même portée = MÊME PLAN (`schedulePlanId` ;
 * les versions de saison partagent le plan SEASON, celles d'une période son plan),
 * soi-même exclu, et une version en vol bloque la validation plutôt que d'être emportée.
 *
 * À garder aligné sur le serveur : c'est ce compte qu'on montre AVANT une
 * destruction irréversible. Le compter dans une autre portée, ou en écarter la
 * version en vigueur (elle est supprimée comme les autres), fait consentir le
 * gestionnaire à moins que ce qu'il perd.
 */
/**
 * La version qui REPRÉSENTE un plan : celle qu'il pointe si le gestionnaire en a
 * choisi une (c'est le planning en vigueur, la seule qui compte), sinon la dernière
 * terminée — le plan est alors un espace de travail et « le planning », c'est l'état
 * le plus abouti. Prendre la plus récente sans regarder le pointeur ferait désigner
 * une version que le plan ne pointe pas, puis lire `isChosen: false` dessus.
 *
 * Entrée : un ensemble visible* (trié createdAt asc) d'UN seul plan.
 */
export function planRepresentative<T extends VersionLike & { isChosen?: boolean }>(versions: T[]): T | null {
  return versions.find((v) => true === v.isChosen) ?? representativeVersion(versions);
}

export function versionsDeletedByValidating<T extends VersionLike>(schedules: T[], selected: T): T[] {
  return schedules.filter(
    (s) => s.id !== selected.id
      && s.schedulePlanId === selected.schedulePlanId
      && !IN_FLIGHT_STATUS.includes(s.status),
  );
}

/**
 * ADR-0002: the selector lists the WORK VERSIONS of one season plan, not named
 * schedules — the plan's NAME lives on the plan. Nothing to hide any more:
 * validating deletes the siblings outright instead of archiving them.
 */
export function visibleSeasonPlans<T extends VersionLike>(schedules: T[]): T[] {
  return schedules.filter(isSeasonVersion).sort((a, b) => a.createdAt.localeCompare(b.createdAt));
}

/**
 * The visible work versions of ONE period's overlay (same plan), chronological —
 * the period's own version selector, mirroring visibleSeasonPlans.
 */
export function visibleOverlayVersions<T extends VersionLike>(schedules: T[], schedulePlanId: string): T[] {
  return schedules.filter((s) => s.schedulePlanId === schedulePlanId).sort((a, b) => a.createdAt.localeCompare(b.createdAt));
}

/**
 * The version that is the loaded context (★): the one whose structure is live.
 * Prefer the server pointer (Schedule.isLiveContext, re-pointed by "Charger cette
 * version"), else fall back to the latest visible version — a pre-deploy season
 * (NULL pointer) or a pointer left on a deleted/archived version. Without the
 * fallback the ★ would vanish AND "Charger" would wrongly enable on the already-
 * current version (restoring an old photo → silent data loss). Restricted to the
 * relevant set: the selected overlay's own versions when an overlay is viewed
 * (overlays carry no pointer → always the latest), else the season versions.
 */
export function liveContextScheduleId<T extends VersionLike & { id: string }>(schedules: T[], selectedOverlayPlanId: string | null): string | null {
  const set = null !== selectedOverlayPlanId ? visibleOverlayVersions(schedules, selectedOverlayPlanId) : visibleSeasonPlans(schedules);
  return set.find((s) => true === s.isLiveContext)?.id ?? set.at(-1)?.id ?? null;
}

/**
 * The version a PLANNING row should represent (cockpit "Tous les plannings"):
 * the latest FINISHED one (COMPLETED), so its Eye / Export never
 * target a FAILED or in-flight (PENDING/GENERATING) version — which would open
 * an empty planning or export an empty file. Returns null when nothing has
 * finished yet (a brand-new planning still solving): there is no plan to consult
 * or export, so the row is omitted rather than pointing at an in-flight version.
 * Input is a visible* set (sorted createdAt asc).
 */
export function representativeVersion<T extends VersionLike>(versions: T[]): T | null {
  const finished = versions.filter((s) => "COMPLETED" === s.status);
  return finished.at(-1) ?? null;
}

/** "V3 — 10 juil. 14:32" stamp shared by season and overlay version labels. */
function versionStamp(createdAt: string, index: number): string {
  const date = new Date(createdAt);
  const stamp = date.toLocaleDateString("fr-FR", { day: "numeric", month: "short" }) + " " + date.toLocaleTimeString("fr-FR", { hour: "2-digit", minute: "2-digit" });
  return `V${index + 1} — ${stamp}`;
}

/**
 * Version label "V3 — 10 juil. 14:32": chronological index among the plan's
 * season versions (createdAt asc). Numbers shift when a version is deleted —
 * workspace semantics, and the date keeps the anchor. Validating collapses the
 * plan to the single version it points at, which is then simply V1. An overlay
 * keeps its period title (the caller falls back to schedule.name).
 */
export function versionLabels(schedules: Schedule[]): Map<string, string> {
  const plans = schedules.filter(isSeasonVersion).sort((a, b) => a.createdAt.localeCompare(b.createdAt));
  const labels = new Map<string, string>();
  plans.forEach((plan, i) => labels.set(plan.id, versionStamp(plan.createdAt, i)));
  return labels;
}

/**
 * V{n} labels for the overlay versions of ONE period's plan (chronological) — same
 * rule as season versionLabels.
 */
export function overlayVersionLabels(schedules: Schedule[], schedulePlanId: string): Map<string, string> {
  const versions = schedules.filter((s) => s.schedulePlanId === schedulePlanId).sort((a, b) => a.createdAt.localeCompare(b.createdAt));
  const labels = new Map<string, string>();
  versions.forEach((version, i) => labels.set(version.id, versionStamp(version.createdAt, i)));
  return labels;
}
