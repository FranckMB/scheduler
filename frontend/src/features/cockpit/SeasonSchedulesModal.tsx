import { Download, Eye, Loader2, Star } from "lucide-react";
import { useState } from "react";
import { useNavigate } from "react-router-dom";

import { STATUS_LABELS, type Schedule } from "@/features/planning/api";
import { representativeVersion, visibleOverlayVersions, visibleSeasonPlans } from "@/features/planning/lib/versions";
import { type ExportFormat, useScheduleExport } from "@/features/planning/queries";
import { usePlanningStore } from "@/features/planning/store";
import { useWizardStore } from "@/features/wizard/store";
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
  isOverlay: boolean;
}

/**
 * The season's distinct PLANNINGS — the main season plan (1) plus one per period
 * overlay — NOT their internal versions (V1/V2… are navigated inside the
 * planning). Each is represented by its latest FINISHED version, so its
 * Eye/Export never target a failed or in-flight one.
 */
function seasonPlannings(schedules: Schedule[], baselineScheduleId: string | null): PlanningRow[] {
  const rows: PlanningRow[] = [];
  const seasonMain = representativeVersion(visibleSeasonPlans(schedules));
  if (null !== seasonMain) {
    rows.push({ id: seasonMain.id, label: "Planning principal", status: seasonMain.status, isBaseline: null !== baselineScheduleId, isOverlay: false });
  }
  // One row per period overlay (its latest finished version), sorted by period name.
  const periodIds = [...new Set(schedules.filter((s) => null !== s.calendarEntryId).map((s) => s.calendarEntryId as string))];
  const periods: PlanningRow[] = [];
  for (const entryId of periodIds) {
    const version = representativeVersion(visibleOverlayVersions(schedules, entryId));
    if (null !== version) {
      periods.push({ id: version.id, label: version.name, status: version.status, isBaseline: false, isOverlay: true });
    }
  }
  periods.sort((a, b) => a.label.localeCompare(b.label));

  return [...rows, ...periods];
}

/** The count shown on the banner ("Tous les plannings (N)") — distinct plannings, not versions. */
export function seasonPlanningCount(schedules: Schedule[]): number {
  return seasonPlannings(schedules, null).length;
}

/**
 * Distinct PERIOD plannings that have a finished (consultable) version — matches
 * exactly what the modal lists, so the banner's "N planning secondaire" never
 * advertises a period the modal omits (e.g. one still mid-first-generation).
 */
export function seasonOverlayCount(schedules: Schedule[]): number {
  return seasonPlannings(schedules, null).filter((row) => row.isOverlay).length;
}

const EXPORT_FORMATS: { key: ExportFormat; label: string }[] = [
  { key: "pdf", label: "PDF" },
  { key: "xlsx", label: "Excel" },
  { key: "png", label: "PNG" },
];

/**
 * Compact export: an icon-only trigger that expands an INLINE format row (no
 * absolute dropdown — it would be clipped by the modal's scroll container). The
 * export always covers every gym (venue scope lives on the planning page).
 */
function CompactExport({ scheduleId, label }: { scheduleId: string; label: string }) {
  const [open, setOpen] = useState(false);
  const { run, busy } = useScheduleExport(scheduleId);

  return (
    <div className="flex items-center gap-1">
      <Button variant="ghost" size="icon" className="size-8" aria-haspopup="menu" aria-expanded={open} aria-label={`Exporter ${label}`} title="Exporter" onClick={() => setOpen((o) => !o)}>
        <Download className="size-4" />
      </Button>
      {open ? (
        <div role="menu" className="flex items-center gap-1">
          {EXPORT_FORMATS.map(({ key, label: fmt }) => (
            <button
              key={key}
              type="button"
              role="menuitem"
              disabled={null !== busy}
              onClick={() => void run(key, null)}
              className="flex items-center gap-1 rounded-md border border-border px-2 py-1 text-xs font-medium hover:bg-muted disabled:opacity-50"
            >
              {busy === key ? <Loader2 className="size-3 animate-spin" /> : null}
              {fmt}
            </button>
          ))}
        </div>
      ) : null}
    </div>
  );
}

/**
 * Lists the season's distinct PLANNINGS (principal + period overlays), NOT the
 * versions of a plan. Each row consults (eye) or exports the planning directly.
 * The main plan is a fact (★) — no "set as main" here.
 */
export function SeasonSchedulesModal({ schedules, baselineScheduleId, onClose }: SeasonSchedulesModalProps) {
  const navigate = useNavigate();
  const setSelectedScheduleId = usePlanningStore((s) => s.setSelectedScheduleId);
  const rows = seasonPlannings(schedules, baselineScheduleId);

  // Routing like the banner's "Ouvrir": a period overlay or a VALIDATED season
  // plan is a finished plan → open it read-only on the planning page. Only a
  // season plan still IN PROGRESS (COMPLETED-not-yet-validated) opens the
  // wizard's generation step to finish/validate it — an overlay is never sent
  // there (the wizard's generate step renders the season plan, not the overlay).
  const consult = (row: PlanningRow) => {
    if (row.isOverlay || "VALIDATED" === row.status) {
      setSelectedScheduleId(row.id);
      navigate("/planning");
      return;
    }
    useWizardStore.getState().jumpTo("generate");
    navigate("/wizard");
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
              <Button variant="ghost" size="icon" className="size-8" aria-label={`Consulter ${row.label}`} title="Consulter" onClick={() => consult(row)}>
                <Eye className="size-4" />
              </Button>
              <CompactExport scheduleId={row.id} label={row.label} />
            </div>
          </li>
        ))}
      </ul>
    </Modal>
  );
}
