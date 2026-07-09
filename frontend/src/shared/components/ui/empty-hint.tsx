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
