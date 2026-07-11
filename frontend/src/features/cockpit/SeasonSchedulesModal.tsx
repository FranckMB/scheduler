import { Eye, Star } from "lucide-react";
import { useNavigate } from "react-router-dom";

import { STATUS_LABELS, type Schedule } from "@/features/planning/api";
import { ExportMenu } from "@/features/planning/ExportMenu";
import { liveContextScheduleId, visibleOverlayVersions } from "@/features/planning/lib/versions";
import { useVenues } from "@/features/planning/queries";
import { usePlanningStore } from "@/features/planning/store";
import { Button } from "@/shared/components/ui/button";
import { Modal } from "@/shared/components/ui/modal";

interface SeasonSchedulesModalProps {
  schedules: Schedule[];
  baselineScheduleId: string | null;
  onClose: () => void;
}

/** A distinct PLANNING (not a version): the season main plan, or a period overlay. */
interface PlanningRow {
  id: string;
  label: string;
  status: Schedule["status"];
  isBaseline: boolean;
}

/**
 * The season's distinct PLANNINGS — the main season plan (1) plus one per period
 * overlay — NOT their internal versions (V1/V2… are navigated inside the
 * planning). Each is represented by its live-context (latest) version.
 */
function seasonPlannings(schedules: Schedule[], baselineScheduleId: string | null): PlanningRow[] {
  const rows: PlanningRow[] = [];
  const seasonMain = liveContextScheduleId(schedules, null);
  if (null !== seasonMain) {
    const s = schedules.find((x) => x.id === seasonMain);
    if (s) {
      rows.push({ id: s.id, label: "Planning principal", status: s.status, isBaseline: null !== baselineScheduleId });
    }
  }
  // One row per period overlay (its latest version), sorted by period name.
  const periodIds = [...new Set(schedules.filter((s) => null !== s.calendarEntryId).map((s) => s.calendarEntryId as string))];
  for (const entryId of periodIds) {
    const latest = visibleOverlayVersions(schedules, entryId).at(-1);
    if (latest) {
      rows.push({ id: latest.id, label: latest.name, status: latest.status, isBaseline: false });
    }
  }
  return rows;
}

/** The count shown on the banner ("Tous les plannings (N)") — distinct plannings, not versions. */
export function seasonPlanningCount(schedules: Schedule[]): number {
  return seasonPlannings(schedules, null).length;
}

/**
 * Lists the season's distinct PLANNINGS (principal + period overlays), NOT the
 * versions of a plan. Each row consults (eye) or exports the planning directly.
 * The main plan is a fact (★) — no "set as main" here.
 */
export function SeasonSchedulesModal({ schedules, baselineScheduleId, onClose }: SeasonSchedulesModalProps) {
  const navigate = useNavigate();
  const setSelectedScheduleId = usePlanningStore((s) => s.setSelectedScheduleId);
  const { data: venues = [] } = useVenues();
  const rows = seasonPlannings(schedules, baselineScheduleId);

  const consult = (id: string) => {
    setSelectedScheduleId(id);
    navigate("/planning");
  };

  return (
    <Modal label="Plannings de la saison" title="Plannings de la saison" onClose={onClose} className="max-w-lg">
      <ul className="mt-4 max-h-[60vh] space-y-2 overflow-y-auto">
        {rows.map((row) => (
          <li key={row.id} className="flex items-center justify-between gap-3 rounded-md border border-border px-3 py-2">
            <div className="min-w-0">
              <p className="flex items-center gap-1.5 truncate text-sm font-medium">
                {row.isBaseline ? <Star className="size-3.5 fill-accent text-accent" /> : null}
                {row.label}
              </p>
              <p className="text-xs text-muted-foreground">{STATUS_LABELS[row.status]}</p>
            </div>
            <div className="flex shrink-0 items-center gap-1">
              <Button variant="ghost" size="icon" className="size-8" aria-label={`Consulter ${row.label}`} title="Consulter" onClick={() => consult(row.id)}>
                <Eye className="size-4" />
              </Button>
              <ExportMenu scheduleId={row.id} venues={venues} />
            </div>
          </li>
        ))}
      </ul>
    </Modal>
  );
}
