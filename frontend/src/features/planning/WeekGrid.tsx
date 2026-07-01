import { Lock } from "lucide-react";

import { cn } from "@/shared/lib/utils";

import type { GridModel } from "./lib/grid";

const ROW_HEIGHT = 30; // px per 30-min step

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
  const { days, resources, rowLabels, cells } = model;
  const resCount = resources.length;

  const gridTemplateColumns = `4rem repeat(${days.length * resCount}, minmax(6rem, 1fr))`;
  const gridTemplateRows = `auto auto repeat(${rowLabels.length}, ${ROW_HEIGHT}px)`;

  return (
    <div className="overflow-x-auto rounded-lg border border-border bg-card">
      <div className="grid text-xs" style={{ gridTemplateColumns, gridTemplateRows }}>
        {/* Corner */}
        <div className="sticky left-0 z-10 border-b border-r border-border bg-card" style={{ gridColumn: 1, gridRow: "1 / 3" }} />

        {/* Day headers — each spans its resource sub-columns */}
        {days.map((day, dayIndex) => (
          <div
            key={`day-${day.n}`}
            className="border-b border-l border-border bg-muted px-2 py-1 text-center font-semibold"
            style={{ gridColumn: `${2 + dayIndex * resCount} / span ${resCount}`, gridRow: 1 }}
          >
            {day.label}
          </div>
        ))}

        {/* Resource sub-headers under each day */}
        {days.map((day, dayIndex) =>
          resources.map((res, ri) => (
            <div
              key={`res-${day.n}-${res.id}`}
              className="truncate border-b border-l border-border bg-card px-1 py-1 text-center text-muted-foreground"
              style={{ gridColumn: 2 + dayIndex * resCount + ri, gridRow: 2 }}
              title={res.label}
            >
              {res.label}
            </div>
          )),
        )}

        {/* Time gutter */}
        {rowLabels.map((label, i) => (
          <div
            key={`time-${label}`}
            className="sticky left-0 z-10 border-r border-border bg-card px-1 text-right text-[10px] text-muted-foreground"
            style={{ gridColumn: 1, gridRow: 3 + i }}
          >
            {label}
          </div>
        ))}

        {/* Row grid lines (visual guide, one span per day-group) */}
        {rowLabels.map((label, i) => (
          <div
            key={`line-${label}`}
            className="border-b border-l border-border/50"
            style={{ gridColumn: `2 / span ${days.length * resCount}`, gridRow: 3 + i }}
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
