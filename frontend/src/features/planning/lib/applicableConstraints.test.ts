import { describe, expect, it } from "vitest";

import type { Constraint, Slot } from "../api";
import { applicableConstraints, buildTagTeamIds, isClubWide } from "./applicableConstraints";

const slot = (over: Partial<Slot> = {}): Slot => ({
  id: "s1",
  scheduleId: "sch1",
  teamId: "team-A",
  venueId: "venue-1",
  coachId: "coach-X",
  dayOfWeek: 2,
  startTime: "18:00",
  durationMinutes: 90,
  lockLevel: "NONE",
  lockOrigin: null,
  temporaryLock: false,
  ...over,
});

const constraint = (over: Partial<Constraint>): Constraint => ({
  id: "c1",
  name: "Contrainte",
  scope: "CLUB",
  scopeTargetId: null,
  family: "TIME",
  ruleType: "HARD",
  config: {},
  isActive: true,
  ...over,
});

describe("applicableConstraints", () => {
  it("garde une contrainte CLUB pour tout créneau", () => {
    const club = constraint({ id: "club", scope: "CLUB", scopeTargetId: null });
    expect(applicableConstraints(slot(), [club]).map((c) => c.id)).toEqual(["club"]);
  });

  it("garde une contrainte TEAM seulement pour SON équipe", () => {
    const mine = constraint({ id: "mine", scope: "TEAM", scopeTargetId: "team-A" });
    const other = constraint({ id: "other", scope: "TEAM", scopeTargetId: "team-B" });
    expect(applicableConstraints(slot(), [mine, other]).map((c) => c.id)).toEqual(["mine"]);
  });

  it("garde une contrainte FACILITY seulement pour SON gymnase", () => {
    const here = constraint({ id: "here", scope: "FACILITY", scopeTargetId: "venue-1" });
    const elsewhere = constraint({ id: "elsewhere", scope: "FACILITY", scopeTargetId: "venue-9" });
    expect(applicableConstraints(slot(), [here, elsewhere]).map((c) => c.id)).toEqual(["here"]);
  });

  it("garde une contrainte COACH seulement pour SON coach (jamais si le créneau n'a pas de coach)", () => {
    const coach = constraint({ id: "coach", scope: "COACH", scopeTargetId: "coach-X" });
    expect(applicableConstraints(slot(), [coach]).map((c) => c.id)).toEqual(["coach"]);
    expect(applicableConstraints(slot({ coachId: null }), [coach])).toEqual([]);
  });

  it("écarte les contraintes inactives", () => {
    const off = constraint({ id: "off", scope: "CLUB", isActive: false });
    expect(applicableConstraints(slot(), [off])).toEqual([]);
  });
});

describe("applicableConstraints — CLUB ciblant un tag (miroir de ScheduleConstraintBuilder)", () => {
  // team-A porte le tag REGIONAL dans la saison ; team-Z ne le porte pas. Le backend
  // ÉCLATE une contrainte CLUB+targetTag en N contraintes TEAM (les seules équipes
  // taguées) — le solveur ne voit jamais une règle « tout le club » quand un tag est posé.
  const tagged = buildTagTeamIds([{ id: "tag-reg", name: "REGIONAL" }], [{ teamId: "team-A", tagId: "tag-reg" }]);

  it("affiche une contrainte CLUB+tag sur une équipe QUI PORTE le tag", () => {
    const reg = constraint({ id: "reg", scope: "CLUB", config: { targetTag: "REGIONAL" } });
    expect(applicableConstraints(slot({ teamId: "team-A" }), [reg], tagged).map((c) => c.id)).toEqual(["reg"]);
  });

  it("MASQUE une contrainte CLUB+tag sur une équipe qui NE porte PAS le tag", () => {
    // ⚠ FALSIFICATION : remettre `case \"CLUB\": return true` dans applies() fait rougir
    // CE test — c'est exactement le mensonge d'affichage qu'on répare.
    const reg = constraint({ id: "reg", scope: "CLUB", config: { targetTag: "REGIONAL" } });
    expect(applicableConstraints(slot({ teamId: "team-Z" }), [reg], tagged)).toEqual([]);
  });

  it("affiche PARTOUT une contrainte CLUB sans tag (elle concerne vraiment tout le club)", () => {
    const club = constraint({ id: "club", scope: "CLUB", config: {} });
    expect(applicableConstraints(slot({ teamId: "team-Z" }), [club], tagged).map((c) => c.id)).toEqual(["club"]);
  });

  it("MASQUE partout une contrainte CLUB+tag dont le tag ne résout AUCUNE équipe (NO-OP backend)", () => {
    // Faute de frappe, ou tags non réappliqués après bascule de saison : le backend saute
    // la contrainte entièrement (l.855-862). Le front doit faire pareil — nulle part.
    const ghost = constraint({ id: "ghost", scope: "CLUB", config: { targetTag: "FANTOME" } });
    expect(applicableConstraints(slot({ teamId: "team-A" }), [ghost], tagged)).toEqual([]);
    expect(applicableConstraints(slot({ teamId: "team-Z" }), [ghost], tagged)).toEqual([]);
  });

  it("sans données de tags, une contrainte CLUB+tag ne s'affiche nulle part (fail-closed)", () => {
    // Tant que tags/assignations ne sont pas lus, ne PAS sur-afficher : montrer partout
    // serait re-mentir. Une contrainte CLUB sans tag, elle, reste affichée.
    const reg = constraint({ id: "reg", scope: "CLUB", config: { targetTag: "REGIONAL" } });
    const club = constraint({ id: "club", scope: "CLUB", config: {} });
    expect(applicableConstraints(slot({ teamId: "team-A" }), [reg, club]).map((c) => c.id)).toEqual(["club"]);
  });
});

describe("isClubWide — ce qui concerne le club ENTIER vs l'équipe", () => {
  it("CLUB sans tag = club entier", () => {
    expect(isClubWide(constraint({ scope: "CLUB", config: {} }))).toBe(true);
  });
  it("CLUB avec tag = concerne l'équipe (taguée), pas le club entier", () => {
    expect(isClubWide(constraint({ scope: "CLUB", config: { targetTag: "REGIONAL" } }))).toBe(false);
  });
  it("TEAM/FACILITY/COACH ne sont jamais club entier", () => {
    expect(isClubWide(constraint({ scope: "TEAM", scopeTargetId: "team-A" }))).toBe(false);
    expect(isClubWide(constraint({ scope: "FACILITY", scopeTargetId: "venue-1" }))).toBe(false);
    expect(isClubWide(constraint({ scope: "COACH", scopeTargetId: "coach-X" }))).toBe(false);
  });
});
