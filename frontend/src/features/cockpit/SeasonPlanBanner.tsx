import { useState } from "react";
import { useNavigate } from "react-router-dom";

import { STATUS_LABELS, type Schedule } from "@/features/planning/api";
import { useWizardStore } from "@/features/wizard/store";
import { Button } from "@/shared/components/ui/button";

import { SeasonSchedulesModal } from "./SeasonSchedulesModal";
import { seasonPlanCounts } from "./seasonPlannings";

interface SeasonPlanBannerProps {
  schedules: Schedule[];
  /** The version the season's plan points at — its calendar in force, or null. */
  chosenScheduleId: string | null;
  /** Whether the plan points at a version (state 3) or not yet (state 2). */
  socleValidated: boolean;
  /** Schedules query still in flight — don't flash "aucun planning principal". */
  loading?: boolean;
}

/** Top strip: the season's main plan at a glance + entry points to consult / edit / list all plans. */
export function SeasonPlanBanner({ schedules, chosenScheduleId, socleValidated, loading = false }: SeasonPlanBannerProps) {
  const navigate = useNavigate();
  const [listOpen, setListOpen] = useState(false);

  const chosen = schedules.find((s) => s.id === chosenScheduleId) ?? null;
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
          {chosen ? (
            <>
              {STATUS_LABELS[chosen.status]}
              {chosen.score !== null ? ` · score ${chosen.score}` : ""}
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

      {listOpen ? <SeasonSchedulesModal schedules={schedules} onClose={() => setListOpen(false)} /> : null}
    </div>
  );
}
