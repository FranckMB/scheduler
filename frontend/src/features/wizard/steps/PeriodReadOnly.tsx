import { Lock } from "lucide-react";

interface Item {
  id: string;
  label: string;
  note?: string;
}

/**
 * Read-only view of an inherited structure step in wizard "period" mode: the
 * club's teams/venues/coaches come from the base plan and cannot be edited here —
 * only the period's constraints (and generation) are actionable (spec §6bis).
 */
export function PeriodReadOnlyStructure({ title, items }: { title: string; items: Item[] }) {
  return (
    <div className="space-y-3">
      <p className="flex items-center gap-2 rounded-md border border-border bg-muted/40 px-3 py-2 text-sm text-muted-foreground">
        <Lock className="size-4 shrink-0" />
        {title} — hérité du planning principal, en lecture seule pour cette période.
      </p>
      {0 === items.length ? (
        <p className="text-sm text-muted-foreground">Aucun élément.</p>
      ) : (
        <ul className="divide-y divide-border rounded-md border border-border">
          {items.map((item) => (
            <li key={item.id} className="flex items-center justify-between px-3 py-2 text-sm">
              <span>{item.label}</span>
              {item.note ? <span className="text-xs font-medium text-destructive">{item.note}</span> : null}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
