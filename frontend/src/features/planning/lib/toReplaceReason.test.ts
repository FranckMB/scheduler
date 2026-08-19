import { describe, expect, it } from "vitest";

import { toReplaceReasonLabel } from "./toReplaceReason";

describe("toReplaceReasonLabel", () => {
  it("traduit chaque raison SERVIE par le backend en clair", () => {
    expect(toReplaceReasonLabel("venue_closed")).toBe("Fermeture du gymnase");
    expect(toReplaceReasonLabel("venue_disabled")).toBe("Gymnase désactivé");
    expect(toReplaceReasonLabel("team_reduced")).toBe("Séances réduites");
  });

  it("une raison inconnue retombe sur un libellé neutre — jamais un crash ni du code brut à l'écran", () => {
    expect(toReplaceReasonLabel("something_new")).toBe("Non reprise");
    expect(toReplaceReasonLabel("")).toBe("Non reprise");
  });
});
