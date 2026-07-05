import { useState } from "react";
import { useNavigate } from "react-router-dom";

import { STATUS_LABELS, type Schedule } from "@/features/planning/api";
import { useReopenSchedule } from "@/features/planning/queries";
import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { toast } from "@/shared/stores/toastStore";

import { SeasonSchedulesModal } from "./SeasonSchedulesModal";

interface BaselineBannerProps {
  schedules: Schedule[];
  baselineScheduleId: string | null;
}

/** Top strip: the season's main plan at a glance + entry points to consult / edit / list all plans. */
export function BaselineBanner({ schedules, baselineScheduleId }: BaselineBannerProps) {
  const navigate = useNavigate();
  const reopen = useReopenSchedule();
  const [confirmEdit, setConfirmEdit] = useState(false);
  const [listOpen, setListOpen] = useState(false);

  const baseline = schedules.find((s) => s.id === baselineScheduleId) ?? null;

  const edit = () => {
    setConfirmEdit(false);
    if (baseline && baseline.status === "VALIDATED") {
      reopen.mutate(baseline.id, {
        onSuccess: () => navigate("/planning"),
        onError: () => toast.error("Réouverture impossible"),
      });
    } else {
      navigate("/planning");
    }
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
            </>
          ) : (
            "Aucun planning principal désigné"
          )}
        </p>
      </div>
      <div className="flex flex-wrap gap-2">
        <Button variant="outline" size="sm" onClick={() => navigate("/planning")}>
          Ouvrir
        </Button>
        <Button variant="ghost" size="sm" onClick={() => setConfirmEdit(true)} disabled={reopen.isPending}>
          Modifier…
        </Button>
        <Button variant="ghost" size="sm" onClick={() => setListOpen(true)}>
          Tous les plannings ({schedules.length})
        </Button>
      </div>

      <ConfirmDialog
        open={confirmEdit}
        destructive={false}
        title="Modifier le socle ?"
        description="Modifier le planning principal rouvre l'édition. Une fois des calendriers secondaires créés, cela les supprimera."
        confirmLabel="Modifier"
        onConfirm={edit}
        onCancel={() => setConfirmEdit(false)}
      />

      {listOpen ? <SeasonSchedulesModal schedules={schedules} baselineScheduleId={baselineScheduleId} onClose={() => setListOpen(false)} /> : null}
    </div>
  );
}
