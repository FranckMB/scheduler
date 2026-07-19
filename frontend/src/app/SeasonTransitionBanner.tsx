import { CalendarPlus } from "lucide-react";

import { useMe } from "@/features/auth/queries";
import { useTransitionUiStore } from "@/shared/stores/transitionUiStore";
import { localIso, seasonYearOf } from "./seasonTransition";

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

  // Fenêtre [15 mai, FIN de la saison courante] (retour fondateur 2026-07-19 :
  // « jusqu'à la fin de la saison », plus le pivot fixe du 15 juillet). Borne haute
  // = endDate réelle QUAND c'est bien la saison courante qui précède le pivot à
  // venir ; sinon (club dormant, saison courante ancienne) on garde le pivot du 15
  // juillet pour continuer à nudger avant CHAQUE pivot.
  const pivotYear = anchorYear + 1;
  const seasonEnd = seasonYearOf(current.startDate) === anchorYear ? current.endDate : `${pivotYear}-07-15`;
  if (todayIso < `${pivotYear}-05-15` || todayIso > seasonEnd) {
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
