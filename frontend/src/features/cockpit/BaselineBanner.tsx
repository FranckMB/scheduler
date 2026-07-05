import { useState } from "react";
import { useNavigate } from "react-router-dom";

import { OverlaysExistError, STATUS_LABELS, type Schedule } from "@/features/planning/api";
import { useReopenSchedule } from "@/features/planning/queries";
import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { toast } from "@/shared/stores/toastStore";

import { SeasonSchedulesModal } from "./SeasonSchedulesModal";

interface BaselineBannerProps {
  schedules: Schedule[];
  baselineScheduleId: string | null;
  /** Number of period overlays in the season (for the proportional edit warning). */
  overlayCount: number;
}

/** Top strip: the season's main plan at a glance + entry points to consult / edit / list all plans. */
export function BaselineBanner({ schedules, baselineScheduleId, overlayCount }: BaselineBannerProps) {
  const navigate = useNavigate();
  const reopen = useReopenSchedule();
  const [listOpen, setListOpen] = useState(false);
  // null = closed; number = the count of overlays a confirmed reopen will delete.
  const [confirmDeleteCount, setConfirmDeleteCount] = useState<number | null>(null);

  const baseline = schedules.find((s) => s.id === baselineScheduleId) ?? null;

  const startEdit = () => {
    if (!baseline || baseline.status !== "VALIDATED") {
      navigate("/planning");
      return;
    }
    // Zero overlay = zero friction (spec §2bis): reopen straight away.
    reopen.mutate(
      { id: baseline.id },
      {
        onSuccess: () => navigate("/planning"),
        onError: (error) => {
          if (error instanceof OverlaysExistError) {
            setConfirmDeleteCount(error.count);
          } else {
            toast.error("Réouverture impossible");
          }
        },
      },
    );
  };

  const confirmDestructiveEdit = () => {
    if (!baseline) {
      return;
    }
    setConfirmDeleteCount(null);
    reopen.mutate(
      { id: baseline.id, confirmDeleteOverlays: true },
      {
        onSuccess: () => navigate("/planning"),
        onError: () => toast.error("Réouverture impossible"),
      },
    );
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
              {overlayCount > 0 ? ` · ${overlayCount} calendrier${overlayCount > 1 ? "s" : ""} secondaire${overlayCount > 1 ? "s" : ""}` : ""}
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
        <Button variant="ghost" size="sm" onClick={startEdit} disabled={reopen.isPending}>
          Modifier…
        </Button>
        <Button variant="ghost" size="sm" onClick={() => setListOpen(true)}>
          Tous les plannings ({schedules.filter((s) => null === s.calendarEntryId).length})
        </Button>
      </div>

      <ConfirmDialog
        open={confirmDeleteCount !== null}
        destructive
        title="Modifier le socle ?"
        description={`Ceci supprimera ${confirmDeleteCount ?? 0} calendrier${(confirmDeleteCount ?? 0) > 1 ? "s" : ""} secondaire${(confirmDeleteCount ?? 0) > 1 ? "s" : ""} (à refaire ensuite).`}
        confirmLabel="Modifier et supprimer"
        onConfirm={confirmDestructiveEdit}
        onCancel={() => setConfirmDeleteCount(null)}
      />

      {listOpen ? <SeasonSchedulesModal schedules={schedules} baselineScheduleId={baselineScheduleId} onClose={() => setListOpen(false)} /> : null}
    </div>
  );
}
