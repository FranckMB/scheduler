import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it } from "vitest";

import { AccordionSection } from "./accordion";

describe("AccordionSection", () => {
  it("is collapsed by default (body hidden)", () => {
    render(
      <AccordionSection title="Visuel">
        <p>body content</p>
      </AccordionSection>,
    );
    expect(screen.getByRole("button", { name: /Visuel/ })).toHaveAttribute("aria-expanded", "false");
    expect(screen.queryByText("body content")).toBeNull();
  });

  it("renders the body when defaultOpen", () => {
    render(
      <AccordionSection title="Demandes" defaultOpen>
        <p>body content</p>
      </AccordionSection>,
    );
    expect(screen.getByRole("button", { name: /Demandes/ })).toHaveAttribute("aria-expanded", "true");
    expect(screen.getByText("body content")).toBeInTheDocument();
  });

  it("toggles on header click", async () => {
    const user = userEvent.setup();
    render(
      <AccordionSection title="Visuel">
        <p>body content</p>
      </AccordionSection>,
    );
    const header = screen.getByRole("button", { name: /Visuel/ });
    await user.click(header);
    expect(screen.getByText("body content")).toBeInTheDocument();
    await user.click(header);
    expect(screen.queryByText("body content")).toBeNull();
  });
});
