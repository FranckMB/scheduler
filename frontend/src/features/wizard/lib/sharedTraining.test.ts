import { describe, expect, it } from "vitest";

import type { SharedTrainingGroup, Team, TeamPeriodOverride } from "../api";
import {
  alreadyGroupedTeamIds,
  effectiveSessionsPerWeek,
  gendersCompatible,
  groupContainingTeam,
  maxCommonSessions,
  rankCandidates,
  sharedGroupLabel,
} from "./sharedTraining";

const team = (over: Partial<Team> & Pick<Team, "id" | "name">): Team => ({
  sportCategoryId: "cat",
  priorityTierId: 1,
  tierOrder: 0,
  gender: null,
  level: null,
  sessionsPerWeek: 2,
  isActive: true,
  ...over,
});

const group = (id: string, teamIds: string[], commonSessions = 1, schedulePlanId: string | null = null): SharedTrainingGroup => ({
  id,
  version: 1,
  createdAt: "2026-08-17T00:00:00+00:00",
  updatedAt: "2026-08-17T00:00:00+00:00",
  schedulePlanId,
  teamIds,
  commonSessions,
});

describe("effectiveSessionsPerWeek — the period override is priority (mirror of the processor)", () => {
  const t = team({ id: "t1", name: "SM1", sessionsPerWeek: 3 });

  it("uses the seasonal value when no override applies", () => {
    expect(effectiveSessionsPerWeek(t, undefined)).toBe(3);
  });

  it("prefers the override's sessionsPerWeek over the seasonal one", () => {
    const override: TeamPeriodOverride = { id: "o", schedulePlanId: "p", teamId: "t1", isActive: true, sessionsPerWeek: 1 };
    expect(effectiveSessionsPerWeek(t, override)).toBe(1);
  });

  it("falls back to the seasonal value when the override carries no session count", () => {
    const override: TeamPeriodOverride = { id: "o", schedulePlanId: "p", teamId: "t1", isActive: true, sessionsPerWeek: null };
    expect(effectiveSessionsPerWeek(t, override)).toBe(3);
  });
});

describe("maxCommonSessions — the smallest effective sessionsPerWeek of the selection", () => {
  it("is the minimum across the selected teams (seasonal)", () => {
    const teams = [team({ id: "t1", name: "A", sessionsPerWeek: 3 }), team({ id: "t2", name: "B", sessionsPerWeek: 2 })];
    expect(maxCommonSessions(teams, new Map())).toBe(2);
  });

  it("honours the period override when computing the floor", () => {
    const teams = [team({ id: "t1", name: "A", sessionsPerWeek: 3 }), team({ id: "t2", name: "B", sessionsPerWeek: 3 })];
    const overrides = new Map<string, TeamPeriodOverride>([["t2", { id: "o", schedulePlanId: "p", teamId: "t2", isActive: true, sessionsPerWeek: 1 }]]);
    expect(maxCommonSessions(teams, overrides)).toBe(1);
  });

  it("is 0 for an empty selection", () => {
    expect(maxCommonSessions([], new Map())).toBe(0);
  });
});

describe("alreadyGroupedTeamIds / groupContainingTeam — a team belongs to at most one group of a scope", () => {
  const groups = [group("g1", ["t1", "t2"]), group("g2", ["t3", "t4"])];

  it("collects every grouped team of the scope", () => {
    expect(alreadyGroupedTeamIds(groups, null)).toEqual(new Set(["t1", "t2", "t3", "t4"]));
  });

  it("excludes the group currently being edited (its members stay free)", () => {
    expect(alreadyGroupedTeamIds(groups, "g1")).toEqual(new Set(["t3", "t4"]));
  });

  it("finds the OTHER group a team belongs to, for a reason label", () => {
    expect(groupContainingTeam(groups, "t3", null)?.id).toBe("g2");
    expect(groupContainingTeam(groups, "t3", "g2")).toBeUndefined();
  });
});

describe("sharedGroupLabel — « SM1 + SM2 — 1 séance commune » (plural handled)", () => {
  const name = (id: string): string => ({ t1: "SM1", t2: "SM2", t3: "SM3" })[id] ?? "?";

  it("joins the team names and singularises one common session", () => {
    expect(sharedGroupLabel(["t1", "t2"], 1, name)).toBe("SM1 + SM2 — 1 séance commune");
  });

  it("pluralises several common sessions and names three teams", () => {
    expect(sharedGroupLabel(["t1", "t2", "t3"], 2, name)).toBe("SM1 + SM2 + SM3 — 2 séances communes");
  });
});

describe("gendersCompatible — MIXTE fits any gender; null is never a reason to hide", () => {
  it("matches equal genders", () => {
    expect(gendersCompatible("M", "M")).toBe(true);
  });
  it("separates M from F", () => {
    expect(gendersCompatible("M", "F")).toBe(false);
  });
  it("lets MIXTE pair with any gender", () => {
    expect(gendersCompatible("MIXTE", "F")).toBe(true);
    expect(gendersCompatible("M", "MIXTE")).toBe(true);
  });
  it("treats an unspecified gender (null) as compatible — a ranking must never hide on missing data", () => {
    expect(gendersCompatible(null, "F")).toBe(true);
    expect(gendersCompatible("M", null)).toBe(true);
  });
});

describe("rankCandidates — a DISPLAY split into « proches » and the rest, never a permission", () => {
  const anchor = team({ id: "a", name: "SM1", sportCategoryId: "senior", gender: "M" });
  const sameCat = team({ id: "b", name: "SM2", sportCategoryId: "senior", gender: "M" });
  const mixteSameCat = team({ id: "c", name: "SM3", sportCategoryId: "senior", gender: "MIXTE" });
  const wrongGender = team({ id: "d", name: "SF1", sportCategoryId: "senior", gender: "F" });
  const otherCat = team({ id: "e", name: "U11", sportCategoryId: "u11", gender: "M" });

  it("with no anchor, nothing is « proche » — the whole list stays flat (far)", () => {
    const { near, far } = rankCandidates([anchor, sameCat, otherCat], null, new Set());
    expect(near).toEqual([]);
    expect(far.map((t) => t.id)).toEqual(["a", "b", "e"]);
  });

  it("puts same-category, gender-compatible teams in « proches », the rest in far", () => {
    const { near, far } = rankCandidates([sameCat, mixteSameCat, wrongGender, otherCat], anchor, new Set(["a"]));
    expect(near.map((t) => t.id)).toEqual(["b", "c"]);
    expect(far.map((t) => t.id)).toEqual(["d", "e"]);
  });

  it("keeps a checked team in « proches » so it can always be unchecked, even if far from the anchor", () => {
    // otherCat (e) is far from the anchor by category, but it is CHECKED — it must remain reachable.
    const { near, far } = rankCandidates([sameCat, otherCat], anchor, new Set(["a", "e"]));
    expect(near.map((t) => t.id)).toContain("e");
    expect(far.map((t) => t.id)).not.toContain("e");
  });
});
