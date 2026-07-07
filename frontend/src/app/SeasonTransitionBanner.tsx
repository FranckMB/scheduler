import { CalendarPlus } from "lucide-react";

import { useMe } from "@/features/auth/queries";
import { useTransitionUiStore } from "@/shared/stores/transitionUiStore";

/**
 * Season year of an ISO date, July-15 pivot — mirrors the backend
 * SeasonResolver::seasonYear (lexicographic month-day comparison is safe on
 * zero-padded ISO strings).
 */
export function seasonYearOf(iso: string): number {
  const year = Number(iso.slice(0, 4));

  return iso.slice(5, 10) >= "07-15" ? year : year - 1;
}

/** Local Y-m-d (never toISOString — the UTC shift can flip the day). */
function localIso(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
}

/**
 * Permanent anticipation banner (transition P2-PR2): from May 15 until the
 * July-15 pivot, while NO season N+1 exists, nudge the manager to prepare the
 * next season. Entirely derived from /api/me (no endpoint); the CTA opens the
 * existing "Préparer la saison suivante" confirm via the shared store. The
 * e-mail cron (app:seasons:remind-transition) is the out-of-app twin.
 * Not dismissible by design — the user asked for a permanent on-screen nudge.
 */
export function SeasonTransitionBanner({ today = new Date() }: { today?: Date }) {
  const { data: me } = useMe();
  const openConfirm = useTransitionUiStore((s) => s.openConfirm);

  const seasons = me?.seasons ?? [];
  const current = seasons.find((s) => s.isCurrent);
  // Preparing a season is a management action (the endpoint 403s otherwise) —
  // never nag members who cannot act on the nudge.
  const isManagement = "owner" === me?.role || "admin" === me?.role;
  if (undefined === current || !isManagement) {
    return null;
  }

  // Anchor on TODAY's season-year, never the current season's: a dormant club
  // whose latest season is years old must still be nudged before EVERY
  // upcoming pivot (mirrors TransitionReminderCommand).
  const todayIso = localIso(today);
  const anchorYear = seasonYearOf(todayIso);
  const successorExists = seasons.some((s) => seasonYearOf(s.startDate) > anchorYear);
  if (successorExists) {
    return null;
  }

  // Window [May 15, July 15[ before the next pivot — the pivot day itself is
  // out (the season has switched; N+1 resolution takes over).
  const pivotYear = anchorYear + 1;
  if (todayIso < `${pivotYear}-05-15` || todayIso >= `${pivotYear}-07-15`) {
    return null;
  }

  return (
    <div className="mb-4 flex items-center gap-2 rounded-md border border-accent/40 bg-accent/10 px-3 py-2 text-sm" role="status">
      <CalendarPlus className="size-4 shrink-0 text-accent" />
      <span className="min-w-0 flex-1">
        La saison <span className="font-medium">{current.name}</span> se termine — préparez la saison suivante avant le 15 juillet.
      </span>
      <button type="button" className="shrink-0 font-medium text-accent hover:underline" onClick={openConfirm}>
        Préparer la saison suivante
      </button>
    </div>
  );
}
