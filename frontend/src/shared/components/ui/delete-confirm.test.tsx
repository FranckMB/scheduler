import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import { DeleteConfirm } from "./delete-confirm";

describe("DeleteConfirm", () => {
  const impact = (over = {}) => ({ blocked: false, reason: null, lines: [], slotsInForce: 0, declaredFixtures: 0, ...over });

  /**
   * P3-16 — les comptes ET les libellés viennent du serveur : une famille ajoutée à la
   * cascade s'affiche d'office, au lieu de disparaître faute de traduction côté écran.
   */
  it("affiche l'impact SERVEUR, la casse du planning en vigueur et l'alerte fédération", () => {
    render(
      <DeleteConfirm
        open
        entityName="Matéo"
        impact={impact({
          lines: [{ key: "venue_slot", count: 12, one: "créneau de disponibilité", many: "créneaux de disponibilité" }],
          slotsInForce: 6,
          declaredFixtures: 2,
        })}
        onConfirm={vi.fn()}
        onCancel={vi.fn()}
      />,
    );

    expect(screen.getByText(/12 créneaux de disponibilité/)).toBeInTheDocument();
    expect(screen.getByText(/planning/i)).toBeInTheDocument();
    expect(screen.getByText(/déjà déclarés/)).toBeInTheDocument();
  });

  it("n'offre PAS de confirmer tant que l'impact n'a pas répondu — ni quand le serveur refusera", async () => {
    const onConfirm = vi.fn();
    const { rerender } = render(<DeleteConfirm open entityName="Matéo" impactLoading onConfirm={onConfirm} onCancel={vi.fn()} />);
    await userEvent.click(screen.getByRole("button", { name: "Supprimer" }));
    expect(onConfirm).not.toHaveBeenCalled();

    // Périmètre engagé : le geste rendrait 409 — on l'annonce au lieu de l'offrir.
    rerender(
      <DeleteConfirm
        open
        entityName="SM1"
        impact={impact({ blocked: true, reason: "Cette équipe joue en compétition." })}
        onConfirm={onConfirm}
        onCancel={vi.fn()}
      />,
    );
    expect(screen.getByText(/joue en compétition/)).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: "Supprimer" }));
    expect(onConfirm).not.toHaveBeenCalled();
  });

  it("ne présente JAMAIS un impact inconnu comme un impact vide", () => {
    render(<DeleteConfirm open entityName="Matéo" impactFailed onConfirm={vi.fn()} onCancel={vi.fn()} />);

    expect(screen.getByText(/Impossible de vérifier/)).toBeInTheDocument();
  });

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
    // The permanence caution shows EVEN with collateral (the dangerous case).
    expect(screen.getByText(/Cette action est définitive/)).toBeInTheDocument();
  });

  it("always warns it is definitive, even when nothing is linked", () => {
    render(<DeleteConfirm open entityName="Gymnase A" impacts={[{ count: 0, one: "créneau", many: "créneaux" }]} onConfirm={vi.fn()} onCancel={vi.fn()} />);
    expect(screen.getByText(/Cette action est définitive/)).toBeInTheDocument();
    expect(screen.queryByRole("list")).not.toBeInTheDocument();
  });

  it("names period-plan reservations in the caution when affectsPeriodPlans is set", () => {
    render(<DeleteConfirm open entityName="SM1" affectsPeriodPlans impacts={[]} onConfirm={vi.fn()} onCancel={vi.fn()} />);
    expect(screen.getByText(/plannings de période/)).toBeInTheDocument();
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
