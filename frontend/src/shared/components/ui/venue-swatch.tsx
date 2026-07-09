import { cn } from "@/shared/lib/utils";

/**
 * The venue colour dot, shared by the planning + matches grids and the venue
 * editor (was re-implemented inline in three places with divergent sizes). Purely
 * decorative → aria-hidden; the venue name is always the accessible label.
 * Size/border are passed via `className` (default: a small `size-2` dot).
 */
export function VenueSwatch({ color, className }: { color: string | null; className?: string }) {
  return <span aria-hidden className={cn("inline-block size-2 shrink-0 rounded-full", className)} style={{ backgroundColor: color ?? "var(--muted)" }} />;
}
