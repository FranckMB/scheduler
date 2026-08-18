import { describe, expect, it } from "vitest";

import { compareNamesFr, sortByName } from "./nameOrder";

/**
 * Le tri serveur (`LOWER(name)`) compare des OCTETS : « École » et « Étoile » sortent après
 * « Zola ». Vérifié sur la base du projet. Ce test épingle la correction côté affichage —
 * retirer `localeCompare("fr")` (ou revenir à un `<` brut) le fait rougir.
 */
describe("nameOrder", () => {
  it("range les accents à leur place, pas après le Z", () => {
    const venues = [{ name: "Zola" }, { name: "Étoile" }, { name: "Armand" }, { name: "École" }];

    expect(sortByName(venues).map((v) => v.name)).toEqual(["Armand", "École", "Étoile", "Zola"]);
  });

  it("ignore la casse — « alpha » ne tombe pas après « Zola »", () => {
    const venues = [{ name: "Zola" }, { name: "alpha" }, { name: "Béta" }];

    expect(sortByName(venues).map((v) => v.name)).toEqual(["alpha", "Béta", "Zola"]);
  });

  it("ne mute pas le tableau reçu (la donnée de cache reste intacte)", () => {
    const venues = [{ name: "Zola" }, { name: "Armand" }];

    sortByName(venues);

    expect(venues.map((v) => v.name)).toEqual(["Zola", "Armand"]);
  });

  it("expose le comparateur nu, pour les listes qui ne sont pas des objets nommés", () => {
    expect(["Zola", "École", "Armand"].sort(compareNamesFr)).toEqual(["Armand", "École", "Zola"]);
  });
});
