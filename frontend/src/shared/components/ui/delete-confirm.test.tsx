import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import { DeleteConfirm } from "./delete-confirm";

describe("DeleteConfirm", () => {
  it("lists only the non-zero impact lines, pluralised", () => {
    render(
      <DeleteConfirm
        open
        entityName="SM1"
        impacts={[
          { count: 2, one: "créneau réservé", many: "créneaux réservés" },
          { count: 1, one: "coach lié", many: "coachs liés" },
          { count: 0, one: "coach-joueur lié", many: "coach-joueurs liés" },
        ]}
        onConfirm={vi.fn()}
        onCancel={vi.fn()}
      />,
    );
    expect(screen.getByRole("dialog", { name: /Supprimer .*SM1/ })).toBeInTheDocument();
    expect(screen.getByText("2 créneaux réservés")).toBeInTheDocument();
    expect(screen.getByText("1 coach lié")).toBeInTheDocument();
    // Zero-count line is hidden — the dialog only ever states real collateral.
    expect(screen.queryByText(/coach-joueur/)).not.toBeInTheDocument();
  });

  it("states a plain definitive message when nothing is linked", () => {
    render(<DeleteConfirm open entityName="Gymnase A" impacts={[{ count: 0, one: "créneau", many: "créneaux" }]} onConfirm={vi.fn()} onCancel={vi.fn()} />);
    expect(screen.getByText(/définitive/)).toBeInTheDocument();
    expect(screen.queryByRole("list")).not.toBeInTheDocument();
  });

  it("wires confirm and cancel", async () => {
    const onConfirm = vi.fn();
    const onCancel = vi.fn();
    render(<DeleteConfirm open entityName="X" impacts={[]} onConfirm={onConfirm} onCancel={onCancel} />);
    await userEvent.click(screen.getByRole("button", { name: "Supprimer" }));
    expect(onConfirm).toHaveBeenCalledOnce();
    await userEvent.click(screen.getByRole("button", { name: "Annuler" }));
    expect(onCancel).toHaveBeenCalledOnce();
  });
});
