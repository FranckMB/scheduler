import { Lock } from "lucide-react";
import { type UIEvent, useRef } from "react";

import { cn } from "@/shared/lib/utils";

import type { GridModel } from "./lib/grid";

const ROW_HEIGHT = 16; // px per 15-min step (1h = 64px)
const HEADER_ROW = "1.75rem";

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

/**
 * The frozen header row + time column are kept in place by syncing a transform to
 * the scroll offset (CSS vars --sx/--sy). `position: sticky` can't be used here:
 * a sticky grid item is clamped to its own cell, so it detaches once that narrow
 * cell scrolls out of view.
 */
export function WeekGrid({ model, selectedSlotId, onSelectSlot, highlightSlotIds }: WeekGridProps) {
  const { columns, dayGroups, rows, cells } = model;
  const gridRef = useRef<HTMLDivElement>(null);

  if (0 === columns.length) {
    return (
      <div className="rounded-lg border border-dashed border-border bg-card p-8 text-center text-sm text-muted-foreground">
        Aucun créneau à afficher pour cette sélection.
      </div>
    );
  }

  const gridTemplateColumns = `3.25rem repeat(${columns.length}, minmax(4.25rem, 1fr))`;
  const gridTemplateRows = `${HEADER_ROW} ${HEADER_ROW} repeat(${rows.length}, ${ROW_HEIGHT}px)`;

  function onScroll(event: UIEvent<HTMLDivElement>) {
    const el = gridRef.current;
    if (null !== el) {
      el.style.setProperty("--sx", `${event.currentTarget.scrollLeft}px`);
      el.style.setProperty("--sy", `${event.currentTarget.scrollTop}px`);
    }
  }

  const freezeX = { transform: "translateX(var(--sx, 0))" };
  const freezeY = { transform: "translateY(var(--sy, 0))" };
  const freezeXY = { transform: "translate(var(--sx, 0), var(--sy, 0))" };

  return (
    <div className="h-full overflow-auto rounded-lg border border-border bg-card" onScroll={onScroll}>
      <div ref={gridRef} className="grid text-xs" style={{ gridTemplateColumns, gridTemplateRows }}>
        {/* Corner — frozen both axes */}
        <div className="z-40 border-b border-r border-border bg-card" style={{ gridColumn: 1, gridRow: "1 / 3", ...freezeXY }} />

        {/* Day headers — frozen top, span the day's used resource sub-columns */}
        {dayGroups.map((group) => (
          <div
            key={`day-${group.day}`}
            className="z-30 border-b border-l border-border bg-muted px-2 py-1 text-center font-semibold"
            style={{ gridColumn: `${group.startColumn} / span ${group.span}`, gridRow: 1, ...freezeY }}
          >
            {group.label}
          </div>
        ))}

        {/* Resource sub-headers — frozen top */}
        {columns.map((column, i) => (
          <div
            key={column.key}
            className="z-30 flex items-center justify-center gap-1 truncate border-b border-l border-border bg-card px-1 text-center text-muted-foreground"
            style={{ gridColumn: 2 + i, gridRow: 2, ...freezeY }}
            title={column.label}
          >
            {null !== column.color ? <span className="size-2 shrink-0 rounded-full" style={{ backgroundColor: column.color }} /> : null}
            <span className="truncate">{column.label}</span>
          </div>
        ))}

        {/* Time gutter — frozen left, label only on half-hours */}
        {rows.map((row, i) => (
          <div
            key={`time-${i}`}
            className="z-20 border-r border-border bg-card px-1 text-right text-[10px] text-muted-foreground"
            style={{ gridColumn: 1, gridRow: 3 + i, ...freezeX }}
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

        {/* Slots — overlapping ones share the column in side-by-side lanes */}
        {cells.map((cell) => {
          const selected = cell.slotId === selectedSlotId;
          const dimmed = highlightSlotIds && highlightSlotIds.size > 0 && !highlightSlotIds.has(cell.slotId);
          return (
            <button
              key={cell.key}
              type="button"
              onClick={() => onSelectSlot(cell.slotId)}
              title={`${cell.teamLabel} · ${cell.venueLabel} · ${cell.coachLabel} · ${cell.startLabel}–${cell.endLabel}`}
              className={cn(
                "z-10 m-px flex flex-col items-start overflow-hidden rounded border-l-4 px-1 py-0.5 text-left leading-tight transition",
                "hover:ring-1 hover:ring-accent",
                selected ? "ring-2 ring-accent" : "",
                dimmed ? "opacity-30" : "",
              )}
              style={{
                gridColumn: cell.gridColumn,
                gridRow: `${cell.gridRowStart} / span ${cell.gridRowSpan}`,
                justifySelf: "start",
                width: `${100 / cell.laneCount}%`,
                transform: `translateX(${cell.lane * 100}%)`,
                borderLeftColor: cell.venueColor ?? "var(--accent)",
                backgroundColor: tint(cell.venueColor) ?? "var(--muted)",
              }}
            >
              <span className="flex w-full items-center gap-1 font-medium">
                <span className="truncate">{cell.primaryLabel}</span>
                {cell.roleTag ? <span className="shrink-0 rounded-sm bg-accent/20 px-1 text-[9px] text-accent-foreground">{cell.roleTag}</span> : null}
                {cell.locked ? <Lock className="ml-auto size-3 shrink-0 text-muted-foreground" /> : null}
              </span>
              <span className="truncate text-[10px] text-muted-foreground">{cell.secondaryLabel}</span>
            </button>
          );
        })}
      </div>
    </div>
  );
}
