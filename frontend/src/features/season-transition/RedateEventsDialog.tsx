import { useQuery, useQueryClient } from "@tanstack/react-query";
import { useEffect, useMemo, useState } from "react";

import type { CalendarEntry } from "@/features/cockpit/api";
import { errorMessage } from "@/shared/lib/errorMessage";
import { Button } from "@/shared/components/ui/button";
import { Modal } from "@/shared/components/ui/modal";
import { Spinner } from "@/shared/components/ui/spinner";
import { toast } from "@/shared/stores/toastStore";

import { getSeasonEvents, redateEvent } from "./api";
import { NEXT_SEASON_SHIFT_DAYS, shiftIsoDays } from "./lib/dates";

interface RedateEventsDialogProps {
  /** The season the events come from (N). */
  sourceSeasonId: string;
  /** The freshly prepared draft (N+1) the kept events are recreated in. */
  targetSeasonId: string;
  targetSeasonName: string;
  onClose: () => void;
}

interface RowState {
  keep: boolean;
  startDate: string;
  endDate: string;
  error: string | null;
}

const dateClass = "h-8 rounded-md border border-input bg-background px-2 text-xs";

function frDate(iso: string): string {
  return new Date(`${iso}T00:00:00`).toLocaleDateString("fr-FR", { day: "numeric", month: "short", year: "numeric" });
}

/**
 * "Reconduire les événements" step of the season transition (P2-PR1, spec
 * transition-de-saison §6.6): the source season's club events, each with a
 * keep checkbox (default on) and a suggested new date (+364 days = same
 * weekday next year, duration preserved). Kept events are POSTed one by one
 * into the draft via the standard calendar_entries API (X-Season-Id target);
 * a failed row stays listed with its server message. Skippable ("Plus tard"):
 * relaunching "Préparer la saison suivante" reopens the step as long as the
 * draft has no event yet.
 */
