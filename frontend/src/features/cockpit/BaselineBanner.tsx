import { useState } from "react";
import { useNavigate } from "react-router-dom";

import { OverlaysExistError, STATUS_LABELS, type Schedule } from "@/features/planning/api";
import { visibleSeasonPlans } from "@/features/planning/lib/versions";
import { useReopenSchedule } from "@/features/planning/queries";
import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";

import { useWizardStore } from "@/features/wizard/store";

import { SeasonSchedulesModal } from "./SeasonSchedulesModal";

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
  const reopen = useReopenSchedule();
  const [listOpen, setListOpen] = useState(false);
  const [confirmEdit, setConfirmEdit] = useState(false);
  // null = closed; number = the count of overlays a confirmed reopen will delete.
  const [confirmDeleteCount, setConfirmDeleteCount] = useState<number | null>(null);

  const baseline = schedules.find((s) => s.id === baselineScheduleId) ?? null;
  // planning-versions: ARCHIVED versions are invisible — the count and the
  // modal (which filters them itself) must agree.
  const seasonPlans = visibleSeasonPlans(schedules);
  const overlayCount = schedules.filter((s) => null !== s.calendarEntryId).length;

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

  const edit = () => {
    setConfirmEdit(false);
    if (!baseline || baseline.status !== "VALIDATED") {
      navigate("/planning");
      return;
    }
    // Zero overlay = zero friction (spec §2bis): reopen after the plain confirm.
    reopen.mutate(
      { id: baseline.id },
      {
        onSuccess: () => navigate("/planning"),
        // Generic failures are toasted by the hook (unmount-safe); only the
        // 409 escalation is UI state handled here.
        onError: (error) => {
          if (error instanceof OverlaysExistError) {
            setConfirmDeleteCount(error.count);
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
      { onSuccess: () => navigate("/planning") },
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
        <Button variant="outline" size="sm" onClick={open}>
          Ouvrir
        </Button>
        <Button variant="ghost" size="sm" onClick={() => setConfirmEdit(true)} disabled={reopen.isPending}>
          Modifier…
        </Button>
        <Button variant="ghost" size="sm" onClick={() => setListOpen(true)}>
          Tous les plannings ({seasonPlans.length})
        </Button>
      </div>

      {/* First gate: reopening a finalized plan is a real edit — always confirm. */}
      <ConfirmDialog
        open={confirmEdit}
        destructive={false}
        title="Modifier le planning principal ?"
        description={
          overlayCount > 0
            ? `Modifier rouvre l'édition du planning principal et supprimera ${overlayCount} planning${overlayCount > 1 ? "s" : ""} secondaire${overlayCount > 1 ? "s" : ""}.`
            : "Modifier rouvre l'édition du planning principal (il repasse en modifiable)."
        }
        confirmLabel="Modifier"
        onConfirm={edit}
        onCancel={() => setConfirmEdit(false)}
      />

      {/* Second gate: the backend reported overlays (proportional, authoritative count). */}
      <ConfirmDialog
        open={confirmDeleteCount !== null}
        destructive
        title="Supprimer les plannings secondaires ?"
        description={`Ceci supprimera ${confirmDeleteCount ?? 0} planning${(confirmDeleteCount ?? 0) > 1 ? "s" : ""} secondaire${(confirmDeleteCount ?? 0) > 1 ? "s" : ""} (à refaire ensuite).`}
        confirmLabel="Modifier et supprimer"
        onConfirm={confirmDestructiveEdit}
        onCancel={() => setConfirmDeleteCount(null)}
      />

      {listOpen ? <SeasonSchedulesModal schedules={seasonPlans} baselineScheduleId={baselineScheduleId} onClose={() => setListOpen(false)} /> : null}
    </div>
  );
}
