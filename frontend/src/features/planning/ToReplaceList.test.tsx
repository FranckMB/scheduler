import { render, screen, within } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { axe } from "vitest-axe";

import { ToReplaceList } from "./ToReplaceList";
import type { ToReplaceEntry } from "./lib/toReplaceReason";

const entries: ToReplaceEntry[] = [
  { teamId: "t1", dayOfWeek: 3, startTime: "18:00:00", venueId: "v1", reason: "venue_closed" },
  { teamId: "t2", dayOfWeek: 6, startTime: "10:30:00", venueId: "v2", reason: "team_reduced" },
];

const teamName = (id: string) => ({ t1: "U11 M1", t2: "Séniors F" })[id] ?? id;
const venueName = (id: string) => ({ v1: "Gymnase Matéo", v2: "Salle Bleue" })[id] ?? id;

describe("ToReplaceList", () => {
  it("liste chaque séance non reprise — équipe, jour, heure, gymnase, RAISON en clair (servie par le backend)", () => {
    render(<ToReplaceList entries={entries} teamName={teamName} venueName={venueName} />);

    // Structure : une région nommée + une VRAIE liste (pas un blob).
    const region = screen.getByRole("region", { name: /non reprises/i });
    const items = within(region).getAllByRole("listitem");
    expect(items).toHaveLength(2);

    // Équipe + gymnase d'origine.
    expect(within(items[0]).getByText("U11 M1")).toBeInTheDocument();
    expect(within(items[0]).getByText(/Gymnase Matéo/)).toBeInTheDocument();
    // Jour + heure (normalisée sans les secondes).
    expect(within(items[0]).getByText(/Mer/)).toBeInTheDocument();
    expect(within(items[0]).getByText(/18:00/)).toBeInTheDocument();
    // Raison EN CLAIR, jamais le code brut.
    expect(within(items[0]).getByText("Fermeture du gymnase")).toBeInTheDocument();
    expect(screen.queryByText("venue_closed")).not.toBeInTheDocument();

    expect(within(items[1]).getByText("Séniors F")).toBeInTheDocument();
    expect(within(items[1]).getByText("Séances réduites")).toBeInTheDocument();
  });

  it("liste vide → ne rend RIEN (pas de région fantôme)", () => {
    const { container } = render(<ToReplaceList entries={[]} teamName={teamName} venueName={venueName} />);
    expect(container).toBeEmptyDOMElement();
  });

  it("est accessible (axe)", async () => {
    const { container } = render(<ToReplaceList entries={entries} teamName={teamName} venueName={venueName} />);
    expect(await axe(container)).toHaveNoViolations();
  });
});
