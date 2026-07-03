import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import { Menu, MenuItem } from "./menu";

function setup(onSelect = vi.fn()) {
  render(
    <Menu label="Menu du compte" trigger={<span>burger</span>}>
      <MenuItem onSelect={onSelect}>Se déconnecter</MenuItem>
    </Menu>,
  );
  return onSelect;
}

describe("Menu", () => {
  it("is closed initially and opens on trigger click", async () => {
    const user = userEvent.setup();
    setup();
    const trigger = screen.getByLabelText("Menu du compte");
    expect(trigger).toHaveAttribute("aria-expanded", "false");
    expect(screen.queryByRole("menu")).toBeNull();
    await user.click(trigger);
    expect(trigger).toHaveAttribute("aria-expanded", "true");
    expect(screen.getByRole("menu")).toBeInTheDocument();
  });

  it("closes on Escape", async () => {
    const user = userEvent.setup();
    setup();
    await user.click(screen.getByLabelText("Menu du compte"));
    await user.keyboard("{Escape}");
    expect(screen.queryByRole("menu")).toBeNull();
  });

  it("closes on an outside click", async () => {
    const user = userEvent.setup();
    render(
      <div>
        <button type="button">outside</button>
        <Menu label="Menu du compte" trigger={<span>burger</span>}>
          <MenuItem>Item</MenuItem>
        </Menu>
      </div>,
    );
    await user.click(screen.getByLabelText("Menu du compte"));
    expect(screen.getByRole("menu")).toBeInTheDocument();
    await user.click(screen.getByText("outside"));
    expect(screen.queryByRole("menu")).toBeNull();
  });

  it("invokes the item's onSelect when clicked", async () => {
    const user = userEvent.setup();
    const onSelect = setup();
    await user.click(screen.getByLabelText("Menu du compte"));
    await user.click(screen.getByRole("menuitem", { name: "Se déconnecter" }));
    expect(onSelect).toHaveBeenCalledOnce();
  });
});
