import { AlertTriangle, Lock, Trash2, Undo2 } from "lucide-react";
import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { Modal } from "@/shared/components/ui/modal";
import { TeamSelect } from "@/shared/components/ui/team-select";

import type { Closure } from "@/features/cockpit/api";

import type { PriorityTier, Reservation, Team, TeamCoach, Venue, VenueTrainingSlot } from "../api";
import { conflictingReservation, mainCoachByTeam } from "../lib/coachDoubleBooking";
import { dayLabel, hhmm } from "../lib/days";
import { closureLabel } from "../lib/venueClosures";
import { assignableTeams, effectiveSlotCapacity, slotKey } from "../lib/reservationSlots";
import { useCreateReservation, useDeleteReservation } from "../queries";
import { WizardStepLink } from "../WizardStepLink";

interface Props {
  slot: VenueTrainingSlot;
  venue: Venue;
  teams: Team[];
  tiers: PriorityTier[];
  reservations: Reservation[];
  /** null = les liens ne sont pas connus (chargement ou échec) — surtout PAS un tableau vide. */
  teamCoaches: TeamCoach[] | null;
  coachesPending: boolean;
  coachesFailed: boolean;
  onRetryCoaches: () => void;
  venues: Venue[];
  disabledVenueIds?: ReadonlySet<string>;
  /** Équipes en pause pour la période : nommées sur un épinglage existant, jamais proposées. */
  pausedTeamIds?: ReadonlySet<string>;
  /** P2-22 D2 — ce créneau tombe un jour de fermeture du gymnase : ajout FERMÉ, retrait ouvert
   *  (l'épinglage sur un jour fermé est orphelin et bloque la génération). Même patron que
   *  `disabledVenueIds`, au grain JOUR : atteignable pour corriger, pas pour aggraver. */
  slotClosed?: boolean;
  /** P2-37 D6 — ce gymnase est ENTIÈREMENT fermé sur la fenêtre (donnée serveur) : le refus est
   *  d'un cran plus fort qu'un jour fermé, et le message le dit comme le serveur (D3). */
  venueFullyClosed?: boolean;
  /** Les fermetures de CE gymnase (titre + bornes) — pour dire à l'écran la même chose que le
   *  refus serveur : quelle fermeture, sur quelles dates. */
  venueClosures?: Closure[];
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
export function SlotReservationModal({
  slot,
  venue,
  teams,
  tiers,
  reservations,
  teamCoaches,
  coachesPending,
  coachesFailed,
  onRetryCoaches,
  venues,
  disabledVenueIds,
  pausedTeamIds,
  slotClosed = false,
  venueFullyClosed = false,
  venueClosures = [],
  venueCanSplit,
  schedulePlanId,
  onClose,
}: Props) {
  const create = useCreateReservation();
  const del = useDeleteReservation();

  // Brouillon local : rien n'est écrit avant « Valider ».
  const [added, setAdded] = useState<string[]>([]);
  const [removed, setRemoved] = useState<string[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [submitError, setSubmitError] = useState<string | null>(null);

  const teamName = new Map(teams.map((t) => [t.id, t.name]));
  const venueName = new Map(venues.map((v) => [v.id, v.name]));
  const coachByTeam = mainCoachByTeam(teamCoaches ?? []);
  const key = slotKey(slot.venueId, slot.dayOfWeek, slot.startTime);
  const capacity = effectiveSlotCapacity(slot, venueCanSplit);

  const onSlot = reservations.filter((r) => slotKey(r.venueId, r.dayOfWeek, r.startTime) === key && !removed.includes(r.id));
  const occupied = onSlot.length + added.length;

  // Le picker doit refléter le BROUILLON, pas l'état serveur : une équipe ajoutée à
  // l'instant ne doit plus être proposée, une équipe retirée doit redevenir choisissable.
  // Le backend écarte les réservations d'un gymnase DÉSACTIVÉ pour la période (elles ne
  // partiront pas au solveur) — la modale doit faire pareil, sinon elle refuse un geste que
  // le récap accepte, en citant un gymnase qui ne sert plus. La parité doit tenir dans les
  // DEUX sens : ni plus permissive, ni plus stricte.
  const inScope = (r: Reservation): boolean => undefined === disabledVenueIds || !disabledVenueIds.has(r.venueId);

  const draftReservations: Reservation[] = [
    ...reservations.filter((r) => !removed.includes(r.id) && inScope(r)),
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
  // ATTEINDRE n'est pas AUTORISER (revue #342 round 2). Un gymnase désactivé reste
  // accessible pour qu'on puisse RETIRER l'épinglage qui bloque la génération ; y AJOUTER
  // en recréerait un — même 422, même impasse. Une équipe en pause suit la même logique :
  // son nom reste lisible sur un épinglage existant, mais elle n'est plus proposée (le
  // solveur ne la verra pas, l'épingler serait un geste sans effet).
  const venueDisabled = true === disabledVenueIds?.has(slot.venueId);
  // `disabledVenueIds` fond DÉSACTIVÉ (override) et ENTIÈREMENT FERMÉ (fermeture, P2-37). On
  // sépare pour dire le BON motif : le désactivé « override » n'est que ce qui reste une fois
  // la fermeture écartée.
  const overrideDisabled = venueDisabled && !venueFullyClosed;
  // Trois causes qui ferment l'AJOUT (le retrait, lui, reste ouvert — geste correctif) :
  // gymnase entièrement fermé, jour fermé, ou désactivé pour la période (P2-22 D2 · P2-37 D6).
  const blockAdd = venueFullyClosed || slotClosed || venueDisabled;
  // Le libellé des fermetures du gymnase (titre + bornes) — même substance que le refus serveur.
  const closureText = venueClosures.map(closureLabel).join(" · ");
  const offerable = undefined === pausedTeamIds ? teams : teams.filter((t) => !pausedTeamIds.has(t.id));
  const pickable = blockAdd ? [] : assignableTeams(offerable, tiers, slot, draftReservations, venueCanSplit);

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

  /**
   * Les écritures partent une par une, et le brouillon est PURGÉ AU FUR ET À MESURE. C'est
   * ce qui rend une reprise sûre : après un échec, « Valider » ne rejoue que ce qui reste
   * — sinon une suppression déjà passée repartirait en 404 (lot bloqué), ou une création
   * déjà passée se dupliquerait, `reservation` ne portant aucune contrainte d'unicité.
   * Le lot n'est pas atomique côté serveur (pas de transaction HTTP) : on ne peut donc pas
   * promettre le tout-ou-rien, mais on peut garantir qu'on ne perd ni ne double rien.
   */
  const submit = async () => {
    setSubmitError(null);
    try {
      // Les retraits d'abord : ils libèrent de la capacité pour les ajouts du même lot.
      for (const id of [...removed]) {
        await del.mutateAsync(id);
        setRemoved((prev) => prev.filter((pending) => pending !== id));
      }
      for (const teamId of [...added]) {
        await create.mutateAsync({ teamId, venueId: slot.venueId, dayOfWeek: slot.dayOfWeek, startTime: hhmm(slot.startTime), durationMinutes: slot.durationMinutes, schedulePlanId });
        setAdded((prev) => prev.filter((pending) => pending !== teamId));
      }
      onClose();
    } catch {
      // La modale RESTE ouverte, avec ce qui n'est pas passé : sans ce message le
      // gestionnaire ne saurait pas qu'une partie de son lot est partie et l'autre non.
      setSubmitError("Une partie des modifications n'a pas pu être enregistrée. Ce qui reste affiché n'est pas encore appliqué — réessayez.");
    }
  };

  const busy = create.isPending || del.isPending;
  const dirty = added.length > 0 || removed.length > 0;
  // La règle ne peut pas s'appliquer sans les liens équipe→coach : une Map vide ne trouve
  // AUCUN conflit. Plutôt que d'autoriser en aveugle (fail-open), on ferme la saisie.
  const guardReady = null !== teamCoaches;
  // Fermer pendant l'envoi laisserait des mutations en vol s'appliquer sans trace à l'écran.
  const dismiss = () => {
    if (!busy) {
      onClose();
    }
  };

  return (
    <Modal label="Réserver ce créneau" title={`${venue.name} · ${dayLabel(slot.dayOfWeek)} ${hhmm(slot.startTime)}`} onClose={dismiss}>
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
                className="rounded p-1 text-muted-foreground hover:text-destructive"
                onClick={() => {
                  setError(null); // le refus affiché peut devenir caduc en libérant la place
                  setRemoved((prev) => [...prev, r.id]);
                }}
              >
                <Trash2 className="size-4" />
              </button>
            </li>
          ))}
          {/* Un retrait en attente reste NOMMÉ et annulable : le remplacer par un compteur
              anonyme empêchait de savoir quelle équipe on avait retirée, et de revenir en
              arrière autrement qu'en fermant la modale — ce qui abandonnait aussi les ajouts. */}
          {reservations
            .filter((r) => removed.includes(r.id))
            .map((r) => (
              <li key={`removed-${r.id}`} className="flex items-center gap-2 rounded-md border border-dashed border-destructive/50 bg-destructive/5 px-3 py-1.5 text-sm">
                <Trash2 className="size-3.5 text-destructive" />
                <span className="flex-1 font-medium line-through">{teamName.get(r.teamId) ?? "?"}</span>
                <span className="text-xs text-muted-foreground">retrait à valider</span>
                <button
                  type="button"
                  aria-label={`Annuler le retrait de ${teamName.get(r.teamId) ?? "l'équipe"}`}
                  // AUD-A11Y-15 — p-1 : 16 px d'icône + 4 px de part et d'autre = 24 px, le
                  // minimum WCAG 2.5.8. Ce bouton était le seul des trois du fichier resté nu
                  // après AUD-A11Y-12 (ses jumeaux :213 et :251 l'avaient déjà).
                  className="rounded p-1 text-muted-foreground hover:text-foreground"
                  onClick={() => setRemoved((prev) => prev.filter((id) => id !== r.id))}
                >
                  <Undo2 className="size-4" />
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
                className="rounded p-1 text-muted-foreground hover:text-destructive"
                onClick={() => {
                  setError(null);
                  setAdded((prev) => prev.filter((id) => id !== teamId));
                }}
              >
                <Undo2 className="size-4" />
              </button>
            </li>
          ))}
        </ul>
      ) : null}

      {/* Les motifs de blocage disent la MÊME chose que le refus serveur (P2-37 D3) : la
          fermeture TOTALE prime sur le jour fermé (refus d'un cran plus fort), et le désactivé
          « override » n'est que ce qui reste une fois la fermeture écartée. */}
      {venueFullyClosed ? (
        <p role="status" className="rounded-md border border-warning/40 bg-warning/10 px-3 py-2 text-xs text-foreground">
          Ce gymnase est indisponible sur toute la période{closureText ? ` — ${closureText}` : ""} : la séance ne peut pas y être réservée. Retirez les réservations ci-dessus pour débloquer la génération ; ajustez ou levez la fermeture pour rouvrir ce gymnase.
        </p>
      ) : slotClosed ? (
        <p role="status" className="rounded-md border border-warning/40 bg-warning/10 px-3 py-2 text-xs text-foreground">
          Ce gymnase est fermé ce jour-là{closureText ? ` — ${closureText}` : ""} : la séance ne peut pas y être réservée ici. Retirez les réservations ci-dessus pour débloquer la génération.
        </p>
      ) : overrideDisabled ? (
        <p role="status" className="rounded-md border border-warning/40 bg-warning/10 px-3 py-2 text-xs text-foreground">
          {venue.name} est désactivé pour cette période : on ne peut plus y ajouter d'équipe. Retirez les réservations ci-dessus pour débloquer la génération.
        </p>
      ) : !guardReady ? (
        <p role="status" className="rounded-md border border-border bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
          {coachesPending ? (
            "Vérification des coachs en cours…"
          ) : (
            <>
              {coachesFailed ? "Impossible de vérifier les coachs" : "Vérification des coachs indisponible"} — la saisie est bloquée pour ne pas créer un conflit sans le voir.{" "}
              <button type="button" className="underline" onClick={onRetryCoaches}>
                Réessayer
              </button>
            </>
          )}
        </p>
      ) : occupied < capacity ? (
        pickable.length > 0 ? (
          // Groupes par rang (demande fondateur 2026-08-04) : 49 équipes à plat sont
          // illisibles — TeamSelect est le home unique du découpage S/A/B/C/D.
          <TeamSelect aria-label="Ajouter une équipe" className="h-9 w-full" value="" onChange={(e) => pick(e.target.value)} disabled={busy} teams={pickable} tiers={tiers} placeholder="— ajouter une équipe —" />
        ) : (
          <p className="text-xs text-muted-foreground">Aucune équipe disponible (toutes ont atteint leur nombre de séances ou sont déjà sur ce créneau).</p>
        )
      ) : (
        <div className="text-xs text-muted-foreground">
          <p>Créneau complet ({occupied}/{capacity}).</p>
          {/* P2-25 lien A — c'est l'instant où l'on comprend qu'il faut plus de place. Plutôt
              que subir, on va RÉGLER ce créneau là où il vit (étape Gymnases), positionné
              dessus. Retour nommé « ← Retour à la réservation ». */}
          <WizardStepLink
            step="venues"
            params={{ slot: slot.id }}
            from="reservation"
            className="mt-1 inline-flex items-center gap-1 font-medium text-accent underline underline-offset-2 hover:text-accent/80"
          >
            Régler ce créneau dans « Gymnases »
          </WizardStepLink>
        </div>
      )}

      {null !== error || null !== submitError ? (
        <p role="alert" className="mt-3 flex items-start gap-2 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-xs text-destructive">
          <AlertTriangle className="mt-0.5 size-3.5 shrink-0" />
          <span>{submitError ?? error}</span>
        </p>
      ) : null}

      <div className="mt-4 flex justify-end gap-2">
        <Button variant="ghost" onClick={dismiss} disabled={busy}>
          Annuler
        </Button>
        <Button onClick={() => void submit()} disabled={busy || !dirty}>
          {busy ? "Enregistrement…" : "Valider"}
        </Button>
      </div>
    </Modal>
  );
}
