import { AlertTriangle, Lock, Trash2, Undo2 } from "lucide-react";
import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { Modal } from "@/shared/components/ui/modal";
import { Select } from "@/shared/components/ui/select";

import type { PriorityTier, Reservation, Team, TeamCoach, Venue, VenueTrainingSlot } from "../api";
import { conflictingReservation, mainCoachByTeam } from "../lib/coachDoubleBooking";
import { dayLabel, hhmm } from "../lib/days";
import { assignableTeams, effectiveSlotCapacity, slotKey } from "../lib/reservationSlots";
import { useCreateReservation, useDeleteReservation } from "../queries";

interface Props {
  slot: VenueTrainingSlot;
  venue: Venue;
  teams: Team[];
  tiers: PriorityTier[];
  reservations: Reservation[];
  teamCoaches: TeamCoach[];
  venues: Venue[];
  venueCanSplit: Map<string, boolean>;
  schedulePlanId: string | null;
  onClose: () => void;
}

/**
 * Affecter/retirer des équipes sur UN créneau (onglet « Réserver »).
 *
 * P2-9 PR C — la modale est TRANSACTIONNELLE (décision fondateur 2026-07-31) : on compose
 * ses ajouts et ses retraits, puis on VALIDE. Auparavant chaque geste partait aussitôt,
 * donc rien ne pouvait s'interposer entre le choix et l'écriture — or c'est précisément là
 * que le contrôle doit vivre : « on ajoute un bouton de validation qui affecte au moment
 * du ok et c'est là que le validator intervient ».
 *
 * Le contrôle : affecter une équipe dont le coach MAIN est déjà ailleurs à la même heure
 * est une IMPOSSIBILITÉ PHYSIQUE, que le solveur ne peut pas rattraper (un verrou HARD est
 * pré-placé hors modèle, ALIGN-07). Le récap la refuse déjà (PR B) ; ici on l'annonce au
 * moment du geste, avec le motif, pour que le gestionnaire comprenne au lieu de subir un
 * refus plus tard.
 *
 * Fermer sans valider ABANDONNE le brouillon (décision fondateur) : comportement standard
 * d'un dialogue OK/Annuler, et les changements en attente sont visibles à l'écran.
 */
