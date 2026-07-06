import { MapPin } from "lucide-react";

import type { Fixture, Team } from "./api";

interface UnplacedListProps {
  fixtures: Fixture[];
  teams: Map<string, Team>;
  selectedFixtureId: string | null;
  onSelect: (id: string) => void;
}

/** A home fixture still needing a venue + kickoff. Away fixtures are not placed here. */
function isUnplacedHome(fixture: Fixture): boolean {
  return "HOME" === fixture.homeAway && (null === fixture.venueId || null === fixture.kickoffTime);
}

/** The to-do list of home matches to place — clicking one opens the placement panel. */
export function UnplacedList({ fixtures, teams, selectedFixtureId, onSelect }: UnplacedListProps) {
  const unplaced = fixtures.filter(isUnplacedHome).sort((a, b) => a.matchDate.localeCompare(b.matchDate));

  if (0 === unplaced.length) {
    return <p className="text-sm text-muted-foreground">Aucun match domicile à placer.</p>;
  }

  return (
    <ul className="flex flex-col gap-1">
      {unplaced.map((fixture) => (
        <li key={fixture.id}>
          <button
            type="button"
            onClick={() => onSelect(fixture.id)}
            aria-pressed={fixture.id === selectedFixtureId}
            className={`flex w-full items-center justify-between gap-2 rounded-md border px-3 py-2 text-left text-sm transition-colors ${
              fixture.id === selectedFixtureId ? "border-accent bg-accent/10" : "border-border hover:bg-muted"
            }`}
          >
            <span className="min-w-0">
              <span className="block truncate font-medium">{teams.get(fixture.teamId)?.name ?? "Équipe ?"}</span>
              <span className="block truncate text-xs text-muted-foreground">
                {fixture.matchDate} · vs {fixture.opponentLabel}
              </span>
            </span>
            <MapPin className="size-4 shrink-0 text-muted-foreground" />
          </button>
        </li>
      ))}
    </ul>
  );
}
