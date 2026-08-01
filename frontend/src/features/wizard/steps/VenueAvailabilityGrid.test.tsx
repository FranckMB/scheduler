import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import type { Venue, VenueTrainingSlot } from "../api";
import { VenueAvailabilityGrid } from "./VenueAvailabilityGrid";

const venue: Venue = { id: "v1", name: "Gymnase A", color: "#00aa00", canSplit: false, isActive: true } as Venue;

const slot = (over: Partial<VenueTrainingSlot>): VenueTrainingSlot =>
  ({ id: "s1", venueId: "v1", dayOfWeek: 1, startTime: "18:00:00", durationMinutes: 90, capacity: 1, ...over }) as VenueTrainingSlot;

/**
 * P4-37 — ce que la grille rend POSABLE.
 *
 * ⚠ Les assertions CLIQUENT au lieu de constater une présence : une cellule rendue mais
 * inerte laisserait passer un test « la case existe », alors que le défaut corrigé est
 * précisément qu'on ne pouvait pas poser de créneau là.
 */
describe("VenueAvailabilityGrid — les bornes de ce qu'on peut poser", () => {
  it("pose un créneau le DIMANCHE (jour 7), que la grille n'affichait pas", async () => {
    const onAdd = vi.fn();
    const user = userEvent.setup();
    render(<VenueAvailabilityGrid venue={venue} slots={[]} selectedSlotId={null} onAdd={onAdd} onSelect={vi.fn()} />);

    await user.click(screen.getByRole("button", { name: "Dim 10:00" }));
    expect(onAdd).toHaveBeenCalledWith(7, "10:00");
  });

  it("pose un créneau après 22h, jusqu'au dernier quart d'heure", async () => {
    const onAdd = vi.fn();
    const user = userEvent.setup();
    render(<VenueAvailabilityGrid venue={venue} slots={[]} selectedSlotId={null} onAdd={onAdd} onSelect={vi.fn()} />);

    await user.click(screen.getByRole("button", { name: "Mar 22:45" }));
    expect(onAdd).toHaveBeenCalledWith(2, "22:45");
  });

  it("affiche un créneau du dimanche déjà posé", () => {
    render(<VenueAvailabilityGrid venue={venue} slots={[slot({ dayOfWeek: 7, startTime: "22:00:00" })]} selectedSlotId={null} onAdd={vi.fn()} onSelect={vi.fn()} />);

    // On vise le TITRE du créneau, pas son texte : « 22:00 » figure aussi dans la
    // gouttière des heures, et un `getByText` y trouverait deux éléments — vert ou rouge
    // pour la mauvaise raison. Avant P4-37, `WEEK.findIndex` rendait -1 sur un dimanche et
    // le créneau n'était pas rendu du tout.
    expect(screen.getByTitle(/22:00 .* cliquer pour modifier/)).toBeInTheDocument();
  });
});
