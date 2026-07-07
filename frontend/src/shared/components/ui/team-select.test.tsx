import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import type { TeamLike, TierLike } from "@/shared/lib/teamTiers";

import { TeamSelect } from "./team-select";

const tiers: TierLike[] = [
  { id: 1, label: "S", name: "Fanion" },
  { id: 3, label: "B", name: "Moyenne" },
];
const teams: TeamLike[] = [
  { id: "b1", name: "U15", priorityTierId: 3, tierOrder: 0 },
  { id: "s1", name: "SM1", priorityTierId: 1, tierOrder: 0 },
];

describe("TeamSelect", () => {
  it("renders teams under their tier optgroup (S/A/B/C/D découpage), S before B", () => {
    render(<TeamSelect aria-label="Équipe" teams={teams} tiers={tiers} value="s1" onChange={() => {}} />);
    const groups = screen.getByLabelText("Équipe").querySelectorAll("optgroup");
    expect([...groups].map((g) => g.label)).toEqual(["S · Fanion", "B · Moyenne"]);
    // SM1 (tier S) is grouped under the first optgroup, not a flat list.
    expect(groups[0].querySelector("option")?.textContent).toBe("SM1");
  });

  it("falls back to a flat list when tiers are not loaded", () => {
    render(<TeamSelect aria-label="Équipe" teams={teams} tiers={[]} value="s1" onChange={() => {}} />);
    expect(screen.getByLabelText("Équipe").querySelectorAll("optgroup")).toHaveLength(0);
    expect(screen.getByLabelText("Équipe").querySelectorAll("option")).toHaveLength(2);
  });

  it("renders an orphan team (unknown tier) under 'Autres' — never dropped", () => {
    const withOrphan: TeamLike[] = [...teams, { id: "x", name: "Mystère", priorityTierId: 99, tierOrder: 0 }];
    render(<TeamSelect aria-label="Équipe" teams={withOrphan} tiers={tiers} value="s1" onChange={() => {}} />);
    const groups = screen.getByLabelText("Équipe").querySelectorAll("optgroup");
    expect([...groups].map((g) => g.label)).toEqual(["S · Fanion", "B · Moyenne", "Autres"]);
    expect(screen.getByRole("option", { name: "Mystère" })).toBeInTheDocument();
  });

  it("renders a leading placeholder option when provided", () => {
    render(<TeamSelect aria-label="Équipe" teams={teams} tiers={tiers} placeholder="—" value="" onChange={() => {}} />);
    const options = screen.getByLabelText("Équipe").querySelectorAll("option");
    expect(options[0].textContent).toBe("—");
  });
});
