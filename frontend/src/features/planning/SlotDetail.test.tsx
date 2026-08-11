import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import type { Constraint, LockOrigin, Slot } from "./api";
import type { GridCell } from "./lib/grid";
import { SlotDetail } from "./SlotDetail";

const slot = (over: Partial<Slot> = {}): Slot => ({
  id: "s1",
  scheduleId: "sch1",
  teamId: "team-A",
  venueId: "venue-1",
  coachId: "coach-X",
  dayOfWeek: 2,
  startTime: "18:00:00",
  durationMinutes: 90,
  lockLevel: "HARD",
  lockOrigin: "RESERVATION",
  temporaryLock: false,
  ...over,
});

const cell = (locked: boolean): GridCell => ({
  key: "k",
  slotId: "s1",
  gridColumn: 1,
  gridRowStart: 1,
  gridRowSpan: 1,
  lane: 0,
  laneCount: 1,
  primaryLabel: "",
  secondaryLabel: "",
  roleTag: null,
  teamLabel: "U11",
  venueLabel: "Gymnase Alpha",
  venueColor: null,
  coachLabel: "Jean Dupont",
  day: 2,
  startLabel: "18:00",
  endLabel: "19:30",
  locked,
});

const constraint = (over: Partial<Constraint>): Constraint => ({
  id: "c1",
  name: "Contrainte",
  scope: "CLUB",
  scopeTargetId: null,
  family: "TIME",
  ruleType: "HARD",
  isActive: true,
  ...over,
});

function renderDetail(over: { slot?: Partial<Slot>; constraints?: Constraint[] } = {}) {
  const s = slot(over.slot);
  render(
    <SlotDetail
      cell={cell(s.lockLevel !== "NONE")}
      slot={s}
      venues={[]}
      categoryLabel="U11"
      constraints={over.constraints ?? []}
      busy={false}
      onClose={vi.fn()}
      onToggleLock={vi.fn()}
      onMove={vi.fn()}
    />,
  );
}

describe("SlotDetail — origine du verrou (F1)", () => {
  it.each<[LockOrigin, string]>([
    ["RESERVATION", "Réservation gymnase"],
    ["MANUAL", "Épinglé manuellement"],
    ["UNKNOWN", "origine inconnue"],
  ])("affiche l'origine %s en clair, sans code d'enum", (origin, label) => {
    renderDetail({ slot: { lockOrigin: origin } });
    expect(screen.getByText(new RegExp(label, "i"))).toBeInTheDocument();
    // Jamais le code brut de l'enum à l'écran.
    expect(screen.queryByText(origin)).not.toBeInTheDocument();
  });

  it("UNKNOWN se lit comme une IGNORANCE, pas comme une absence de verrou", () => {
    renderDetail({ slot: { lockOrigin: "UNKNOWN" } });
    // Le mot « verrouillé » DOIT apparaître (label ET explication) : le créneau est bien
    // bloqué, on ne sait juste pas d'où vient le verrou.
    expect(screen.getAllByText(/verrouill/i).length).toBeGreaterThan(0);
  });

  it("n'affiche aucune origine quand le créneau n'est pas verrouillé", () => {
    renderDetail({ slot: { lockLevel: "NONE", lockOrigin: null } });
    expect(screen.queryByText(/Réservation gymnase|Épinglé manuellement|origine inconnue/i)).not.toBeInTheDocument();
  });
});

describe("SlotDetail — contraintes applicables (F1)", () => {
  it("liste les contraintes qui s'appliquent au créneau, pas les autres", () => {
    renderDetail({
      constraints: [
        constraint({ id: "mine", name: "Pas le lundi", scope: "TEAM", scopeTargetId: "team-A" }),
        constraint({ id: "other", name: "Autre équipe", scope: "TEAM", scopeTargetId: "team-B" }),
      ],
    });
    expect(screen.getByText("Pas le lundi")).toBeInTheDocument();
    expect(screen.queryByText("Autre équipe")).not.toBeInTheDocument();
  });

  it("le dit franchement quand aucune contrainte ne s'applique", () => {
    renderDetail({ constraints: [] });
    expect(screen.getByText(/Aucune contrainte spécifique/i)).toBeInTheDocument();
  });
});
