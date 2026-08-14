import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { useState } from "react";
import { describe, expect, it, vi } from "vitest";
import { axe } from "vitest-axe";

import { GROUP_LABEL_MAX, GroupLabelField } from "./slotFields";

/** Contrôleur minimal : `GroupLabelField` est contrôlé, on lui prête un état pour observer la saisie. */
function Harness({ capacity }: { capacity: number }) {
  const [value, setValue] = useState("");
  return <GroupLabelField capacity={capacity} value={value} onChange={setValue} />;
}

describe("GroupLabelField (P2-17 D7)", () => {
  it("n'apparaît PAS sous une capacité de 1 (aligné sur le refus 422 du backend)", () => {
    render(<GroupLabelField capacity={1} value="" onChange={vi.fn()} />);
    expect(screen.queryByRole("textbox", { name: "Libellé de groupe" })).toBeNull();
  });

  it("apparaît dès 2 équipes, enregistre la saisie et plafonne à 40 caractères", async () => {
    const { container } = render(<Harness capacity={2} />);
    const input = screen.getByRole("textbox", { name: "Libellé de groupe" });
    expect(input).toHaveAttribute("maxlength", String(GROUP_LABEL_MAX));
    await userEvent.type(input, "CEC3");
    expect(input).toHaveValue("CEC3");
    // Nommé explicitement (aria-label) — pas par le seul placeholder (AGENTS.md §axe).
    expect(await axe(container)).toHaveNoViolations();
  });
});
