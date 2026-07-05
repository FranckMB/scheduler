import { Star, X } from "lucide-react";
import { useEffect } from "react";
import { createPortal } from "react-dom";
import { useNavigate } from "react-router-dom";

import { STATUS_LABELS, type Schedule } from "@/features/planning/api";
import { useSetBaseline } from "@/features/planning/queries";
import { usePlanningStore } from "@/features/planning/store";
import { Button } from "@/shared/components/ui/button";
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

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") onClose();
    };
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [onClose]);

  const consult = (id: string) => {
    setSelectedScheduleId(id);
    navigate("/planning");
  };

  return createPortal(
    <div className="fixed inset-0 z-[90] flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-black/50" aria-hidden="true" onClick={onClose} />
      <div role="dialog" aria-modal="true" aria-label="Plannings de la saison" className="relative w-full max-w-lg rounded-lg border border-border bg-card p-5 text-card-foreground shadow-xl">
        <div className="flex items-center justify-between">
          <h2 className="text-base font-semibold">Plannings de la saison</h2>
          <button type="button" aria-label="Fermer" className="rounded p-1 text-muted-foreground hover:text-foreground" onClick={onClose}>
            <X className="size-4" />
          </button>
        </div>

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
                    {isBaseline ? " · Principal" : ""}
                  </p>
                </div>
                <div className="flex shrink-0 gap-1">
                  <Button variant="outline" size="sm" onClick={() => consult(s.id)}>
                    Consulter
                  </Button>
                  {!isBaseline && (s.status === "COMPLETED" || s.status === "VALIDATED") ? (
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
      </div>
    </div>,
    document.body,
  );
}
