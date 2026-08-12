import { describe, expect, it } from "vitest";

import type { Constraint } from "../api";
import { describeConstraint } from "./describeConstraint";

const constraint = (over: Partial<Constraint>): Constraint => ({
  id: "c1",
  name: "un nom libre",
  scope: "CLUB",
  scopeTargetId: null,
  family: "TIME",
  ruleType: "PREFERRED",
  config: {},
  isActive: true,
  ...over,
});

const venueName = (id: string): string | undefined => ({ "v-mateo": "Matéo", "v-alpha": "Gymnase Alpha" })[id];

describe("describeConstraint — ce que la règle FAIT, pas son nom", () => {
  it("DAY forbiddenDays:[6] se lit « samedi », quel que soit le nom libre", () => {
    // Le cas fondateur : une règle « samedi interdit » nommée n'importe comment.
    const desc = describeConstraint(constraint({ name: "SM2 au moins 1 seance a Mateo", family: "DAY", config: { forbiddenDays: [6] } }), venueName);
    expect(desc).not.toBeNull();
    expect(desc!.toLowerCase()).toContain("samedi");
    expect(desc!.toLowerCase()).toContain("interdit");
  });

  it("DAY forbiddenDays multiples s'accordent au pluriel", () => {
    const desc = describeConstraint(constraint({ family: "DAY", config: { forbiddenDays: [6, 7] } }), venueName);
    expect(desc!.toLowerCase()).toContain("samedi");
    expect(desc!.toLowerCase()).toContain("dimanche");
    expect(desc!.toLowerCase()).toContain("interdits");
  });

  it("DAY allowedDays se lit « uniquement »", () => {
    const desc = describeConstraint(constraint({ family: "DAY", config: { allowedDays: [3] } }), venueName);
    expect(desc!.toLowerCase()).toContain("uniquement");
    expect(desc!.toLowerCase()).toContain("mercredi");
  });

  it("FACILITY minAtVenueId nomme le gymnase et le compte", () => {
    const desc = describeConstraint(constraint({ family: "FACILITY", config: { minAtVenueId: "v-mateo", minAtVenueCount: 1 } }), venueName);
    expect(desc).toBe("Au moins 1 séance à Matéo");
  });

  it("FACILITY minAtVenueId au pluriel", () => {
    const desc = describeConstraint(constraint({ family: "FACILITY", config: { minAtVenueId: "v-mateo", minAtVenueCount: 2 } }), venueName);
    expect(desc).toBe("Au moins 2 séances à Matéo");
  });

  it("FACILITY preferredVenueId se lit « préfère »", () => {
    const desc = describeConstraint(constraint({ family: "FACILITY", config: { preferredVenueId: "v-mateo" } }), venueName);
    expect(desc).toBe("Préfère Matéo");
  });

  it("FACILITY forbiddenVenueId / forcedVenueId se lisent « évite » / « impose »", () => {
    expect(describeConstraint(constraint({ family: "FACILITY", config: { forbiddenVenueId: "v-mateo" } }), venueName)).toBe("Évite Matéo");
    expect(describeConstraint(constraint({ family: "FACILITY", config: { forcedVenueId: "v-mateo" } }), venueName)).toBe("Impose Matéo");
  });

  it("TIME compose les bornes en clair", () => {
    const desc = describeConstraint(constraint({ family: "TIME", config: { maxStartTime: "21:00", minStartTime: "18:00" } }), venueName);
    expect(desc!.toLowerCase()).toContain("pas après 21:00");
    expect(desc!.toLowerCase()).toContain("pas avant 18:00");
  });

  it("COACH_AVAILABILITY se lit « indisponible » avec sa fenêtre horaire", () => {
    const desc = describeConstraint(constraint({ family: "COACH_AVAILABILITY", scope: "COACH", scopeTargetId: "co1", config: { unavailableDays: [5], fromTime: "20:00" } }), venueName);
    expect(desc!.toLowerCase()).toContain("indisponible");
    expect(desc!.toLowerCase()).toContain("vendredi");
    expect(desc!.toLowerCase()).toContain("20:00");
  });

  it("retombe sur `null` (→ le nom) pour un gymnase INTROUVABLE : jamais « préfère undefined »", () => {
    const desc = describeConstraint(constraint({ family: "FACILITY", config: { preferredVenueId: "inconnu" } }), venueName);
    expect(desc).toBeNull();
  });

  it("retombe sur `null` pour une combinaison NON couverte — pas d'invention", () => {
    // Config vide, ou clé qu'on ne sait pas décrire fidèlement.
    expect(describeConstraint(constraint({ family: "DAY", config: {} }), venueName)).toBeNull();
    expect(describeConstraint(constraint({ family: "FACILITY", config: {} }), venueName)).toBeNull();
    // Famille inconnue (donnée future) → null, on ne devine pas.
    expect(describeConstraint(constraint({ family: "MYSTERY", config: { foo: "bar" } }), venueName)).toBeNull();
  });
});
