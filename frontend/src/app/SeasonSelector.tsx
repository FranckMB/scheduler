import { useQueryClient } from "@tanstack/react-query";
import { HTTPError } from "ky";
import { CalendarPlus, CalendarRange, Check } from "lucide-react";
import { useEffect, useState } from "react";

import { transitionSeason } from "@/features/auth/api";
import { useMe } from "@/features/auth/queries";
import { RedateEventsDialog } from "@/features/season-transition/RedateEventsDialog";
import { useWizardStore } from "@/features/wizard/store";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { Menu, MenuItem } from "@/shared/components/ui/menu";
import { useSeasonStore } from "@/shared/stores/seasonStore";
import { toast } from "@/shared/stores/toastStore";

/**
 * Header season switcher (transition-de-saison P1). Shows the season the
 * manager is working in; switching resets ALL client state (query cache +
 * wizard) — everything is season-scoped server-side via X-Season-Id.
 * "Préparer la saison suivante" copies the current season's entries into a
 * fresh N+1 draft (confirmed first — structural club action).
 */
export function SeasonSelector() {
  const { data: me } = useMe();
  const queryClient = useQueryClient();
  const selectedSeasonId = useSeasonStore((s) => s.selectedSeasonId);
  const setSelectedSeasonId = useSeasonStore((s) => s.setSelectedSeasonId);
  const exitPeriodMode = useWizardStore((s) => s.exitPeriodMode);
  const [confirmTransition, setConfirmTransition] = useState(false);
  const [transitionPending, setTransitionPending] = useState(false);
  // Re-dating step (P2-PR1): opens after the switch to N+1, listing N's events.
  const [redateContext, setRedateContext] = useState<{ sourceSeasonId: string; targetSeasonId: string; targetSeasonName: string } | null>(null);

  const seasons = me?.seasons ?? [];
  const currentSeasonId = me?.currentSeasonId ?? null;
  const selected = seasons.find((s) => s.id === selectedSeasonId) ?? seasons.find((s) => s.id === currentSeasonId) ?? null;

  // Stale persisted selection (season purged / other club): reset to current.
  const staleSelection = null !== selectedSeasonId && seasons.length > 0 && !seasons.some((s) => s.id === selectedSeasonId);
  useEffect(() => {
    if (staleSelection) {
      setSelectedSeasonId(null);
      queryClient.clear();
    }
  }, [staleSelection, setSelectedSeasonId, queryClient]);

  if (null === selected) {
    return null;
  }

  const switchTo = (id: string) => {
    // Selecting the current season = default state (no header sent).
    setSelectedSeasonId(id === currentSeasonId ? null : id);
    exitPeriodMode();
    queryClient.clear();
  };

  const launchTransition = async () => {
    // Guard a double-click on the confirm button: a second concurrent POST
    // would race the backend successor check (mitigated by an advisory lock,
    // but no reason to fire it).
    if (null === currentSeasonId || transitionPending) return;
    // Capture N BEFORE switching (switchTo clears the query cache; the dialog
    // must not depend on /api/me refetch timing).
    const sourceSeasonId = currentSeasonId;
    setTransitionPending(true);
    try {
      const created = await transitionSeason(sourceSeasonId);
      toast.success(`Saison ${created.name} préparée — structure copiée.`);
      switchTo(created.seasonId);
      setRedateContext({ sourceSeasonId, targetSeasonId: created.seasonId, targetSeasonName: created.name });
    } catch (error) {
      if (error instanceof HTTPError && 409 === error.response.status) {
        // ky 2.x parses the error body into error.data (the stream itself is
        // consumed — response.json() would throw "body stream already read").
        const body = ((error as { data?: unknown }).data ?? null) as { existingSeasonId?: string } | null;
        if (body?.existingSeasonId) {
          toast.success("La saison suivante existe déjà — bascule dessus.");
          switchTo(body.existingSeasonId);
          // Re-opening path: the step re-triggers as long as the draft has no
          // event (the dialog auto-skips otherwise).
          const existingName = seasons.find((s) => s.id === body.existingSeasonId)?.name ?? "la saison suivante";
          setRedateContext({ sourceSeasonId, targetSeasonId: body.existingSeasonId, targetSeasonName: existingName });
          return;
        }
      }
      toast.error("La préparation de la saison suivante a échoué.");
    } finally {
      setTransitionPending(false);
      setConfirmTransition(false);
    }
  };

  const badge = (season: (typeof seasons)[number]): string => {
    if (season.isCurrent) return "en cours";
    if (season.isReadonly) return "lecture seule";
    return "brouillon";
  };

  return (
    <>
      <Menu
        label="Saison de travail"
        trigger={
          <span className="flex items-center gap-1.5 text-sm text-muted-foreground">
            <CalendarRange className="size-4" />
            {selected.name}
          </span>
        }
      >
        {seasons.map((season) => {
          // Read-only (past) seasons are now switchable for CONSULTATION — the
          // server refuses every write on them (SeasonAccessGuard, 409), and
          // the UI shows a read-only banner + disabled write CTAs.
          const isSelected = season.id === selected.id;
          return (
            <MenuItem
              key={season.id}
              disabled={isSelected}
              icon={isSelected ? <Check /> : <CalendarRange />}
              onSelect={() => !isSelected && switchTo(season.id)}
            >
              {`${season.name} · ${badge(season)}`}
            </MenuItem>
          );
        })}
        <MenuItem icon={<CalendarPlus />} onSelect={() => setConfirmTransition(true)}>
          Préparer la saison suivante…
        </MenuItem>
      </Menu>

      <ConfirmDialog
        open={confirmTransition}
        title="Préparer la saison suivante ?"
        description="La structure de la saison en cours (gymnases, équipes, coachs, contraintes permanentes) sera copiée dans une nouvelle saison brouillon, librement modifiable. Le planning généré n'est pas copié."
        confirmLabel={transitionPending ? "Préparation…" : "Préparer"}
        onConfirm={launchTransition}
        onCancel={() => setConfirmTransition(false)}
      />

      {null !== redateContext ? (
        <RedateEventsDialog
          sourceSeasonId={redateContext.sourceSeasonId}
          targetSeasonId={redateContext.targetSeasonId}
          targetSeasonName={redateContext.targetSeasonName}
          onClose={() => setRedateContext(null)}
        />
      ) : null}
    </>
  );
}
