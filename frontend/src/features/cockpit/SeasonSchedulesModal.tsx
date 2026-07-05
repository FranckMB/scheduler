import { Star } from "lucide-react";
import { useNavigate } from "react-router-dom";

import { STATUS_LABELS, type Schedule } from "@/features/planning/api";
import { useSetBaseline } from "@/features/planning/queries";
import { usePlanningStore } from "@/features/planning/store";
import { Button } from "@/shared/components/ui/button";
import { Modal } from "@/shared/components/ui/modal";
import { toast } from "@/shared/stores/toastStore";

interface SeasonSchedulesModalProps {
  schedules: Schedule[];
  baselineScheduleId: string | null;
  onClose: () => void;
}

/** Lists every schedule of the season (spec §5bis: listing = modal). Open one in read-only consultation, or set it as the main plan. */
export function SeasonSchedulesModal({ schedules, baselineScheduleId, onClose }: SeasonSchedulesModalProps) {
  const navigate = useNavigate();
  const setSelectedScheduleId = usePlanningStore((s) => s.setSelectedScheduleId);
  const setBaseline = useSetBaseline();

  const consult = (id: string) => {
    setSelectedScheduleId(id);
    navigate("/planning");
  };

  return (
    <Modal label="Plannings de la saison" title="Plannings de la saison" onClose={onClose} className="max-w-lg">
      <ul className="mt-4 max-h-[60vh] space-y-2 overflow-y-auto">
        {schedules.map((s) => {
          const isBaseline = s.id === baselineScheduleId;
          return (
            <li key={s.id} className="flex items-center justify-between gap-3 rounded-md border border-border px-3 py-2">
              <div className="min-w-0">
                <p className="flex items-center gap-1.5 truncate text-sm font-medium">
                  {isBaseline ? <Star className="size-3.5 fill-accent text-accent" /> : null}
                  {s.name}
                </p>
                <p className="text-xs text-muted-foreground">
                  {STATUS_LABELS[s.status]}
                  {s.score !== null ? ` · score ${s.score}` : ""}
                  {isBaseline ? " · Principal" : null !== s.calendarEntryId ? " · Période" : ""}
                </p>
              </div>
              <div className="flex shrink-0 gap-1">
                <Button variant="outline" size="sm" onClick={() => consult(s.id)}>
                  Consulter
                </Button>
                {!isBaseline && null === s.calendarEntryId && (s.status === "COMPLETED" || s.status === "VALIDATED") ? (
                  <Button
                    variant="ghost"
                    size="sm"
                    disabled={setBaseline.isPending}
                    onClick={() => setBaseline.mutate(s.id, { onSuccess: () => toast.success("Planning principal mis à jour"), onError: () => toast.error("Action impossible") })}
                  >
                    Définir principal
                  </Button>
                ) : null}
              </div>
            </li>
          );
        })}
      </ul>
    </Modal>
  );
}
