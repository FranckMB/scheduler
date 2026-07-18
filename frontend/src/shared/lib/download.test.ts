import { describe, expect, it } from "vitest";

import { slugFilename } from "./download";

describe("slugFilename", () => {
  it("slugs a French plan name (accents stripped, spaces → dashes, lowercase)", () => {
    expect(slugFilename("Vacances de la Toussaint")).toBe("vacances-de-la-toussaint");
    expect(slugFilename("Planning de la saison 2026-2027")).toBe("planning-de-la-saison-2026-2027");
    expect(slugFilename("Ajustement Gymnase Été")).toBe("ajustement-gymnase-ete");
  });

  it("never yields an empty filename", () => {
    expect(slugFilename("")).toBe("planning");
    expect(slugFilename("   ")).toBe("planning");
    expect(slugFilename("💥💥")).toBe("planning");
  });
});
