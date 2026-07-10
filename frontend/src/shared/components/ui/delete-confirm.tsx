import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";

/** One linked-count line of the impact list ("2 créneaux réservés"). */
export interface ImpactLine {
  count: number;
  /** Singular label (count === 1). */
  one: string;
  /** Plural label (count > 1). */
  many: string;
}

interface DeleteConfirmProps {
  open: boolean;
  /** The thing being deleted, shown in the title + sentence (e.g. a team/coach/venue name). */
  entityName: string;
  /** Linked entities the cascade will also remove; zero-count lines are hidden. */
  impacts: ImpactLine[];
  confirmLabel?: string;
  onConfirm: () => void;
  onCancel: () => void;
}

/**
 * Destructive-delete confirmation that spells out the IMPACT: the manager sees
 * exactly what interlinked data the cascade (backend EntityCascadeDeleter) will
 * remove — "SM1 a 2 créneaux réservés et 3 coach-joueurs liés" — before it
 * happens. Wraps the shared ConfirmDialog; each caller computes its own counts
 * from the query cache. Lines with a zero count are omitted so the dialog only
 * ever states real collateral.
 */
export function DeleteConfirm({ open, entityName, impacts, confirmLabel = "Supprimer", onConfirm, onCancel }: DeleteConfirmProps) {
  const lines = impacts.filter((impact) => impact.count > 0);
  const description =
    lines.length === 0 ? (
      "Cette suppression est définitive."
    ) : (
      <>
        La suppression de « {entityName} » retirera aussi&nbsp;:
        <ul className="mt-2 list-disc space-y-0.5 pl-5">
          {lines.map((line) => (
            <li key={line.one}>
              {line.count} {line.count > 1 ? line.many : line.one}
            </li>
          ))}
        </ul>
      </>
    );

  return (
    <ConfirmDialog
      open={open}
      title={`Supprimer « ${entityName} » ?`}
      description={description}
      confirmLabel={confirmLabel}
      destructive
      onConfirm={onConfirm}
      onCancel={onCancel}
    />
  );
}
