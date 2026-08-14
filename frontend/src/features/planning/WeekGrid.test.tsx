import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { axe } from "vitest-axe";

import type { Coach, Slot, Team, Venue } from "./api";
import { buildGrid, type Lookups } from "./lib/grid";
import { WeekGrid } from "./WeekGrid";

const lookups: Lookups = {
  teams: new Map<string, Team>([["t1", { id: "t1", name: "U11", sportCategoryId: "c", priorityTierId: 1, tierOrder: 0 }]]),
  venues: new Map<string, Venue>([["v1", { id: "v1", name: "Gymnase Alpha", color: "#00aa00" }]]),
  coaches: new Map<string, Coach>(),
  teamCoach: new Map<string, string>(),
  teamPlayerCoaches: new Map<string, string[]>(),
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
  lockOrigin: null,
  temporaryLock: false,
};

describe("WeekGrid", () => {
  it("renders day headers, resource and slot; fires selection on click", () => {
    const onSelect = vi.fn();
    const model = buildGrid([slot], "gymnase", lookups);
    const { container } = render(<WeekGrid model={model} selectedSlotId={null} onSelectSlot={onSelect} />);

    expect(screen.getByText("Lun")).toBeInTheDocument();
    // Only Monday has a slot → only its used gymnase column is rendered (empty columns hidden).
    expect(screen.getAllByText("Gymnase Alpha")).toHaveLength(1);

    const cell = screen.getByText("U11");
    cell.click();
    expect(onSelect).toHaveBeenCalledWith("a");
    // P4-95 — la cellule porte son `data-slot-id` pour que « ouvrir le créneau fautif » puisse
    // le retrouver et le scroller à l'écran.
    expect(container.querySelector('[data-slot-id="a"]')).not.toBeNull();
  });

  it("fusionne un créneau mutualisé sous son libellé, chaque équipe restant cliquable (P2-17 D4)", async () => {
    const mergeLookups: Lookups = {
      teams: new Map<string, Team>([
        ["t1", { id: "t1", name: "U11", sportCategoryId: "c", priorityTierId: 1, tierOrder: 0 }],
        ["t2", { id: "t2", name: "U13", sportCategoryId: "c", priorityTierId: 2, tierOrder: 0 }],
      ]),
      venues: new Map<string, Venue>([["v1", { id: "v1", name: "Gymnase Alpha", color: "#00aa00" }]]),
      coaches: new Map<string, Coach>(),
      teamCoach: new Map<string, string>(),
      teamPlayerCoaches: new Map<string, string[]>(),
      groupLabels: new Map<string, string>([["v1|1|1080", "CEC3"]]),
    };
    const slots: Slot[] = [
      { ...slot, id: "s1", teamId: "t1" },
      { ...slot, id: "s2", teamId: "t2" },
    ];
    const onSelect = vi.fn();
    const model = buildGrid(slots, "gymnase", mergeLookups);
    const { container } = render(<WeekGrid model={model} selectedSlotId={null} onSelectSlot={onSelect} />);

    // Le libellé titre la carte ; les deux équipes y sont listées.
    expect(screen.getByText("CEC3")).toBeInTheDocument();
    // Chaque équipe est un bouton propre → clic = sélection de SA séance.
    screen.getByRole("button", { name: /U13/ }).click();
    expect(onSelect).toHaveBeenCalledWith("s2");
    // P4-95 — chaque membre d'une carte fusionnée porte AUSSI son `data-slot-id`.
    expect(container.querySelector('[data-slot-id="s1"]')).not.toBeNull();
    expect(container.querySelector('[data-slot-id="s2"]')).not.toBeNull();
    expect(await axe(container)).toHaveNoViolations();
  });

  it("names the venue as TEXT in every view, not colour only (A11Y-01, WCAG 1.4.1)", async () => {
    // In the team ('equipe') view the venue is no longer a column header — it must
    // still be readable as text on the cell (not conveyed by the border/tint colour
    // alone), so a colourblind or touch user can tell venues apart.
    const model = buildGrid([slot], "equipe", lookups);
    const { container } = render(<WeekGrid model={model} selectedSlotId={null} onSelectSlot={vi.fn()} />);

    expect(screen.getByText("Gymnase Alpha")).toBeInTheDocument();
    expect(await axe(container)).toHaveNoViolations();
  });
});
