import { Lock } from "lucide-react";

import { cn } from "@/shared/lib/utils";

import type { GridModel } from "./lib/grid";

const ROW_HEIGHT = 16; // px per 15-min step (1h = 64px)

/** hex colour → subtle translucent fill; non-hex falls back to no tint. */
function tint(color: string | null): string | undefined {
  if (null !== color && /^#[0-9a-f]{6}$/i.test(color)) {
    return `${color}22`;
  }
  return undefined;
}

interface WeekGridProps {
  model: GridModel;
  selectedSlotId: string | null;
  onSelectSlot: (slotId: string) => void;
  highlightSlotIds?: Set<string>;
}

export function WeekGrid({ model, selectedSlotId, onSelectSlot, highlightSlotIds }: WeekGridProps) {
  const { columns, dayGroups, rows, cells } = model;

  if (0 === columns.length) {
    return (
      <div className="rounded-lg border border-dashed border-border bg-card p-8 text-center text-sm text-muted-foreground">
        Aucun créneau à afficher pour cette sélection.
      </div>
    );
  }

  const gridTemplateColumns = `4rem repeat(${columns.length}, minmax(6rem, 1fr))`;
  const gridTemplateRows = `auto auto repeat(${rows.length}, ${ROW_HEIGHT}px)`;

  return (
    <div className="overflow-x-auto rounded-lg border border-border bg-card">
      <div className="grid text-xs" style={{ gridTemplateColumns, gridTemplateRows }}>
        {/* Corner */}
        <div className="sticky left-0 z-10 border-b border-r border-border bg-card" style={{ gridColumn: 1, gridRow: "1 / 3" }} />

        {/* Day headers — span the day's used resource sub-columns */}
        {dayGroups.map((group) => (
          <div
            key={`day-${group.day}`}
            className="border-b border-l border-border bg-muted px-2 py-1 text-center font-semibold"
            style={{ gridColumn: `${group.startColumn} / span ${group.span}`, gridRow: 1 }}
          >
            {group.label}
          </div>
        ))}

        {/* Resource sub-headers */}
        {columns.map((column, i) => (
          <div
            key={column.key}
            className="flex items-center justify-center gap-1 truncate border-b border-l border-border bg-card px-1 py-1 text-center text-muted-foreground"
            style={{ gridColumn: 2 + i, gridRow: 2 }}
            title={column.label}
          >
            {null !== column.color ? <span className="size-2 shrink-0 rounded-full" style={{ backgroundColor: column.color }} /> : null}
            <span className="truncate">{column.label}</span>
          </div>
        ))}

        {/* Time gutter — label only on half-hours */}
        {rows.map((row, i) => (
          <div
            key={`time-${i}`}
            className={cn("sticky left-0 z-10 border-r border-border bg-card px-1 text-right text-[10px] text-muted-foreground", row.major ? "" : "")}
            style={{ gridColumn: 1, gridRow: 3 + i }}
          >
            {row.label}
          </div>
        ))}

        {/* Row grid lines — stronger on the hour */}
        {rows.map((row, i) => (
          <div
            key={`line-${i}`}
            className={cn("border-l", row.major ? "border-b border-border/70" : "border-b border-border/30")}
            style={{ gridColumn: `2 / span ${columns.length}`, gridRow: 3 + i }}
          />
        ))}

        {/* Slots */}
        {cells.map((cell) => {
          const selected = cell.slotId === selectedSlotId;
          const dimmed = highlightSlotIds && highlightSlotIds.size > 0 && !highlightSlotIds.has(cell.slotId);
          return (
            <button
              key={cell.slotId}
              type="button"
              onClick={() => onSelectSlot(cell.slotId)}
              title={`${cell.teamLabel} · ${cell.venueLabel} · ${cell.startLabel}–${cell.endLabel}`}
              className={cn(
                "z-20 m-px flex flex-col items-start overflow-hidden rounded border-l-4 px-1 py-0.5 text-left leading-tight transition",
                "hover:ring-1 hover:ring-accent",
                selected ? "ring-2 ring-accent" : "",
                dimmed ? "opacity-30" : "",
              )}
              style={{
                gridColumn: cell.gridColumn,
                gridRow: `${cell.gridRowStart} / span ${cell.gridRowSpan}`,
                borderLeftColor: cell.venueColor ?? "var(--accent)",
                backgroundColor: tint(cell.venueColor) ?? "var(--muted)",
              }}
            >
              <span className="flex w-full items-center gap-1 font-medium">
                <span className="truncate">{cell.teamLabel}</span>
                {cell.locked ? <Lock className="size-3 shrink-0 text-muted-foreground" /> : null}
              </span>
              <span className="truncate text-[10px] text-muted-foreground">{cell.startLabel}</span>
            </button>
          );
        })}
      </div>
    </div>
  );
}
