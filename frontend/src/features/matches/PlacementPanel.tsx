import { AlertTriangle, ArrowLeftRight, Check, Lock, LockOpen, Pencil, Trash2, Undo2, X } from "lucide-react";
import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";

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
  /** P1-4 PR E1 — manual loop on an already-placed match. */
  onUnplace: () => void;
  onToggleLock: () => void;
  onStartSwap: () => void;
  onEdit: () => void;
  onDelete: () => void;
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
 * Place / re-place a home fixture and drive the manual loop (P1-4 PR E1):
 * unplace, lock/hand back to the solver, swap, edit, delete. Two HARD guards on
 * the placement gesture itself: the league envelope (when the team maps) and the
 * club's capacity data (PR B). A SUBMITTED/VALIDATED match is read-only — it is
 * filed with the federation.
 */
export function PlacementPanel({
  fixture,
  venues,
  matchWindows,
  unavailabilities,
  habits,
  teamLabel,
  categoryLabel,
  envelope,
  busy,
  onClose,
  onPlace,
  onUnplace,
  onToggleLock,
  onStartSwap,
  onEdit,
  onDelete,
}: PlacementPanelProps) {
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
  const [confirmDelete, setConfirmDelete] = useState(false);

  const placed = "PLACED" === fixture.status;
  const readonly = "SUBMITTED" === fixture.status || "VALIDATED" === fixture.status;
  const locked = placed && "SOLVER" !== fixture.placementSource;

  const hasKickoff = "" !== kickoff;
  const envelopeBlocked = envelope.mapped && hasKickoff && !isInEnvelope(envelope, kickoff);
  const venueName = venues.find((v) => v.id === venueId)?.name ?? "ce gymnase";
  const accessError = "" === venueId ? null : venueAccessError(venueId, venueName, fixture.matchDate, kickoff, matchWindows, unavailabilities);
  const unchanged = placed && venueId === (fixture.venueId ?? "") && kickoff === (fixture.kickoffTime ?? "");
  const canPlace = "" !== venueId && hasKickoff && !envelopeBlocked && null === accessError && !busy && !unchanged;

  return (
    <Card>
      <CardHeader className="flex-row items-center justify-between">
        <CardTitle className="flex items-center gap-1.5">
          {locked ? <Lock aria-label="Ancre manuelle" className="size-3.5 text-muted-foreground" /> : null}
          {teamLabel}
        </CardTitle>
        <button type="button" onClick={onClose} aria-label="Fermer" className="text-muted-foreground hover:text-foreground">
          <X className="size-4" />
        </button>
      </CardHeader>
      <CardContent className="pt-0">
        <Row label="Catégorie" value={categoryLabel} />
        <Row label="Adversaire" value={fixture.opponentLabel} />
        <Row label="Date" value={fixture.matchDate} />

        {readonly ? (
          <p className="mt-3 border-t border-border pt-3 text-xs text-muted-foreground">
            Match déposé à la fédération — il ne se modifie plus ici.
          </p>
        ) : (
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
              {placed ? "Déplacer" : "Placer"}
            </Button>

            {placed ? (
              <div className="grid grid-cols-2 gap-1.5">
                <Button variant="outline" size="sm" disabled={busy} onClick={onUnplace}>
                  <Undo2 className="size-3.5" />
                  Dé-placer
                </Button>
                <Button
                  variant="outline"
                  size="sm"
                  disabled={busy}
                  title={locked ? "Le solveur pourra re-placer ce match au prochain passage" : "Figer ce placement — le solveur ne le bougera plus"}
                  onClick={onToggleLock}
                >
                  {locked ? <LockOpen className="size-3.5" /> : <Lock className="size-3.5" />}
                  {locked ? "Rendre au solveur" : "Verrouiller"}
                </Button>
                <Button variant="outline" size="sm" disabled={busy} className="col-span-2" onClick={onStartSwap}>
                  <ArrowLeftRight className="size-3.5" />
                  Échanger avec…
                </Button>
              </div>
            ) : null}

            <div className="grid grid-cols-2 gap-1.5 border-t border-border pt-2">
              <Button variant="outline" size="sm" disabled={busy} onClick={onEdit}>
                <Pencil className="size-3.5" />
                Modifier
              </Button>
              <Button variant="outline" size="sm" disabled={busy} onClick={() => setConfirmDelete(true)}>
                <Trash2 className="size-3.5" />
                Supprimer
              </Button>
            </div>
          </div>
        )}
      </CardContent>

      <ConfirmDialog
        open={confirmDelete}
        title={`Supprimer le match contre « ${fixture.opponentLabel} » ?`}
        description="Le match disparaît du calendrier. S'il vient d'un import FBI, le prochain import le recréera."
        confirmLabel="Supprimer"
        destructive
        onConfirm={() => {
          setConfirmDelete(false);
          onDelete();
        }}
        onCancel={() => setConfirmDelete(false)}
      />
    </Card>
  );
}
