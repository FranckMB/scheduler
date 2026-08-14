import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import type { Venue, VenueTrainingSlot } from "../api";
import { WEEK } from "../lib/weekGrid";
import { ReservationGrid } from "./ReservationGrid";

const venue: Venue = { id: "v1", name: "Gymnase A", color: "#00aa00", canSplit: false, isActive: true } as Venue;

const sunday: VenueTrainingSlot = { id: "s7", venueId: "v1", dayOfWeek: 7, startTime: "20:00:00", durationMinutes: 90, capacity: 2 } as VenueTrainingSlot;

/**
 * P4-37 — LE DIMANCHE A UNE COLONNE, DONC UN CRÉNEAU DU DIMANCHE S'AFFICHE.
 *
 * Bug latent trouvé en lisant le code : `WEEK.findIndex((d) => d.n === slot.dayOfWeek)`
 * rendait **-1** pour un dimanche, puisque `WEEK` s'arrêtait au samedi. L'onglet Réserver
 * ne rendait donc RIEN pour ce créneau — pas une colonne fausse, une disparition pure :
 * la garde `di < 0` le supprimait silencieusement, alors qu'il était bien servi au solveur.
 */
describe("ReservationGrid — le dimanche", () => {
  it("rend un créneau du dimanche dans la DERNIÈRE colonne", () => {
    render(<ReservationGrid venue={venue} slots={[sunday]} reservedTeams={new Map()} slotKeyOf={() => "k"} capacityOf={() => 2} onSelectSlot={vi.fn()} />);

    const cell = screen.getByRole("button", { name: /^Dim 20:00 · Gymnase A/ });

    // La présence seule ne suffirait pas : c'est la COLONNE qui portait le défaut. Sept
    // jours ⇒ la gouttière occupe la colonne 1 et le dimanche la 8ᵉ.
    expect(cell).toHaveStyle({ gridColumn: `${1 + WEEK.length}` });
  });
});

describe("ReservationGrid — libellé de groupe (P2-17)", () => {
  it("affiche le libellé d'un créneau mutualisé, sans fusion (badge)", () => {
    const labelled: VenueTrainingSlot = { ...sunday, groupLabel: "CEC3" } as VenueTrainingSlot;
    render(<ReservationGrid venue={venue} slots={[labelled]} reservedTeams={new Map()} slotKeyOf={() => "k"} capacityOf={() => 2} onSelectSlot={vi.fn()} />);

    expect(screen.getByText("CEC3")).toBeInTheDocument();
    // Et dans le nom accessible du créneau, pour l'AT.
    expect(screen.getByRole("button", { name: /· CEC3 ·/ })).toBeInTheDocument();
  });

  it("n'affiche aucun libellé quand le créneau n'en porte pas", () => {
    render(<ReservationGrid venue={venue} slots={[sunday]} reservedTeams={new Map()} slotKeyOf={() => "k"} capacityOf={() => 2} onSelectSlot={vi.fn()} />);

    expect(screen.queryByText("CEC3")).toBeNull();
  });
});
