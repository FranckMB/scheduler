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
  plans.forEach((plan, i) => {
    const date = new Date(plan.createdAt);
    const stamp = date.toLocaleDateString("fr-FR", { day: "numeric", month: "short" }) + " " + date.toLocaleTimeString("fr-FR", { hour: "2-digit", minute: "2-digit" });
    labels.set(plan.id, `V${i + 1} — ${stamp}`);
  });
  return labels;
}
