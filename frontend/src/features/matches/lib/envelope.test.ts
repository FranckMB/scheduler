import { describe, expect, it } from "vitest";

import type { Category, Fixture, LeagueWindow, Team } from "../api";
import { isInEnvelope, isoWeekday, resolveEnvelope, timeToMinutes } from "./envelope";

const team = (over: Partial<Team> = {}): Team => ({ id: "team-1", name: "U13 M", sportCategoryId: "cat-u13", level: "DEPARTEMENTAL", gender: "M", priorityTierId: 3, tierOrder: 0, ...over });
const category = (over: Partial<Category> = {}): Category => ({ id: "cat-u13", name: "U13", ...over });
const fixture = (over: Partial<Fixture> = {}): Fixture => ({
  id: "fx-1",
  teamId: "team-1",
  seasonId: "s",
  competitionId: null,
  matchDate: "2026-10-03", // Saturday
  homeAway: "HOME",
  opponentLabel: "Adv",
  status: "UNPLACED",
  venueId: null,
  kickoffTime: null,
  externalRef: null,
  ...over,
});
const window = (over: Partial<LeagueWindow> = {}): LeagueWindow => ({
  id: "w-1",
  league: "AURA",
  category: "U13",
  level: "DEPARTEMENTAL",
  gender: null,
  dayOfWeek: 6, // Saturday
  kickoffMin: "13:00",
  kickoffMax: "18:00",
  ...over,
});

describe("envelope helpers", () => {
  it("timeToMinutes tolerates HH:MM and HH:MM:SS", () => {
    expect(timeToMinutes("13:00")).toBe(780);
    expect(timeToMinutes("18:30:00")).toBe(1110);
  });

  it("isoWeekday returns 6 for Saturday, 7 for Sunday", () => {
    expect(isoWeekday("2026-10-03")).toBe(6);
    expect(isoWeekday("2026-10-04")).toBe(7);
  });
});

describe("resolveEnvelope", () => {
  const teams = new Map([[team().id, team()]]);
  const categories = new Map([[category().id, category()]]);

  it("maps a team to its window and validates day + time", () => {
    const env = resolveEnvelope(fixture(), teams, categories, [window()]);
    expect(env.mapped).toBe(true);
    expect(env.dayOk).toBe(true);
    expect(env.timeOk("14:00")).toBe(true);
    expect(env.timeOk("20:00")).toBe(false); // past kickoffMax
  });

  it("does not map when the category label does not align", () => {
    const env = resolveEnvelope(fixture(), teams, categories, [window({ category: "Senior" })]);
    expect(env.mapped).toBe(false);
    expect(env.windows).toHaveLength(0);
  });

  it("does not map a team whose level is unknown (no blanket match)", () => {
    const noLevel = new Map([[team().id, team({ level: null })]]);
    const env = resolveEnvelope(fixture(), noLevel, categories, [window(), window({ id: "w-2", level: "REGIONAL", dayOfWeek: 7 })]);
    expect(env.mapped).toBe(false);
  });

  it("flags the wrong day as out of envelope", () => {
    // A Sunday match against a Saturday-only window.
    const env = resolveEnvelope(fixture({ matchDate: "2026-10-04" }), teams, categories, [window()]);
    expect(env.mapped).toBe(true);
    expect(env.dayOk).toBe(false);
  });
});

describe("isInEnvelope", () => {
  const teams = new Map([[team().id, team()]]);
  const categories = new Map([[category().id, category()]]);

  it("never blocks an unmapped team (advisory degradation)", () => {
    const env = resolveEnvelope(fixture(), teams, categories, [window({ category: "Senior" })]);
    expect(env.mapped).toBe(false);
    expect(isInEnvelope(env, "23:00")).toBe(true);
  });

  it("blocks a mapped team placed outside its window", () => {
    const env = resolveEnvelope(fixture(), teams, categories, [window()]);
    expect(isInEnvelope(env, "14:00")).toBe(true);
    expect(isInEnvelope(env, "20:00")).toBe(false);
  });
});
