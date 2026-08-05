import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import { VenueSelect } from "./venue-select";

const venues = [
  { id: "v1", name: "ADN", color: "#ff0000" },
  { id: "v2", name: "JDR", color: "#00ff00" },
];

describe("VenueSelect (pastille avant le nom — demande fondateur 2026-08-05)", () => {
  it("affiche la pastille du gymnase SÉLECTIONNÉ et reste un select natif", async () => {
    const onChange = vi.fn();
    const { container } = render(<VenueSelect aria-label="Gymnase" venues={venues} value="v1" onChange={onChange} />);

    const swatch = container.querySelector("span[aria-hidden]");
    expect(swatch).toHaveStyle({ backgroundColor: "#ff0000" });

    // Le champ reste un <select> natif : clavier/mobile/tests inchangés.
    const select = screen.getByLabelText("Gymnase");
    expect(select.tagName).toBe("SELECT");
    await userEvent.selectOptions(select, "v2");
    expect(onChange).toHaveBeenCalled();
  });

  it("placeholder + options de tête (children) précèdent les gymnases", () => {
    render(
      <VenueSelect aria-label="Gymnase" venues={venues} value="" onChange={() => {}} placeholder="— gymnase —">
        <option value="x" disabled>
          Tête
        </option>
      </VenueSelect>,
    );
    const options = screen.getAllByRole("option");
    expect(options.map((o) => o.textContent)).toEqual(["— gymnase —", "Tête", "ADN", "JDR"]);
  });
});
