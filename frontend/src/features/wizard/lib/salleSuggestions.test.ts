import { describe, expect, it } from "vitest";

import type { FfbbSalle } from "../api";
import { filterSalles } from "./salleSuggestions";

const salle = (name: string, address: string | null = null): FfbbSalle =>
  ({ name, address, city: null, externalRef: null, latitude: null, longitude: null }) as FfbbSalle;

/** P2-20 — ce que la combobox OFFRE pour ce qui est tapé. */
describe("filterSalles", () => {
  const salles = [salle("GYMNASE MATEO", "5 BIS RUE EMILE DUNIERE"), salle("SALLE RAPHAEL DE BARROS", "251 cours Émile Zola"), salle("ASTROBALLE")];

  it("matche sans casse ni accents, sur le nom comme sur l'adresse", () => {
    expect(filterSalles(salles, "mateo").map((s) => s.name)).toEqual(["GYMNASE MATEO"]);
    // « émile » (accentué) doit trouver l'adresse EMILE (majuscule sans accent) ET Émile.
    expect(filterSalles(salles, "émile").map((s) => s.name)).toEqual(["GYMNASE MATEO", "SALLE RAPHAEL DE BARROS"]);
  });

  it("champ vide = tout offrir (l'invitation à choisir), plafonné à 8", () => {
    expect(filterSalles(salles, "")).toHaveLength(3);
    const many = Array.from({ length: 20 }, (_, i) => salle(`SALLE ${i}`));
    expect(filterSalles(many, "")).toHaveLength(8);
    expect(filterSalles(many, "SALLE")).toHaveLength(8);
  });

  it("rien ne matche → liste vide (jamais un repli sur tout)", () => {
    expect(filterSalles(salles, "zzz")).toEqual([]);
  });
});
