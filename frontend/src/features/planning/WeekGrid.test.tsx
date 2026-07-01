import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import type { Coach, Slot, Team, Venue } from "./api";
import { buildGrid, type Lookups } from "./lib/grid";
import { WeekGrid } from "./WeekGrid";

const lookups: Lookups = {
  teams: new Map<string, Team>([["t1", { id: "t1", name: "U11", sportCategoryId: "c" }]]),
  venues: new Map<string, Venue>([["v1", { id: "v1", name: "Gymnase Alpha", color: "#00aa00" }]]),
  coaches: new Map<string, Coach>(),
};

const slot: Slot = {
  id: "a",
  scheduleId: "s",
  teamId: "t1",
  venueId: "v1",
  coachId: null,
  dayOfWeek: 1,
  startTime: "18:00:00",
  durationMinutes: 90,
  lockLevel: "NONE",
  temporaryLock: false,
};

describe("WeekGrid", () => {
  it("renders day headers, resource and slot; fires selection on click", () => {
    const onSelect = vi.fn();
    const model = buildGrid([slot], "gymnase", lookups);
    render(<WeekGrid model={model} selectedSlotId={null} onSelectSlot={onSelect} />);

    expect(screen.getByText("Lun")).toBeInTheDocument();
    // Only Monday has a slot → only its used gymnase column is rendered (empty columns hidden).
    expect(screen.getAllByText("Gymnase Alpha")).toHaveLength(1);

    const cell = screen.getByText("U11");
    cell.click();
    expect(onSelect).toHaveBeenCalledWith("a");
  });
});
