import { AlertTriangle, Check, X } from "lucide-react";
import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/shared/components/ui/card";

import type { Fixture, PlaceFixtureInput, TeamMatchHabit, Venue, VenueMatchWindow, VenueUnavailability } from "./api";
import { isInEnvelope, isoWeekday } from "./lib/envelope";
import type { EnvelopeResult } from "./lib/envelope";
import { matchVenueIds, venueAccessError } from "./lib/matchAccess";

interface PlacementPanelProps {
  fixture: Fixture;
  venues: Venue[];
  matchWindows: VenueMatchWindow[];
  unavailabilities: VenueUnavailability[];
  /** P1-4 PR C — the team's habitual windows: prefill + hint, never a guard. */
  habits: TeamMatchHabit[];
  teamLabel: string;
  categoryLabel: string;
  envelope: EnvelopeResult;
  busy: boolean;
  onClose: () => void;
  onPlace: (input: PlaceFixtureInput) => void;
}

const fieldClass = "h-9 rounded-md border border-input bg-background px-2 text-sm";

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex justify-between gap-4 py-1 text-sm">
      <span className="text-muted-foreground">{label}</span>
      <span className="text-right font-medium">{value}</span>
    </div>
  );
}

/** Envelope guidance line: HARD when the team maps, advisory reference otherwise. */
function EnvelopeHint({ envelope, kickoff }: { envelope: EnvelopeResult; kickoff: string }) {
  if (!envelope.mapped) {
    if (0 === envelope.windows.length) {
      return null;
    }
    const windows = envelope.windows.map((w) => `${w.kickoffMin}–${w.kickoffMax}`).join(", ");
    return <p className="text-xs text-muted-foreground">Fenêtres ligue (indicatif) : {windows}</p>;
  }
  const ok = isInEnvelope(envelope, kickoff);
  return (
    <p className={`flex items-center gap-1 text-xs ${ok ? "text-success" : "text-warning"}`}>
      {ok ? <Check className="size-3.5" /> : <AlertTriangle className="size-3.5" />}
      {ok ? "Dans la fenêtre autorisée par la ligue" : "Hors fenêtre autorisée (jour ou heure)"}
    </p>
  );
}

/**
 * Place a home fixture: pick venue + kickoff. Two HARD guards: the league
 * envelope (when the team maps) and the club's own capacity data (P1-4 PR B —
 * match access windows + unavailabilities; the club declared them itself, no
 * degradation). A club with no window anywhere keeps the full venue list.
 */
export function PlacementPanel({ fixture, venues, matchWindows, unavailabilities, habits, teamLabel, categoryLabel, envelope, busy, onClose, onPlace }: PlacementPanelProps) {
  // Masquer n'est légitime que pour un CHOIX (§7.2.3) : le sélecteur n'offre
  // que les gymnases de match — mais seulement si le club a déclaré des
  // fenêtres quelque part (sinon liste complète, donnée non adoptée).
  const matchIds = matchVenueIds(matchWindows);
  const selectableVenues = 0 === matchIds.size ? venues : venues.filter((v) => matchIds.has(v.id));

  // P1-4 PR C — the team's habit on the MATCH's weekday prefills the empty
  // fields (venue must survive the selectable filter). Guards stay sovereign:
  // a habit prefills, it never unlocks.
  const habit = habits.find((h) => h.teamId === fixture.teamId && h.dayOfWeek === isoWeekday(fixture.matchDate)) ?? null;

  const [venueId, setVenueId] = useState(() => {
    const habitVenue = null !== habit && null !== habit.venueId && selectableVenues.some((v) => v.id === habit.venueId) ? habit.venueId : "";
    const initial = fixture.venueId ?? ("" !== habitVenue ? habitVenue : (selectableVenues[0]?.id ?? ""));
    return "" === initial || selectableVenues.some((v) => v.id === initial) ? initial : (selectableVenues[0]?.id ?? "");
  });
  const [kickoff, setKickoff] = useState(fixture.kickoffTime ?? habit?.kickoffTime ?? "");

  const hasKickoff = "" !== kickoff;
  const envelopeBlocked = envelope.mapped && hasKickoff && !isInEnvelope(envelope, kickoff);
  const venueName = venues.find((v) => v.id === venueId)?.name ?? "ce gymnase";
  const accessError = "" === venueId ? null : venueAccessError(venueId, venueName, fixture.matchDate, kickoff, matchWindows, unavailabilities);
  const canPlace = "" !== venueId && hasKickoff && !envelopeBlocked && null === accessError && !busy;

  return (
    <Card>
      <CardHeader className="flex-row items-center justify-between">
        <CardTitle>{teamLabel}</CardTitle>
        <button type="button" onClick={onClose} aria-label="Fermer" className="text-muted-foreground hover:text-foreground">
          <X className="size-4" />
        </button>
      </CardHeader>
      <CardContent className="pt-0">
        <Row label="Catégorie" value={categoryLabel} />
        <Row label="Adversaire" value={fixture.opponentLabel} />
        <Row label="Date" value={fixture.matchDate} />

        <div className="mt-3 flex flex-col gap-2 border-t border-border pt-3">
          <div className="grid grid-cols-2 gap-2">
            <select aria-label="Gymnase" value={venueId} onChange={(e) => setVenueId(e.target.value)} className={fieldClass}>
              <option value="" disabled>
                Gymnase…
              </option>
              {selectableVenues.map((v) => (
                <option key={v.id} value={v.id}>
                  {v.name}
                </option>
              ))}
            </select>
            <input aria-label="Heure de coup d'envoi" type="time" value={kickoff} onChange={(e) => setKickoff(e.target.value)} className={fieldClass} />
          </div>

          {null !== habit ? (
            <p className="text-xs text-muted-foreground">
              Habitude : {habit.kickoffTime}
              {null !== habit.venueId ? ` · ${venues.find((v) => v.id === habit.venueId)?.name ?? "?"}` : ""}
            </p>
          ) : null}
          {hasKickoff ? <EnvelopeHint envelope={envelope} kickoff={kickoff} /> : null}
          {null !== accessError ? (
            <p className="flex items-center gap-1 text-xs text-warning">
              <AlertTriangle className="size-3.5" />
              {accessError}
            </p>
          ) : null}

          <Button size="sm" disabled={!canPlace} onClick={() => onPlace({ venueId, kickoffTime: kickoff })}>
            Placer
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}
