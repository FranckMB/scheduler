import { useState } from "react";
import { useNavigate } from "react-router-dom";

import { STATUS_LABELS, type Schedule } from "@/features/planning/api";
import { useWizardStore } from "@/features/wizard/store";
import { Button } from "@/shared/components/ui/button";

import { SeasonSchedulesModal, seasonPlanCounts } from "./SeasonSchedulesModal";

interface BaselineBannerProps {
  schedules: Schedule[];
  baselineScheduleId: string | null;
  /** Whether the season's socle is validated (state 3) or not yet (state 2). */
  socleValidated: boolean;
  /** Schedules query still in flight — don't flash "aucun planning principal". */
  loading?: boolean;
}

/** Top strip: the season's main plan at a glance + entry points to consult / edit / list all plans. */
export function BaselineBanner({ schedules, baselineScheduleId, socleValidated, loading = false }: BaselineBannerProps) {
  const navigate = useNavigate();
  const [listOpen, setListOpen] = useState(false);

  const baseline = schedules.find((s) => s.id === baselineScheduleId) ?? null;
  // Distinct plannings = the season main plan (1) + one per period overlay
  // (versions are navigated inside the planning, not counted here).
  // Both counts from one derivation (finished period plannings only, consistent
  // with what the modal lists).
  const { total: planCount, overlays: overlayCount } = seasonPlanCounts(schedules);

  // Validated (state 3) → consult the plan. Not yet (state 2) → back to the
  // wizard's generation step to finish/validate it.
  const open = () => {
    if (socleValidated) {
      navigate("/planning");
      return;
    }
    useWizardStore.getState().jumpTo("generate");
    navigate("/wizard");
  };

  return (
    <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-card p-4">
      <div>
        <p className="text-sm font-semibold">Planning principal</p>
        <p className="text-xs text-muted-foreground">
          {baseline ? (
            <>
              {STATUS_LABELS[baseline.status]}
              {baseline.score !== null ? ` · score ${baseline.score}` : ""}
              {overlayCount > 0 ? ` · ${overlayCount} planning${overlayCount > 1 ? "s" : ""} secondaire${overlayCount > 1 ? "s" : ""}` : ""}
            </>
          ) : loading ? (
            "Chargement…"
          ) : (
            "Aucun planning principal désigné"
          )}
        </p>
      </div>
      <div className="flex flex-wrap gap-2">
        {/* Only "Ouvrir": once inside the planning, the manager decides whether to
            modify (the page has its own reopen/validate controls) — no separate
            Modifier here (user request). */}
        <Button variant="outline" size="sm" onClick={open}>
          Ouvrir
        </Button>
        <Button variant="ghost" size="sm" onClick={() => setListOpen(true)}>
          Tous les plannings ({planCount})
        </Button>
      </div>

      {listOpen ? <SeasonSchedulesModal schedules={schedules} baselineScheduleId={baselineScheduleId} onClose={() => setListOpen(false)} /> : null}
    </div>
  );
}
