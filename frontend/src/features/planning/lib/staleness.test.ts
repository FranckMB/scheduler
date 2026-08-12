import { describe, expect, it } from "vitest";

import { stalenessMessage } from "./staleness";

describe("stalenessMessage", () => {
  it("returns null when nothing is stale (no banner)", () => {
    expect(stalenessMessage({ manuallyEdited: false, constraintsChanged: false, readOnly: false })).toBeNull();
  });

  it("names the manual edit as the cause", () => {
    const msg = stalenessMessage({ manuallyEdited: true, constraintsChanged: false, readOnly: false });
    expect(msg).toContain("modifié à la main");
    expect(msg).toContain("Régénérez");
  });

  it("names the constraint change as the cause — périmé, not faux", () => {
    const msg = stalenessMessage({ manuallyEdited: false, constraintsChanged: true, readOnly: false });
    expect(msg).toContain("Une contrainte a changé");
    expect(msg).toContain("périmé");
    // Le mot est choisi : on régénère pour SAVOIR, pas parce que c'est invalide.
    expect(msg).toContain("pas forcément faux");
  });

  it("names BOTH causes in a single message when both triggered", () => {
    const msg = stalenessMessage({ manuallyEdited: true, constraintsChanged: true, readOnly: false });
    expect(msg).toContain("modifié à la main et une contrainte a changé");
  });

  it("offers reopen-then-regenerate on a read-only (validated / in-force) plan, never a bare regenerate", () => {
    const msg = stalenessMessage({ manuallyEdited: false, constraintsChanged: true, readOnly: true });
    // Un planning validé est en lecture seule : « Régénérer » seul l'enverrait dans un 409.
    expect(msg).toContain("Rouvrez ce planning");
    expect(msg).not.toMatch(/^Régénérez/);
  });
});
