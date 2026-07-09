import { AlertTriangle, Check, X } from "lucide-react";
import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/shared/components/ui/card";

import type { Fixture, PlaceFixtureInput, Venue } from "./api";
import type { EnvelopeResult } from "./lib/envelope";
import { isInEnvelope } from "./lib/envelope";

interface PlacementPanelProps {
  fixture: Fixture;
  venues: Venue[];
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

/** Place a home fixture: pick venue + kickoff. Blocks out-of-envelope only when the team maps. */
export function PlacementPanel({ fixture, venues, teamLabel, categoryLabel, envelope, busy, onClose, onPlace }: PlacementPanelProps) {
  const [venueId, setVenueId] = useState(fixture.venueId ?? venues[0]?.id ?? "");
  const [kickoff, setKickoff] = useState(fixture.kickoffTime ?? "");

  const hasKickoff = "" !== kickoff;
  const blocked = envelope.mapped && hasKickoff && !isInEnvelope(envelope, kickoff);
  const canPlace = "" !== venueId && hasKickoff && !blocked && !busy;

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
              {venues.map((v) => (
                <option key={v.id} value={v.id}>
                  {v.name}
                </option>
              ))}
            </select>
            <input aria-label="Heure de coup d'envoi" type="time" value={kickoff} onChange={(e) => setKickoff(e.target.value)} className={fieldClass} />
          </div>

          {hasKickoff ? <EnvelopeHint envelope={envelope} kickoff={kickoff} /> : null}

          <Button size="sm" disabled={!canPlace} onClick={() => onPlace({ venueId, kickoffTime: kickoff })}>
            Placer
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}