export function SlotReservationModal({ slot, venue, teams, tiers, reservations, teamCoaches, venues, venueCanSplit, schedulePlanId, onClose }: Props) {
  const create = useCreateReservation();
  const del = useDeleteReservation();

  // Brouillon local : rien n'est écrit avant « Valider ».
  const [added, setAdded] = useState<string[]>([]);
  const [removed, setRemoved] = useState<string[]>([]);
  const [error, setError] = useState<string | null>(null);

  const teamName = new Map(teams.map((t) => [t.id, t.name]));
  const venueName = new Map(venues.map((v) => [v.id, v.name]));
  const coachByTeam = mainCoachByTeam(teamCoaches);
  const key = slotKey(slot.venueId, slot.dayOfWeek, slot.startTime);
  const capacity = effectiveSlotCapacity(slot, venueCanSplit);

  const onSlot = reservations.filter((r) => slotKey(r.venueId, r.dayOfWeek, r.startTime) === key && !removed.includes(r.id));
  const occupied = onSlot.length + added.length;

  // Le picker doit refléter le BROUILLON, pas l'état serveur : une équipe ajoutée à
  // l'instant ne doit plus être proposée, une équipe retirée doit redevenir choisissable.
  const draftReservations: Reservation[] = [
    ...reservations.filter((r) => !removed.includes(r.id)),
    ...added.map((teamId) => ({
      id: `draft-${teamId}`,
      schedulePlanId,
      teamId,
      venueId: slot.venueId,
      dayOfWeek: slot.dayOfWeek,
      startTime: hhmm(slot.startTime),
      durationMinutes: slot.durationMinutes,
    })),
  ];
  const pickable = assignableTeams(teams, tiers, slot, draftReservations, venueCanSplit);

  const pick = (teamId: string) => {
    if ("" === teamId) {
      return;
    }
    const candidate = { teamId, venueId: slot.venueId, dayOfWeek: slot.dayOfWeek, startTime: hhmm(slot.startTime), durationMinutes: slot.durationMinutes };
    const clash = conflictingReservation(candidate, draftReservations, coachByTeam);
    if (null !== clash) {
      // Le message nomme l'équipe, l'heure et le gymnase : sans ça le gestionnaire sait
      // qu'on refuse, pas ce qu'il doit changer.
      setError(
        `${teamName.get(teamId) ?? "Cette équipe"} ne peut pas être ajoutée : son coach entraîne déjà ${teamName.get(clash.teamId) ?? "une autre équipe"} à ${hhmm(clash.startTime)} à ${venueName.get(clash.venueId) ?? "un autre gymnase"}.`,
      );

      return;
    }
    setError(null);
    setAdded((prev) => [...prev, teamId]);
  };

  const submit = async () => {
    // Les retraits d'abord : ils libèrent de la capacité pour les ajouts du même lot.
    for (const id of removed) {
      await del.mutateAsync(id);
    }
    for (const teamId of added) {
      await create.mutateAsync({ teamId, venueId: slot.venueId, dayOfWeek: slot.dayOfWeek, startTime: hhmm(slot.startTime), durationMinutes: slot.durationMinutes, schedulePlanId });
    }
    onClose();
  };

  const busy = create.isPending || del.isPending;
  const dirty = added.length > 0 || removed.length > 0;

  return (
    <Modal label="Réserver ce créneau" title={`${venue.name} · ${dayLabel(slot.dayOfWeek)} ${hhmm(slot.startTime)}`} onClose={onClose}>
      <p className="mb-3 text-xs text-muted-foreground">
        Fixe une équipe sur ce créneau (verrou pris en compte à chaque génération). Ce créneau accepte {capacity} équipe{capacity > 1 ? "s" : ""}.
      </p>

      {onSlot.length > 0 || added.length > 0 ? (
        <ul className="mb-3 flex flex-col gap-1">
          {onSlot.map((r) => (
            <li key={r.id} className="flex items-center gap-2 rounded-md border border-border bg-card px-3 py-1.5 text-sm">
              <Lock className="size-3.5 text-accent" />
              <span className="flex-1 font-medium">{teamName.get(r.teamId) ?? "?"}</span>
              <button
                type="button"
                aria-label={`Retirer ${teamName.get(r.teamId) ?? "l'équipe"}`}
                className="text-muted-foreground hover:text-destructive"
                onClick={() => setRemoved((prev) => [...prev, r.id])}
              >
                <Trash2 className="size-4" />
              </button>
            </li>
          ))}
          {added.map((teamId) => (
            <li key={`draft-${teamId}`} className="flex items-center gap-2 rounded-md border border-dashed border-accent/60 bg-accent/5 px-3 py-1.5 text-sm">
              <Lock className="size-3.5 text-accent" />
              <span className="flex-1 font-medium">{teamName.get(teamId) ?? "?"}</span>
              <span className="text-xs text-muted-foreground">à valider</span>
              <button
                type="button"
                aria-label={`Annuler l'ajout de ${teamName.get(teamId) ?? "l'équipe"}`}
                className="text-muted-foreground hover:text-destructive"
                onClick={() => setAdded((prev) => prev.filter((id) => id !== teamId))}
              >
                <Undo2 className="size-4" />
              </button>
            </li>
          ))}
        </ul>
      ) : null}

      {removed.length > 0 ? (
        <p className="mb-3 text-xs text-muted-foreground">
          {removed.length} retrait{removed.length > 1 ? "s" : ""} en attente de validation.
        </p>
      ) : null}

      {occupied < capacity ? (
        pickable.length > 0 ? (
          <Select aria-label="Ajouter une équipe" className="h-9 w-full" value="" onChange={(e) => pick(e.target.value)} disabled={busy}>
            <option value="">— ajouter une équipe —</option>
            {pickable.map((t) => (
              <option key={t.id} value={t.id}>
                {t.name}
              </option>
            ))}
          </Select>
        ) : (
          <p className="text-xs text-muted-foreground">Aucune équipe disponible (toutes ont atteint leur nombre de séances ou sont déjà sur ce créneau).</p>
        )
      ) : (
        <p className="text-xs text-muted-foreground">
          Créneau complet ({occupied}/{capacity}).
        </p>
      )}

      {null !== error ? (
        <p role="alert" className="mt-3 flex items-start gap-2 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-xs text-destructive">
          <AlertTriangle className="mt-0.5 size-3.5 shrink-0" />
          <span>{error}</span>
        </p>
      ) : null}

      <div className="mt-4 flex justify-end gap-2">
        <Button variant="ghost" onClick={onClose} disabled={busy}>
          Annuler
        </Button>
        <Button onClick={() => void submit()} disabled={busy || !dirty}>
          {busy ? "Enregistrement…" : "Valider"}
        </Button>
      </div>
    </Modal>
  );
}
