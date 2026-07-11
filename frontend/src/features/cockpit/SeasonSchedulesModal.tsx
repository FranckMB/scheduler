import { Star } from "lucide-react";
import { useNavigate } from "react-router-dom";

import { STATUS_LABELS, type Schedule } from "@/features/planning/api";
import { versionLabels, visibleSeasonPlans } from "@/features/planning/lib/versions";
import { usePlanningStore } from "@/features/planning/store";
import { Button } from "@/shared/components/ui/button";
import { Modal } from "@/shared/components/ui/modal";

interface SeasonSchedulesModalProps {
  schedules: Schedule[];
  baselineScheduleId: string | null;
  onClose: () => void;
}

/**
 * Lists the season's visible WORK VERSIONS (planning-versions; ARCHIVED hidden),
 * opened in consultation. The main plan (★) is the first validated one — a fact,
 * not a choice — so there is no "set as main" action here.
 */
export function SeasonSchedulesModal({ schedules, baselineScheduleId, onClose }: SeasonSchedulesModalProps) {
  const navigate = useNavigate();
  const setSelectedScheduleId = usePlanningStore((s) => s.setSelectedScheduleId);
  const visible = visibleSeasonPlans(schedules);
  const labels = versionLabels(schedules);

  const consult = (id: string) => {
    setSelectedScheduleId(id);
    navigate("/planning");
  };

  return (
    <Modal label="Plannings de la saison" title="Plannings de la saison" onClose={onClose} className="max-w-lg">
      <ul className="mt-4 max-h-[60vh] space-y-2 overflow-y-auto">
        {visible.map((s) => {
          const isBaseline = s.id === baselineScheduleId;
          return (
            <li key={s.id} className="flex items-center justify-between gap-3 rounded-md border border-border px-3 py-2">
              <div className="min-w-0">
                <p className="flex items-center gap-1.5 truncate text-sm font-medium">
                  {isBaseline ? <Star className="size-3.5 fill-accent text-accent" /> : null}
                  {labels.get(s.id) ?? s.name}
                </p>
                <p className="text-xs text-muted-foreground">
                  {STATUS_LABELS[s.status]}
                  {s.score !== null ? ` · score ${s.score}` : ""}
                  {isBaseline ? " · Principal" : ""}
                </p>
              </div>
              <div className="flex shrink-0 gap-1">
                <Button variant="outline" size="sm" onClick={() => consult(s.id)}>
                  Consulter
                </Button>
              </div>
            </li>
          );
        })}
      </ul>
    </Modal>
  );
}
