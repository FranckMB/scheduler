import type { ReactNode } from "react";

import { cn } from "@/shared/lib/utils";

/**
 * Inline empty-list message ("Aucun…") — the small muted paragraph re-invented
 * across ~14 screens. One home so the empty state reads the same everywhere.
 * (The big Card-style empty screen stays `EmptyState` in PlanningPage — different
 * intent: a whole-view empty, not an inline list.)
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
