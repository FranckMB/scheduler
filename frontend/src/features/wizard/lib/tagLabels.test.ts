import { describe, expect, it } from "vitest";

import type { TeamTag } from "../api";
import { groupTagsByAxis, tagLabel } from "./tagLabels";

const tag = (over: Partial<TeamTag>): TeamTag => ({ id: over.name ?? "t", name: "X", color: null, isSystem: true, axis: null, ...over });

describe("tagLabel", () => {
  it("maps system tags to readable French labels, falls back to the raw name", () => {
    expect(tagLabel("MASCULINE")).toBe("Homme");
    expect(tagLabel("SENIOR")).toBe("Adulte");
    expect(tagLabel("DEPARTEMENTAL")).toBe("Départemental");
    expect(tagLabel("U15")).toBe("U15");
    expect(tagLabel("CUSTOM_XYZ")).toBe("CUSTOM_XYZ");
  });
});

describe("groupTagsByAxis", () => {
  it("orders Genre, Niveau, Âge; drops empty axes; sorts by label; falls back to Autres", () => {
    const tags = [
      tag({ name: "U15", axis: "AGE" }),
      tag({ name: "SENIOR", axis: "AGE" }),
      tag({ name: "MASCULINE", axis: "GENRE" }),
      tag({ name: "DEPARTEMENTAL", axis: "NIVEAU" }),
      tag({ name: "CUSTOM", axis: null }),
    ];
    const groups = groupTagsByAxis(tags);
    expect(groups.map((g) => g.label)).toEqual(["Genre", "Niveau", "Âge", "Autres"]);
    // Âge sorted by display label: "Adulte" (SENIOR) before "U15".
    expect(groups[2].tags.map((t) => t.name)).toEqual(["SENIOR", "U15"]);
  });

  it("omits an axis with no visible tag", () => {
    expect(groupTagsByAxis([tag({ name: "MIXTE", axis: "GENRE" })]).map((g) => g.label)).toEqual(["Genre"]);
  });

  it("routes a tag whose axis is undefined (API omits null fields) to Autres, never dropping it", () => {
    const noAxis = { id: "u", name: "U", color: null, isSystem: false } as unknown as TeamTag; // axis absent
    const groups = groupTagsByAxis([noAxis]);
    expect(groups.map((g) => g.label)).toEqual(["Autres"]);
    expect(groups[0].tags).toEqual([noAxis]);
  });
});
