import type { Schedule } from "../api";

/** Structural subset shared with PlanningPage's LandingSchedule (single predicate owner). */
interface VersionLike {
  status: string;
  createdAt: string;
  calendarEntryId: string | null;
}

/**
 * planning-versions (specs/evolution/planning-versions.md): the selector lists
 * the WORK VERSIONS of one season plan, not named schedules. ARCHIVED versions
 * (siblings set aside at validation) are hidden everywhere.
 */
export function visibleSeasonPlans<T extends VersionLike>(schedules: T[]): T[] {
  return schedules.filter((s) => null === s.calendarEntryId && "ARCHIVED" !== s.status).sort((a, b) => a.createdAt.localeCompare(b.createdAt));
}

/**
 * planning-versions (overlay versions): the visible work versions of ONE period's
 * overlay (same calendarEntryId), ARCHIVED hidden, chronological — the period's
 * own version selector, mirroring visibleSeasonPlans for a season plan.
 */
export function visibleOverlayVersions<T extends VersionLike>(schedules: T[], calendarEntryId: string): T[] {
  return schedules.filter((s) => s.calendarEntryId === calendarEntryId && "ARCHIVED" !== s.status).sort((a, b) => a.createdAt.localeCompare(b.createdAt));
}

/**
 * The version whose structure = the club's CURRENT context (teams, slots,
 * constraints) — i.e. the LATEST generated version, not the one being viewed.
 * The ★ marks THIS one: after adding slots and regenerating (→ V2), the star
 * stays on V2 even while consulting V1, because V2 is the plan that matches the
 * live data (user request). Restricted to the relevant set: the selected
 * overlay's own versions when an overlay is viewed, else the season versions.
 */
export function liveContextScheduleId<T extends VersionLike & { id: string }>(schedules: T[], selectedOverlayEntryId: string | null): string | null {
  const set = null !== selectedOverlayEntryId ? visibleOverlayVersions(schedules, selectedOverlayEntryId) : visibleSeasonPlans(schedules);
  return set.at(-1)?.id ?? null; // sorted createdAt asc → last = latest generated.
}

/**
 * The season version that is the loaded context (★). Prefer the server pointer
 * (Schedule.isLiveContext, re-pointed by "Charger cette version"); fall back to
 * the latest visible season plan when NO visible version carries it — a pre-deploy
 * season (NULL pointer), or a pointer left on a deleted/archived version. Without
 * this fallback the ★ would vanish AND "Charger" would wrongly enable on the
 * already-current version (restoring an old photo → silent data loss).
 */
export function seasonLiveContextId(schedules: Schedule[]): string | null {
  const plans = visibleSeasonPlans(schedules);
  return plans.find((s) => true === s.isLiveContext)?.id ?? plans.at(-1)?.id ?? null;
}

/**
 * The version a PLANNING row should represent (cockpit "Tous les plannings"):
 * the latest FINISHED one (VALIDATED or COMPLETED), so its Eye / Export never
 * target a FAILED or in-flight (PENDING/GENERATING) version — which would open
 * an empty planning or export an empty file. Falls back to the latest visible
 * version only when none has finished yet (a brand-new planning still solving).
 * Input is a visible* set (sorted createdAt asc, ARCHIVED excluded).
 */
export function representativeVersion<T extends VersionLike>(versions: T[]): T | null {
  const finished = versions.filter((s) => "VALIDATED" === s.status || "COMPLETED" === s.status);
  return finished.at(-1) ?? versions.at(-1) ?? null;
}

/** "V3 — 10 juil. 14:32" stamp shared by season and overlay version labels. */
function versionStamp(createdAt: string, index: number): string {
  const date = new Date(createdAt);
  const stamp = date.toLocaleDateString("fr-FR", { day: "numeric", month: "short" }) + " " + date.toLocaleTimeString("fr-FR", { hour: "2-digit", minute: "2-digit" });
  return `V${index + 1} — ${stamp}`;
}

/**
 * Version label "V3 — 10 juil. 14:32": chronological index among ALL season
 * plans (createdAt asc), ARCHIVED included — an archived sibling keeps its
 * number slot, so the version the manager just validated as "V2" is still
 * labelled V2 after its siblings are archived. Numbers only shift on a hard
 * delete (workspace semantics; the date keeps the anchor). An overlay keeps
 * its period title (the caller falls back to schedule.name).
 */
export function versionLabels(schedules: Schedule[]): Map<string, string> {
  const plans = schedules.filter((s) => null === s.calendarEntryId).sort((a, b) => a.createdAt.localeCompare(b.createdAt));
  const labels = new Map<string, string>();
  plans.forEach((plan, i) => labels.set(plan.id, versionStamp(plan.createdAt, i)));
  return labels;
}

/**
 * V{n} labels for the overlay versions of ONE period (chronological, ARCHIVED
 * included so a validated version keeps its number after its siblings are set
 * aside — same stable-numbering rule as season versionLabels).
 */
export function overlayVersionLabels(schedules: Schedule[], calendarEntryId: string): Map<string, string> {
  const versions = schedules.filter((s) => s.calendarEntryId === calendarEntryId).sort((a, b) => a.createdAt.localeCompare(b.createdAt));
  const labels = new Map<string, string>();
  versions.forEach((version, i) => labels.set(version.id, versionStamp(version.createdAt, i)));
  return labels;
}
