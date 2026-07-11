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