export function RedateEventsDialog({ sourceSeasonId, targetSeasonId, targetSeasonName, onClose }: RedateEventsDialogProps) {
  const queryClient = useQueryClient();
  const [rows, setRows] = useState<Map<string, RowState>>(new Map());
  const [submitting, setSubmitting] = useState(false);

  const sourceEvents = useQuery({
    queryKey: ["season-events", sourceSeasonId],
    queryFn: () => getSeasonEvents(sourceSeasonId),
    staleTime: 0,
  });
  const targetEvents = useQuery({
    queryKey: ["season-events", targetSeasonId],
    queryFn: () => getSeasonEvents(targetSeasonId),
    staleTime: 0,
  });

  const loading = sourceEvents.isLoading || targetEvents.isLoading;
  // Nothing to carry over, or the step already happened → close silently.
  const autoSkip = !loading && ((sourceEvents.data ?? []).length === 0 || (targetEvents.data ?? []).length > 0);
  useEffect(() => {
    if (autoSkip) {
      onClose();
    }
  }, [autoSkip, onClose]);

  // Default row per event (kept, +364 days) — user edits overlay these in
  // `rows`; no effect-time seeding (derived state, not synchronized state).
  const defaults = useMemo(() => {
    const map = new Map<string, RowState>();
    for (const event of sourceEvents.data ?? []) {
      map.set(event.id, {
        keep: true,
        startDate: shiftIsoDays(event.startDate, NEXT_SEASON_SHIFT_DAYS),
        endDate: shiftIsoDays(event.endDate, NEXT_SEASON_SHIFT_DAYS),
        error: null,
      });
    }
    return map;
  }, [sourceEvents.data]);

  const rowOf = (id: string): RowState | undefined => rows.get(id) ?? defaults.get(id);

  const patchRow = (id: string, patch: Partial<RowState>): void => {
    setRows((prev) => {
      const base = prev.get(id) ?? defaults.get(id);
      if (undefined === base) {
        return prev;
      }
      const next = new Map(prev);
      next.set(id, { ...base, ...patch });
      return next;
    });
  };

  const events = sourceEvents.data ?? [];
  const keptCount = events.filter((e) => rowOf(e.id)?.keep).length;
  const invalidCount = events.filter((e) => {
    const row = rowOf(e.id);
    return row?.keep && row.endDate < row.startDate;
  }).length;

  const submit = async (): Promise<void> => {
    setSubmitting(true);
    let created = 0;
    let failed = 0;
    for (const event of events) {
      const row = rowOf(event.id);
      if (!row?.keep) {
        continue;
      }
      try {
        await redateEvent(targetSeasonId, {
          title: event.title,
          startDate: row.startDate,
          endDate: row.endDate,
          isDisruptive: event.isDisruptive,
        });
        created += 1;
        patchRow(event.id, { keep: false, error: null });
      } catch (error) {
        failed += 1;
        patchRow(event.id, { error: await errorMessage(error) });
      }
    }
    setSubmitting(false);

    if (created > 0) {
      void queryClient.invalidateQueries({ queryKey: ["calendar-entries"] });
      void queryClient.invalidateQueries({ queryKey: ["season-events", targetSeasonId] });
      toast.success(`${created} événement${created > 1 ? "s" : ""} reconduit${created > 1 ? "s" : ""} dans ${targetSeasonName}.`);
    }
    if (0 === failed) {
      onClose();
    }
  };

  if (autoSkip) {
    return null;
  }

  return (
    <Modal label="Reconduire les événements" title="Reconduire les événements" onClose={onClose}>
      {loading ? (
        <div className="flex justify-center py-8">
          <Spinner />
        </div>
      ) : (
        <div className="flex flex-col gap-3">
          <p className="text-xs text-muted-foreground">
            Les événements du club (AG, tournois…) ne sont pas copiés automatiquement : choisissez ceux à reconduire dans {targetSeasonName} et ajustez leur
            date (proposée un an plus tard, même jour de semaine).
          </p>

          <ul className="flex max-h-72 flex-col gap-2 overflow-y-auto">
            {events.map((event: CalendarEntry) => {
              const row = rowOf(event.id);
              if (!row) {
                return null;
              }
              const invalid = row.keep && row.endDate < row.startDate;
              return (
                <li key={event.id} className="rounded-md border border-border px-3 py-2">
                  <label className="flex items-center gap-2 text-sm">
                    <input type="checkbox" checked={row.keep} onChange={(e) => patchRow(event.id, { keep: e.target.checked })} aria-label={`Garder ${event.title}`} />
                    <span className="min-w-0 flex-1 truncate font-medium">{event.title}</span>
                    <span className="shrink-0 text-xs text-muted-foreground">
                      {frDate(event.startDate)}
                      {event.endDate !== event.startDate ? ` → ${frDate(event.endDate)}` : ""}
                    </span>
                  </label>
                  {row.keep ? (
                    <div className="mt-2 flex items-center gap-2">
                      <input aria-label={`Nouvelle date de début de ${event.title}`} type="date" className={dateClass} value={row.startDate} onChange={(e) => patchRow(event.id, { startDate: e.target.value })} />
                      <span className="text-xs text-muted-foreground">→</span>
                      <input aria-label={`Nouvelle date de fin de ${event.title}`} type="date" className={dateClass} value={row.endDate} onChange={(e) => patchRow(event.id, { endDate: e.target.value })} />
                    </div>
                  ) : null}
                  {invalid ? <p className="mt-1 text-xs text-destructive">La fin doit être après le début.</p> : null}
                  {null !== row.error ? <p className="mt-1 text-xs text-destructive">{row.error}</p> : null}
                </li>
              );
            })}
          </ul>

          <div className="flex justify-end gap-2">
            <Button variant="outline" size="sm" onClick={onClose} disabled={submitting}>
              Plus tard
            </Button>
            <Button size="sm" onClick={() => void submit()} disabled={submitting || 0 === keptCount || invalidCount > 0}>
              {submitting ? "Reconduction…" : `Reconduire ${keptCount} événement${keptCount > 1 ? "s" : ""}`}
            </Button>
          </div>
        </div>
      )}
    </Modal>
  );
}
