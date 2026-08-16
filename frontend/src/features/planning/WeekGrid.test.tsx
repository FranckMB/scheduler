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

  describe("cadenas sur la carte (toggle en un clic)", () => {
    it("expose un cadenas nommant l'équipe qui bascule le verrou SANS ouvrir le panneau", async () => {
      const onSelect = vi.fn();
      const onToggleLock = vi.fn();
      const model = buildGrid([slot], "gymnase", lookups);
      const { container } = render(<WeekGrid model={model} selectedSlotId={null} onSelectSlot={onSelect} onToggleLock={onToggleLock} />);

      const lock = screen.getByRole("button", { name: "Verrouiller U11" });
      lock.click();
      expect(onToggleLock).toHaveBeenCalledWith("a");
      // Un clic sur le cadenas ne sélectionne PAS le créneau (le cadenas est un bouton frère,
      // pas un bouton dans un bouton).
      expect(onSelect).not.toHaveBeenCalled();
      expect(await axe(container)).toHaveNoViolations();
    });

    it("nomme « Déverrouiller <équipe> » quand le créneau est verrouillé", () => {
      const onToggleLock = vi.fn();
      const model = buildGrid([{ ...slot, lockLevel: "HARD" }], "gymnase", lookups);
      render(<WeekGrid model={model} selectedSlotId={null} onSelectSlot={vi.fn()} onToggleLock={onToggleLock} />);

      expect(screen.getByRole("button", { name: "Déverrouiller U11" })).toBeInTheDocument();
    });

    it("cliquer la CARTE sélectionne le créneau, sans déclencher le verrou", () => {
      const onSelect = vi.fn();
      const onToggleLock = vi.fn();
      const model = buildGrid([slot], "gymnase", lookups);
      render(<WeekGrid model={model} selectedSlotId={null} onSelectSlot={onSelect} onToggleLock={onToggleLock} />);

      screen.getByText("U11").click();
      expect(onSelect).toHaveBeenCalledWith("a");
      expect(onToggleLock).not.toHaveBeenCalled();
    });

    it("lecture seule (pas d'onToggleLock) : aucun cadenas cliquable, même verrouillé", () => {
      const model = buildGrid([{ ...slot, lockLevel: "HARD" }], "gymnase", lookups);
      render(<WeekGrid model={model} selectedSlotId={null} onSelectSlot={vi.fn()} />);

      expect(screen.queryByRole("button", { name: /Déverrouiller/ })).not.toBeInTheDocument();
      expect(screen.queryByRole("button", { name: /Verrouiller/ })).not.toBeInTheDocument();
    });

    it("carte fusionnée : un cadenas par membre, nommant SON équipe", () => {
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
      const onToggleLock = vi.fn();
      const model = buildGrid(slots, "gymnase", mergeLookups);
      render(<WeekGrid model={model} selectedSlotId={null} onSelectSlot={vi.fn()} onToggleLock={onToggleLock} />);

      screen.getByRole("button", { name: "Verrouiller U13" }).click();
      expect(onToggleLock).toHaveBeenCalledWith("s2");
    });
  });

  describe("lentille verrous (lockLens, PR 3)", () => {
    // Trois créneaux : un manuel, une réservation, un libre — trois jours distincts pour
    // trois colonnes séparées (vue gymnase, même gymnase).
    const lensSlots: Slot[] = [
      { ...slot, id: "manual", dayOfWeek: 1, lockLevel: "HARD", lockOrigin: "MANUAL" },
      { ...slot, id: "reserv", dayOfWeek: 2, lockLevel: "HARD", lockOrigin: "RESERVATION" },
      { ...slot, id: "free", dayOfWeek: 3, lockLevel: "NONE", lockOrigin: null },
    ];

    it("estompe les créneaux SANS verrou et cercle les verrouillés par catégorie", () => {
      const model = buildGrid(lensSlots, "gymnase", lookups);
      const { container } = render(<WeekGrid model={model} selectedSlotId={null} onSelectSlot={vi.fn()} lockLens />);

      // Non verrouillé → estompé (lentille).
      expect(container.querySelector('[data-slot-id="free"]')?.className).toContain("opacity-40");
      // Verrouillés → anneau de leur catégorie (« ring-2 ring-* », distinct du hover base
      // « hover:ring-accent »), jamais estompés.
      expect(container.querySelector('[data-slot-id="manual"]')?.className).toContain("ring-2 ring-accent");
      expect(container.querySelector('[data-slot-id="manual"]')?.className).not.toContain("opacity-40");
      expect(container.querySelector('[data-slot-id="reserv"]')?.className).toContain("ring-warning");
    });

    it("donne un style ET une icône DISTINCTS à MANUAL / RESERVATION / UNKNOWN (jamais la couleur seule)", () => {
      const slots: Slot[] = [
        { ...slot, id: "manual", dayOfWeek: 1, lockLevel: "HARD", lockOrigin: "MANUAL" },
        { ...slot, id: "reserv", dayOfWeek: 2, lockLevel: "HARD", lockOrigin: "RESERVATION" },
        { ...slot, id: "unknown", dayOfWeek: 3, lockLevel: "HARD", lockOrigin: "UNKNOWN" },
      ];
      const model = buildGrid(slots, "gymnase", lookups);
      const { container } = render(<WeekGrid model={model} selectedSlotId={null} onSelectSlot={vi.fn()} lockLens />);

      expect(container.querySelector('[data-slot-id="manual"]')?.className).toContain("ring-2 ring-accent");
      expect(container.querySelector('[data-slot-id="reserv"]')?.className).toContain("ring-warning");
      expect(container.querySelector('[data-slot-id="unknown"]')?.className).toContain("ring-muted-foreground");
      // Une icône par catégorie (pas la couleur seule) — repérée par son data-lens.
      expect(container.querySelector('[data-lens="MANUAL"]')).not.toBeNull();
      expect(container.querySelector('[data-lens="RESERVATION"]')).not.toBeNull();
      expect(container.querySelector('[data-lens="UNKNOWN"]')).not.toBeNull();
    });

    it("le surlignage CONFLIT prime sur la lentille (le refus de move l'emporte)", () => {
      const model = buildGrid(lensSlots, "gymnase", lookups);
      // « free » est le créneau en conflit surligné ; la lentille ne doit PAS colorer.
      const { container } = render(
        <WeekGrid model={model} selectedSlotId={null} onSelectSlot={vi.fn()} lockLens highlightSlotIds={new Set(["free"])} />,
      );

      // Conflit : le hors-conflit est estompé par le CONFLIT (opacity-30), pas par la lentille.
      expect(container.querySelector('[data-slot-id="manual"]')?.className).toContain("opacity-30");
      // La lentille est suffoquée : ni anneau de catégorie ni icône lentille tant qu'un conflit règne.
      expect(container.querySelector('[data-slot-id="manual"]')?.className).not.toContain("ring-2 ring-accent");
      expect(container.querySelector('[data-lens="MANUAL"]')).toBeNull();
    });

    it("lentille inactive (lockLens absent) : ni estompe ni anneau de catégorie", () => {
      const model = buildGrid(lensSlots, "gymnase", lookups);
      const { container } = render(<WeekGrid model={model} selectedSlotId={null} onSelectSlot={vi.fn()} />);

      expect(container.querySelector('[data-slot-id="free"]')?.className).not.toContain("opacity-40");
      expect(container.querySelector('[data-lens="MANUAL"]')).toBeNull();
    });
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
