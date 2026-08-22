import { CalendarX2, type LucideIcon } from "lucide-react";
import type { ReactNode } from "react";

import { Card, CardDescription, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { cn } from "@/shared/lib/utils";

/**
 * Inline empty-list message ("Aucun…") — the small muted paragraph re-invented
 * across ~14 screens. One home so the empty state reads the same everywhere.
 */
export function EmptyHint({ children, className }: { children: ReactNode; className?: string }) {
  return <p className={cn("text-sm text-muted-foreground", className)}>{children}</p>;
}

/**
 * Dashed-card empty block for a grid/panel with nothing to show yet (the timetable
 * grids re-implemented the exact same markup inline). Sits between the inline
 * `EmptyHint` and PlanningPage's full-view `EmptyState` Card.
 */
export function EmptyBlock({ children, className }: { children: ReactNode; className?: string }) {
  return <div className={cn("rounded-lg border border-dashed border-border bg-card p-8 text-center text-sm text-muted-foreground", className)}>{children}</div>;
}

/**
 * The big Card-style empty for a WHOLE view ("Aucun planning", "Génération en
 * échec") — third tier above `EmptyHint` (inline) and `EmptyBlock` (grid/panel).
 *
 * UXC-17 (P4-117) : il vivait en local dans `PlanningPage` — la note qui disait
 * « il y reste, intent différent » confondait l'intent (vrai : une vue entière
 * vide ≠ une liste vide) et la MAISON : un intent différent mérite sa primitive,
 * pas un composant privé qu'un futur écran vide ré-inventera. `icon` par défaut
 * `CalendarX2` parce que tous les consommateurs du jour sont des vues calendaires ;
 * un écran d'une autre nature passe la sienne.
 */
export function EmptyState({ icon: Icon = CalendarX2, title, description }: { icon?: LucideIcon; title: string; description: string }) {
  return (
    <Card className="border-dashed">
      <CardHeader>
        <div className="flex items-center gap-2">
          <Icon className="size-5 text-muted-foreground" />
          <CardTitle>{title}</CardTitle>
        </div>
        <CardDescription>{description}</CardDescription>
      </CardHeader>
    </Card>
  );
}
