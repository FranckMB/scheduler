import { describe, expect, it } from "vitest";

import type { TeamMatchHabit } from "../api";
import { MATCH_MINUTES, WARMUP_MINUTES } from "./weekendGrid";
import { buildTypicalWeekend } from "./typicalWeekend";

const habit = (over: Partial<TeamMatchHabit> = {}): TeamMatchHabit => ({
  id: "h-1",
  teamId: "team-1",
  dayOfWeek: 6,
  kickoffTime: "15:30",
  venueId: "venue-1",
  ...over,
});

describe("buildTypicalWeekend (P1-4 PR E2)", () => {
  // D-02 — ce cas épinglait 15:00→17:45, soit 2h45, sous un nom qui annonce « 2h15 » : il
  // verrouillait la divergence au lieu de la révéler. Le serveur fait foi (`MatchFootprint.php`,
  // 30 min avant le coup d'envoi + 105 après = 2h15) et la grille DATÉE le respectait déjà ;
  // seul le « week-end type » dessinait 2h45. Les bornes ci-dessous sont désormais dérivées des
  // mêmes constantes que le code, pour que la valeur ne puisse plus être recopiée de travers.
  it("lays a venue-anchored habit as a 2h15 footprint in its day×venue column", () => {
    const model = buildTypicalWeekend([habit()]);
    const kickoffMin = 15 * 60 + 30;
    expect(model.empty).toBe(false);
    expect(model.columns).toEqual([{ key: "6:venue-1", dayOfWeek: 6, venueId: "venue-1" }]);
    expect(model.blocks[0]).toMatchObject({ startMin: kickoffMin - WARMUP_MINUTES, endMin: kickoffMin + MATCH_MINUTES, kickoff: "15:30" });
    // L'empreinte totale annoncée par le nom du test : 2h15.
    expect(WARMUP_MINUTES + MATCH_MINUTES).toBe(135);
  });

  it("keeps only weekend habits and lists venue-less ones apart", () => {
    const model = buildTypicalWeekend([
      habit(),
      habit({ id: "h-2", teamId: "team-2", dayOfWeek: 3 }), // Wednesday: not a weekend habit
      habit({ id: "h-3", teamId: "team-3", dayOfWeek: 7, venueId: null }),
    ]);
    expect(model.blocks).toHaveLength(1);
    expect(model.venueless.map((h) => h.id)).toEqual(["h-3"]);
  });

  it("lanes overlapping habits of the same column side by side (a template collision must be SEEN)", () => {
    const model = buildTypicalWeekend([habit(), habit({ id: "h-2", teamId: "team-2", kickoffTime: "16:00" })]);
    const lanes = model.blocks.map((b) => b.lane).sort();
    expect(lanes).toEqual([0, 1]);
    expect(model.blocks.every((b) => 2 === b.laneCount)).toBe(true);
  });

  it("is empty only when NO weekend habit exists at all", () => {
    expect(buildTypicalWeekend([]).empty).toBe(true);
    expect(buildTypicalWeekend([habit({ venueId: null })]).empty).toBe(false); // venue-less still worth showing
  });
});
