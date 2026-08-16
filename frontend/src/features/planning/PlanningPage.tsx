import { IN_FLIGHT_STATUSES } from "./lib/scheduleStatus";
import { useQueryClient } from "@tanstack/react-query";
import { AlertTriangle, CalendarX2, CheckCircle2, Pencil, Star } from "lucide-react";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useNavigate } from "react-router";

import { useMe, useRenamePlanning, useWorkingSeason } from "@/features/auth/queries";
import { FeedbackButton } from "@/features/feedback/FeedbackButton";
import { useWizardStore } from "@/features/wizard/store";
// Same ["priority_tiers"] query key as the matches/wizard hooks — one cache entry.
import { usePriorityTiers } from "@/features/matches/queries";
import { DeletePlanningButton } from "@/features/cockpit/DeletePlanningButton";
import { useSchedulePlans } from "@/features/cockpit/queries";
import { useReservations, useVenuePeriodOverrides, useWizardTeamTagAssignments, useWizardTeamTags } from "@/features/wizard/queries";
import { coachFullName } from "@/shared/lib/coachName";
import { readFailed, readLoading } from "@/shared/lib/readState";
import { useCredits } from "@/shared/credits/useCredits";
import { Button } from "@/shared/components/ui/button";
import { Card, CardDescription, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { Modal } from "@/shared/components/ui/modal";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { FullPageSpinner } from "@/shared/components/ui/spinner";

import { GenerationInProgressError, MoveRejectedError, OverlaysExistError, type Slot } from "./api";
import { DiagnosticsPanel } from "./DiagnosticsPanel";
import { ExportMenu } from "./ExportMenu";
import { GenerationWaiting } from "./GenerationWaiting";
import { buildTagTeamIds } from "./lib/applicableConstraints";
import { topSeveritySummary } from "./lib/diagnosticsSummary";
import { computeEmptySlots } from "./lib/emptySlots";
import { violationHighlightSlotIds } from "./lib/violationHighlight";
import { availableResourceGroups, buildGrid, type Lookups, slotGroupKey } from "./lib/grid";
import { PlanningToolbar } from "./PlanningToolbar";
import { useCategories, useCoachPlayers, useCoaches, useConstraints, useDeleteSchedule, useDiagnostics, useLockSlot, useMoveSlot, useRegenerate, useRegenerateFromVersion, useRegenerateOverlay, useReopenSchedule, useSchedules, useSlots, useTeamCoaches, useTeams, useTrainingSlots, useValidateSchedule, useVenues } from "./queries";
import { ResourceFilter } from "./ResourceFilter";
import { SlotDetail, type MoveFeedback } from "./SlotDetail";

import { pickLandingScheduleId } from "./lib/pickLandingSchedule";
import { stalenessMessage } from "./lib/staleness";
import { isSeasonPlanType } from "./lib/versions";
import { usePlanningStore } from "./store";
import { WeekGrid } from "./WeekGrid";

// D-31 : foyer unique dans `api.ts`.
const IN_FLIGHT: readonly string[] = IN_FLIGHT_STATUSES;

function ValidateDialog({ hasAlerts, siblingCount, busy, onConfirm, onCancel }: { hasAlerts: boolean; siblingCount: number; busy: boolean; onConfirm: () => void; onCancel: () => void }) {
  return (
    <Modal
      label="Valider le planning"
      title={
        <span className="flex items-center gap-2">
          {hasAlerts ? <AlertTriangle aria-hidden="true" className="size-5 text-warning" /> : <CheckCircle2 aria-hidden="true" className="size-5 text-muted-foreground" />}
          Valider ce planning ?
        </span>
      }
      // Block Escape/overlay/X dismissal while the validation is in flight: dismissing
      // mid-request would hide the dialog but let the un-aborted mutation still lock the
      // planning read-only (the raw dialog had no escape at all during busy).
      onClose={() => {
        if (!busy) {
          onCancel();
        }
      }}
    >
      <p className="mt-2 text-sm text-muted-foreground">
        {hasAlerts
          ? "Ce planning présente des alertes du système (créneaux non placés, contraintes non satisfaites…). En le validant, vous assumez ces contre-indications sous votre responsabilité. Le planning passera en lecture seule."
          : "Le planning passera en lecture seule (« Validé »). Vous pourrez le rouvrir pour le modifier."}
      </p>
      {siblingCount > 0 ? (
        <p className="mt-3 text-sm font-medium text-foreground">
          Seule cette version sera conservée — {siblingCount > 1 ? `les ${siblingCount} autres versions seront définitivement supprimées` : "l'autre version sera définitivement supprimée"}.
        </p>
      ) : null}
      <div className="mt-6 flex justify-end gap-2">
        <Button variant="outline" size="sm" onClick={onCancel} disabled={busy}>
          Annuler
        </Button>
        <Button size="sm" onClick={onConfirm} disabled={busy}>
          Valider
        </Button>
      </div>
    </Modal>
  );
}

function EmptyState({ title, description }: { title: string; description: string }) {
  return (
    <Card className="border-dashed">
      <CardHeader>
        <div className="flex items-center gap-2">
          <CalendarX2 className="size-5 text-muted-foreground" />
          <CardTitle>{title}</CardTitle>
        </div>
        <CardDescription>{description}</CardDescription>
      </CardHeader>
    </Card>
  );
}

/** `embedded` = rendered inside the wizard's Génération step, where the sticky
 *  wizard header + footer eat extra vertical space, so the grid must be shorter. */
export function PlanningPage({ embedded = false }: { embedded?: boolean } = {}) {
  const { data: schedules = [], isLoading: schedulesLoading } = useSchedules();
  const { data: me } = useMe();
  // §4bis pt 2 — solde de crédits sur « Régénérer » (Découverte bridée seulement).
  const credits = useCredits();
  const chosenScheduleId = me?.seasonPlan?.chosenScheduleId ?? null;
  const { viewMode, selectedScheduleId, selectedSlotId, resourceFilter, setViewMode, setSelectedScheduleId, setSelectedSlotId, toggleResource, clearResourceFilter } =
    usePlanningStore();
  const [highlightSlotIds, setHighlightSlotIds] = useState<Set<string>>(new Set());
  // Déverrouiller un créneau né d'une RÉSERVATION de gymnase demande confirmation (F1) : c'est
  // un engagement pris hors de l'app, à ne pas relâcher par inadvertance. On mémorise LE créneau
  // visé (et non un booléen) : le cadenas de la grille (PR 2) peut viser un créneau NON
  // sélectionné, la confirmation doit muter celui-là, pas le sélectionné.
  const [pendingUnlockSlotId, setPendingUnlockSlotId] = useState<string | null>(null);
  // Source partagée avec le cockpit (radar/DayDialog) — une seule dérivation de
  // la saison de travail, plus de copie inline qui pourrait diverger.
  const workingSeason = useWorkingSeason();

  // Keep a valid selection: default to the season base plan, else the latest
  // completed. A selection archived concurrently (sibling validation in another
  // tab) is invalid too — the selector has no option for it.
  const validScheduleId = schedules.some((s) => s.id === selectedScheduleId) ? selectedScheduleId : null;
  useEffect(() => {
    if (null === validScheduleId && schedules.length > 0) {
      setSelectedScheduleId(pickLandingScheduleId(schedules));
    }
  }, [validScheduleId, schedules, chosenScheduleId, setSelectedScheduleId]);

  // La COUCHE de créneaux de la version affichée (#8) : le socle lit la grille de
  // saison, une période lit la sienne. Dérivée ici, avant les requêtes, pour que
  // l'écran et l'export montrent les mêmes créneaux vides.
  const displayed = schedules.find((s) => s.id === validScheduleId) ?? null;
  const slotLayerId = null !== displayed && !isSeasonPlanType(displayed.planType) ? (displayed.schedulePlanId ?? null) : null;

  const { data: generatedSlots = [] } = useSlots(validScheduleId);

  // Génération en ÉCHEC : aucun créneau généré, mais les réservations (verrous HARD
  // posés par le gestionnaire) existent indépendamment du résultat — « par défaut il y
  // a au moins les créneaux réservés qui doivent s'afficher » (fondateur, 2026-08-05).
  // Elles entrent dans la grille en pseudo-créneaux HARD, LECTURE SEULE (ids
  // `reservation-*` : aucun PATCH slot ne doit les viser), sur la MÊME couche que le
  // payload du solveur : socle = réservations permanentes, période = celles de son plan.
  const isFailed = "FAILED" === displayed?.status;
  // P2-15 — un gymnase DÉSACTIVÉ pour la période garde ses créneaux en base (le backend
  // les écarte du payload, il ne les supprime pas) : sans ce filtre, l'écran de génération
  // affichait TOUS les gymnases du club alors qu'un seul sert — « du bruit pour rien ».
  // On filtre à la SOURCE : la grille, ses fenêtres vides et le sélecteur en dérivent tous.
  // FAIL-CLOSED : lecture ratée sans cache ⇒ on ne masque rien (P4-20). Déclaré ICI (et
  // non plus après les slots) : les réservations affichées sur un FAILED suivent le même
  // filtre — le backend les écarte aussi du payload d'une période (`reservationsInScope`).
  const venueOverrides = useVenuePeriodOverrides(slotLayerId);
  const disabledVenueIds = useMemo(
    () => new Set(readFailed(venueOverrides) || readLoading(venueOverrides) ? [] : (venueOverrides.data ?? []).filter((o) => "DISABLED" === o.mode).map((o) => o.venueId)),
    [venueOverrides],
  );
  const reservationsQuery = useReservations(slotLayerId, isFailed);
  const reservationSlots = useMemo<Slot[]>(
    () => !isFailed || null === validScheduleId ? [] : (reservationsQuery.data ?? []).filter((r) => !disabledVenueIds.has(r.venueId)).map((r) => ({
      id: `reservation-${r.id}`,
      scheduleId: validScheduleId,
      teamId: r.teamId,
      venueId: r.venueId,
      coachId: null,
      dayOfWeek: r.dayOfWeek,
      startTime: r.startTime,
      durationMinutes: r.durationMinutes,
      lockLevel: "HARD" as const,
      // Ces pseudo-créneaux SONT des réservations — l'origine du verrou est explicite.
      lockOrigin: "RESERVATION" as const,
    })),
    [isFailed, reservationsQuery.data, validScheduleId, disabledVenueIds],
  );
  const slots = useMemo(
    () => isFailed && 0 === generatedSlots.length ? reservationSlots : generatedSlots,
    [isFailed, generatedSlots, reservationSlots],
  );

  const diagnosticsQuery = useDiagnostics(validScheduleId);
  // `useMemo` et non `?? []` : le repli littéral fabriquait un tableau NEUF à chaque rendu,
  // ce qui invalidait le `useMemo` du filtrage en aval à chaque fois (avertissement lint).
  const allDiagnostics = useMemo(() => diagnosticsQuery.data ?? [], [diagnosticsQuery.data]);
  // « Pas encore lu » n'est pas « rien à signaler » : tant que la requête est en vol, le
  // panneau ne doit pas annoncer un planning propre (revue #350, doctrine `readState`).
  const diagnosticsPending = null !== validScheduleId && undefined === diagnosticsQuery.data;
  const { data: trainingSlots = [] } = useTrainingSlots(slotLayerId);
  const { data: teams = [] } = useTeams();
  const { data: venues = [] } = useVenues();
  const { data: coaches = [] } = useCoaches();
  const { data: tiers = [] } = usePriorityTiers();
  const { data: categories = [] } = useCategories();
  const { data: teamCoaches = [] } = useTeamCoaches();
  const { data: coachPlayers = [] } = useCoachPlayers();
  const { data: constraints = [] } = useConstraints();
  // Résolution tag→équipes (saison courante) : le wrap n'affiche une contrainte CLUB ciblant
  // un tag QUE sur les équipes taguées — miroir de l'éclatement `CLUB+targetTag` du backend
  // (ScheduleConstraintBuilder). Mêmes hooks que le wizard (ConstraintsStep), pas un second
  // chemin de données ; les assignations sont déjà filtrées à la saison côté serveur.
  const { data: teamTags = [] } = useWizardTeamTags();
  const { data: teamTagAssignments = [] } = useWizardTeamTagAssignments();
  const tagTeamIds = useMemo(() => buildTagTeamIds(teamTags, teamTagAssignments), [teamTags, teamTagAssignments]);

  const queryClient = useQueryClient();
  const navigate = useNavigate();
  const lockMutation = useLockSlot();
  const moveMutation = useMoveSlot();
  const regenerateMutation = useRegenerate();
  const regenerateOverlayMutation = useRegenerateOverlay();
  const validateMutation = useValidateSchedule();
  const reopenMutation = useReopenSchedule();
  const deleteMutation = useDeleteSchedule();
  const regenerateFromMutation = useRegenerateFromVersion();
  const [regenerateFromOpen, setRegenerateFromOpen] = useState(false);
  const renamePlanning = useRenamePlanning();
  const [editingPlanningName, setEditingPlanningName] = useState<string | null>(null);
  // Repli CONTEXTUEL (P4-40). En boucle de travail, replié par défaut : la grille prend
  // toute la largeur pour vérifier, une barre compacte rouvre l'aside — c'est la demande
  // utilisateur d'origine, inchangée. Au sortir d'une génération lancée DEPUIS LE WIZARD
  // (`embedded`), ouvert : « sinon on risque de ne pas le voir si on n'est pas familier
  // avec l'écran génération » (retour terrain). Les deux règles ne se contredisent pas —
  // la seconde nomme un contexte que la première n'avait pas distingué.
  const [diagnosticsCollapsed, setDiagnosticsCollapsed] = useState(true);
  const [validateOpen, setValidateOpen] = useState(false);
  // Reopening the baseline with period overlays → 409; confirm to delete them.
  const [reopenOverlayCount, setReopenOverlayCount] = useState<number | null>(null);

  // Validating a non-baseline version with overlays → 409 escalation (same
  // destructive idiom as reopen): confirm, then re-POST with the flag.
  const [validateOverlayCount, setValidateOverlayCount] = useState<number | null>(null);
  const validate = (confirmDeleteOverlays?: boolean) => {
    if (!validScheduleId) {
      return;
    }
    validateMutation.mutate(
      { id: validScheduleId, confirmDeleteOverlays },
      {
        onSuccess: () => {
          setValidateOverlayCount(null);
          setValidateOpen(false);
          // Validated → land on the (now read-only) planning view.
          navigate("/planning");
        },
        onError: (error) => {
          if (error instanceof OverlaysExistError) {
            setValidateOpen(false);
            setValidateOverlayCount(error.count);
          }
        },
      },
    );
  };

  const reopen = (confirmDeleteOverlays?: boolean) => {
    if (!validScheduleId) {
      return;
    }
    reopenMutation.mutate(
      { id: validScheduleId, confirmDeleteOverlays },
      {
        onSuccess: () => {
          setReopenOverlayCount(null);
          // Reopened to rework the plan → back to the wizard's generation step.
          useWizardStore.getState().jumpTo("generate");
          navigate("/wizard");
        },
        // Generic failures are toasted by the hook (unmount-safe); only the
        // 409 escalation is UI state handled here.
        onError: (error) => {
          if (error instanceof OverlaysExistError) {
            setReopenOverlayCount(error.count);
          }
        },
      },
    );
  };

  const selectedSchedule = displayed;
  // Suppression d'un planning SECONDAIRE (overlay) depuis l'en-tête (retour fondateur
  // 2026-07-19) : l'entrée de calendrier de son plan (jamais pour le socle SEASON).
  const { data: allSchedulePlans } = useSchedulePlans();
  const overlayDeleteEntryId =
    null !== selectedSchedule && !isSeasonPlanType(selectedSchedule.planType) && null !== selectedSchedule.schedulePlanId
      ? ((allSchedulePlans ?? []).find((p) => p.id === selectedSchedule.schedulePlanId)?.calendarEntryId ?? null)
      : null;
  // ADR-0002 inv. 12 : LE nom vit sur le PLAN, jamais sur la version. Tout ce que
  // l'en-tête montre ou modifie (titre, stylo, nom de fichier exporté, popup de
  // suppression) doit donc désigner le plan de la version AFFICHÉE — pas le plan de
  // saison. Il était codé en dur : renommer un planning de période renommait le
  // planning de la SAISON, et l'en-tête affichait son nom sur toutes les périodes.
  // `null` = plan pas encore résolu (collection en vol, ou plan absent) : l'appelant
  // dégrade, il ne devine pas.
  // Le club n'a AUCUNE version : on est dans le contexte SAISON par défaut, le plan de
  // saison reste le sujet de l'en-tête. Sans ce cas, un club qui n'a jamais généré perdait
  // le nom de son planning ET son stylo — il ne pouvait plus le nommer (revue #339 round 1).
  // ⚠ La condition porte sur « le club n'a aucune version » (`schedules.length`), PAS sur
  // « aucune version RÉSOLUE » : entre deux refetch, la sélection du store peut ne pas se
  // retrouver dans la liste, et un repli sur ce signal-là ré-armerait le plan de SAISON comme
  // cible du stylo alors que le gestionnaire est sur une période — le bug d'origine, de retour
  // par une porte transitoire (revue #339 round 2).
  // Entre deux refetch, la sélection du store peut ne plus être dans la liste (suppression
  // d'une version, sélection persistée d'une autre saison) : `selectedSchedule` est alors null
  // UNE passe de rendu, le temps que l'effet d'atterrissage rejoue. Plutôt que de laisser
  // l'en-tête retomber sur un générique — ou pire, sur le plan de SAISON alors qu'on regarde
  // une période —, on lit dès maintenant la version que cet effet va choisir : la MÊME
  // fonction, donc le même résultat, sans flash et sans deviner (revue #339 round 3).
  const headerSchedule = selectedSchedule ?? schedules.find((s) => s.id === pickLandingScheduleId(schedules)) ?? null;
  const displayedPlan: { id: string; name: string } | null =
    null === headerSchedule || isSeasonPlanType(headerSchedule.planType)
      ? (me?.seasonPlan ?? null)
      : ((allSchedulePlans ?? []).find((p) => p.id === headerSchedule.schedulePlanId) ?? null);
  // Le TITRE tolère un plan non encore résolu (collection des plans en vol) : la photo
  // `Schedule.name` porte le nom du plan à la création, donc un libellé juste dans l'immense
  // majorité des cas — bien mieux que le générique « Planning ». Le STYLO, lui, reste
  // conditionné au plan résolu : on ne propose pas un geste dont on n'a pas la cible.
  const displayedPlanName = displayedPlan?.name ?? headerSchedule?.name ?? null;
  const isGenerating = null !== selectedSchedule && IN_FLIGHT.includes(selectedSchedule.status);
  // Read-only = its plan points at it: this version IS the calendar in force.
  const isReadOnly = true === selectedSchedule?.isChosen;
  const regenerateDisabled =
    null !== selectedSchedule
    && isSeasonPlanType(selectedSchedule.planType)
    && selectedSchedule.snapshotHash === me?.seasonPlan?.currentStructureHash;
  // regenerateFromMutation.isPending: "Charger cette version" no longer creates a
  // PENDING schedule (nothing sets isGenerating), so its own restore must disable
  // the action here — else a second click double-runs the destructive restore.
  const actionBusy = validateMutation.isPending || reopenMutation.isPending || deleteMutation.isPending || regenerateFromMutation.isPending;
  const busy = lockMutation.isPending || moveMutation.isPending;
  const clubInitial = (me?.club?.name ?? "C").trim().charAt(0).toUpperCase();

  // When a running generation finishes, pull the fresh slots + diagnostics.
  const prevStatus = useRef<string | null>(null);
  useEffect(() => {
    const status = selectedSchedule?.status ?? null;
    if (null !== prevStatus.current && IN_FLIGHT.includes(prevStatus.current) && null !== status && !IN_FLIGHT.includes(status)) {
      void queryClient.invalidateQueries({ queryKey: ["slots", validScheduleId] });
      void queryClient.invalidateQueries({ queryKey: ["diagnostics", validScheduleId] });
    }
    prevStatus.current = status;
  }, [selectedSchedule?.status, validScheduleId, queryClient]);

  const selectedSlot = slots.find((s) => s.id === selectedSlotId) ?? null;

  // F1 (PR 2) — LE point d'entrée UNIQUE de la bascule de verrou, partagé par le panneau de
  // détail ET le cadenas de la grille : la règle RÉSERVATION (déverrouiller → confirmation)
  // s'écrit ainsi une seule fois. MANUAL/UNKNOWN et tout verrouillage mutent directement.
  const requestToggleLock = useCallback(
    (slotId: string) => {
      const slot = slots.find((s) => s.id === slotId);
      if (undefined === slot) {
        return;
      }
      const locked = "NONE" !== slot.lockLevel;
      if (locked && "RESERVATION" === slot.lockOrigin) {
        setPendingUnlockSlotId(slotId);
        return;
      }
      lockMutation.mutate({ id: slotId, lockLevel: locked ? "NONE" : "HARD" });
    },
    [slots, lockMutation],
  );

  // F2b — le retour du dernier déplacement, dérivé de la mutation (verdict moteur). Un refus
  // (422) arrive en MoveRejectedError avec ses motifs ; une génération en cours en
  // GenerationInProgressError ; toute autre erreur (moteur injoignable) → « error ».
  const moveReset = moveMutation.reset;
  const moveState: MoveFeedback = moveMutation.isPending
    ? { status: "pending" }
    : moveMutation.error instanceof MoveRejectedError
      ? { status: "rejected", violations: moveMutation.error.violations }
      : moveMutation.error instanceof GenerationInProgressError
        ? { status: "blocked" }
        : null !== moveMutation.error && undefined !== moveMutation.error
          ? { status: "error" }
          : { status: "idle" };

  // Changer de créneau sélectionné efface le verdict du précédent — sinon un refus resterait
  // affiché sous un autre créneau.
  useEffect(() => {
    moveReset();
  }, [selectedSlotId, moveReset]);

  // Un déplacement REFUSÉ surligne le créneau de l'équipe EN CONFLIT (le moteur l'a nommée) —
  // présentation pure, on retrouve juste où elle siège dans le cache affiché. Ajustement en
  // phase de rendu (le lint du dépôt interdit setState dans un effet), clé = l'instance
  // d'erreur : au reset (changement de créneau, nouvel essai), moveState quitte « rejected »
  // et le surlignage s'efface — sans jamais écraser un surlignage venu d'un diagnostic.
  const [rejectionHandled, setRejectionHandled] = useState<unknown>(null);
  if ("rejected" === moveState.status && moveMutation.error !== rejectionHandled) {
    setRejectionHandled(moveMutation.error);
    setHighlightSlotIds(violationHighlightSlotIds(moveState.violations, slots));
  } else if ("rejected" !== moveState.status && null !== rejectionHandled) {
    setRejectionHandled(null);
    setHighlightSlotIds(new Set());
  }

  const lookups: Lookups = useMemo(() => {
    // teamId → main coachId (the engine leaves slot.coachId empty).
    const teamCoach = new Map<string, string>();
    for (const link of teamCoaches) {
      if ("MAIN" === link.role && !teamCoach.has(link.teamId)) {
        teamCoach.set(link.teamId, link.coachId);
      }
    }
    // teamId → coachIds that are players of the team (coach view shows these too).
    const teamPlayerCoaches = new Map<string, string[]>();
    for (const link of coachPlayers) {
      if (link.isActive) {
        teamPlayerCoaches.set(link.teamId, [...(teamPlayerCoaches.get(link.teamId) ?? []), link.coachId]);
      }
    }
    // P2-17 — libellé de groupe d'une fenêtre (clé gymnase|jour|minute) : le front AFFICHE
    // le champ calculé par le backend, il ne le re-dérive pas. Vide/trim→ignoré.
    const groupLabels = new Map<string, string>();
    for (const ts of trainingSlots) {
      const label = (ts.groupLabel ?? "").trim();
      if ("" !== label) {
        groupLabels.set(slotGroupKey(ts.venueId, ts.dayOfWeek, ts.startTime), label);
      }
    }
    return {
      teams: new Map(teams.map((t) => [t.id, t])),
      venues: new Map(venues.map((v) => [v.id, v])),
      coaches: new Map(coaches.map((c) => [c.id, c])),
      teamCoach,
      teamPlayerCoaches,
      groupLabels,
    };
  }, [teams, venues, coaches, teamCoaches, coachPlayers, trainingSlots]);

  // Defined venue windows the solver left unfilled ("créneaux vides"). Injected
  // into the grid in the GYMNASE view only (they have no team/coach) so they
  // show as `vide` cells even without a click; also listed as warnings below.
  const layerSlots = useMemo(
    () => (0 === disabledVenueIds.size ? trainingSlots : trainingSlots.filter((ts) => !disabledVenueIds.has(ts.venueId))),
    [trainingSlots, disabledVenueIds],
  );
  // ⚠ On filtre ce qui est OFFERT (les fenêtres libres), JAMAIS ce qui EXISTE (les séances
  // placées) — revue #342 round 2. Le premier jet filtrait aussi les séances : l'écran
  // cessait alors de montrer des séances que l'EXPORT, rendu côté serveur par scheduleId,
  // contenait toujours — le PDF remis aux coachs et l'écran se contredisaient, et une
  // version entièrement placée dans un gymnase désactivé rendait une grille blanche sans
  // un mot. Une version est un FAIT DÉJÀ ARRIVÉ ; la composition de la période est un
  // réglage pour la PROCHAINE génération. Cacher le fait ne le supprime pas : on l'annonce
  // (`staleVenueSessions`) et on invite à régénérer.
  const emptySlots = useMemo(() => computeEmptySlots(layerSlots, slots, validScheduleId ?? ""), [layerSlots, slots, validScheduleId]);
  const gridSlots = useMemo(() => ("gymnase" === viewMode ? [...slots, ...emptySlots] : slots), [viewMode, slots, emptySlots]);
  // Les séances de cette version que la période ne servirait plus : elles restent à
  // l'écran (et dans l'export), mais le gestionnaire doit savoir qu'elles sont périmées.
  // Sur les créneaux GÉNÉRÉS uniquement : les pseudo-réservations d'un FAILED ne sont
  // pas des « séances de ce planning » (et celles d'un gymnase désactivé sont déjà
  // filtrées à la source, comme le fait le payload).
  const staleVenueSessions = useMemo(
    () => (0 === disabledVenueIds.size ? 0 : generatedSlots.filter((s) => disabledVenueIds.has(s.venueId)).length),
    [generatedSlots, disabledVenueIds],
  );

  // From gridSlots (incl. empty windows in gymnase view) so a venue that has ONLY
  // empty slots still appears in the ResourceFilter picker — otherwise focusVenue
  // could filter to a venue the picker cannot show/clear.
  const resourceGroups = useMemo(() => availableResourceGroups(gridSlots, viewMode, lookups, tiers), [gridSlots, viewMode, lookups, tiers]);
  const model = useMemo(() => buildGrid(gridSlots, viewMode, lookups, new Set(resourceFilter)), [gridSlots, viewMode, lookups, resourceFilter]);

  // Un diagnostic qui NOMME une colonne absente de l'écran proposerait un focus vers rien —
  // un clic qui vide la grille (revue #342). Seul sort le diagnostic d'un gymnase désactivé
  // dont il ne reste AUCUNE séance : s'il en porte encore, sa colonne existe et son
  // diagnostic reste actionnable.
  const hiddenVenueIds = useMemo(() => {
    if (0 === disabledVenueIds.size) {
      return disabledVenueIds;
    }
    const placed = new Set(slots.map((s) => s.venueId));

    return new Set([...disabledVenueIds].filter((id) => !placed.has(id)));
  }, [disabledVenueIds, slots]);
  const diagnostics = useMemo(
    () => (0 === hiddenVenueIds.size ? allDiagnostics : allDiagnostics.filter((d) => null === d.venueId || !hiddenVenueIds.has(d.venueId))),
    [allDiagnostics, hiddenVenueIds],
  );

  // P4-40 — l'aside s'ouvre au sortir d'une génération lancée DEPUIS LE WIZARD, mais
  // seulement s'il a quelque chose à montrer.
  //
  // ⚠ Deux raisons d'attendre les diagnostics plutôt que d'initialiser à `!embedded`
  // (revue #350) : (1) au premier rendu ils ne sont pas encore là, donc l'aside s'ouvrait
  // TOUJOURS — y compris sur une génération propre, où il volait 20rem de largeur à la
  // grille dans une hauteur embarquée déjà courte pour n'afficher que « le planning est
  // propre » ; (2) refermer l'aside est un geste, pas un accident.
  //
  // ⚠ L'amorce est indexée sur la VERSION affichée, pas sur un booléen « déjà fait »
  // (revue #350 round 2) : le premier jet gardait ici le verrou à un coup que le correctif
  // du panneau venait pourtant de condamner un cran plus bas. Conséquence, après UN repli
  // manuel aucune version suivante ne rouvrait l'aside — les erreurs d'une V2 restaient
  // derrière la barre compacte, « on risque de ne pas le voir » de nouveau. Les deux
  // moitiés de la même règle se déclenchent donc sur le même signal.
  const [asideSeededFor, setAsideSeededFor] = useState<string | null>(null);
  if (embedded && !diagnosticsPending && null !== validScheduleId && asideSeededFor !== validScheduleId) {
    setAsideSeededFor(validScheduleId);
    // Les DEUX sens, une fois les diagnostics lus : une version qui en porte ouvre l'aside,
    // une version propre le referme. Ne traiter que l'ouverture laissait 20rem occupés par
    // « le planning est propre » après un passage d'une version bavarde à une version
    // saine — dans une hauteur embarquée déjà courte (revue #350 round 2).
    setDiagnosticsCollapsed(0 === diagnostics.length);
  }

  // Clicking the solver's "unused_slot" warning brings its venue column on screen
  // (venue view, filtered to that venue) so the concerned `vide` cell is visible.
  const focusVenue = useCallback(
    (venueId: string) => {
      setViewMode("gymnase");
      clearResourceFilter();
      toggleResource(venueId);
    },
    [setViewMode, clearResourceFilter, toggleResource],
  );

  // Cliquer un diagnostic `conflict` ouvre LE créneau fautif (SlotDetail) et l'amène à l'écran.
  // rAF + appels optionnels (précédent ConstraintsStep) : on laisse React peindre le créneau
  // sélectionné avant de scroller, et `scrollIntoView` n'existe pas en jsdom.
  const openSlot = useCallback(
    (slotId: string) => {
      setSelectedSlotId(slotId);
      requestAnimationFrame(() => document.querySelector(`[data-slot-id="${slotId}"]`)?.scrollIntoView?.({ block: "center", inline: "center", behavior: "smooth" }));
    },
    [setSelectedSlotId],
  );

  // Le chemin SURLIGNAGE (tous les autres types de diagnostic) n'amenait PAS la grille au
  // créneau : « ça illumine mais je dois chercher pour le trouver » (retour fondateur
  // 2026-08-15). Même recette que openSlot — le PREMIER créneau surligné est centré ; un
  // clic qui ÉTEINT le surlignage (set vide) ne scrolle pas.
  const highlightSlots = useCallback((slotIds: Set<string>) => {
    setHighlightSlotIds(slotIds);
    const [first] = slotIds;
    if (undefined !== first) {
      requestAnimationFrame(() => document.querySelector(`[data-slot-id="${first}"]`)?.scrollIntoView?.({ block: "center", inline: "center", behavior: "smooth" }));
    }
  }, []);

  const selectedCell = model.cells.find((c) => c.slotId === selectedSlotId) ?? null;

  // Sélectionner un créneau REPLIE les diagnostics (retour fondateur : « réduire
  // automatiquement le panel de diagnostique, sinon c'est impossible de le relancer »). Repli,
  // et non masquage : la décision d'hier (masquer sauf s'il restait une ERROR) enterrait la
  // place ET l'accès au panneau. Replié, la barre garde le compte + la sévérité max VISIBLES et
  // rouvre d'un clic ; rien n'est enterré, une ERREUR reste signalée — d'où le retrait de
  // l'exception ERROR (un cas particulier de moins). À la FERMETURE du créneau, on restaure
  // l'état d'avant la sélection.
  //
  // Ajustement en phase de rendu (le lint du dépôt interdit `setState` dans un effet), clé = la
  // transition de sélection, à l'image de `asideSeededFor` plus haut.
  const slotSelected = null !== selectedCell && null !== selectedSlot;
  const activeSlotId = slotSelected ? selectedSlotId : null;
  const [slotCollapse, setSlotCollapse] = useState<{ slotId: string | null; restoreExpanded: boolean }>({ slotId: null, restoreExpanded: false });
  if (slotCollapse.slotId !== activeSlotId) {
    if (null !== activeSlotId && null === slotCollapse.slotId) {
      // Ouverture d'un créneau (depuis aucun) : mémoriser l'expansion courante, puis replier.
      setSlotCollapse({ slotId: activeSlotId, restoreExpanded: !diagnosticsCollapsed });
      setDiagnosticsCollapsed(true);
    } else if (null === activeSlotId && null !== slotCollapse.slotId) {
      // Fermeture du créneau : restaurer l'état d'avant (ne ré-ouvrir que si c'était ouvert).
      if (slotCollapse.restoreExpanded) {
        setDiagnosticsCollapsed(false);
      }
      setSlotCollapse({ slotId: null, restoreExpanded: false });
    } else {
      // Passage d'un créneau à un autre : garder le repli, suivre juste l'id.
      setSlotCollapse((prev) => ({ ...prev, slotId: activeSlotId }));
    }
  }

  const categoryLabel = useMemo(() => {
    if (null === selectedCell) {
      return "—";
    }
    const slot = slots.find((s) => s.id === selectedCell.slotId);
    const team = slot ? lookups.teams.get(slot.teamId) : undefined;
    const category = team ? categories.find((c) => c.id === team.sportCategoryId) : undefined;
    return category?.name ?? "—";
  }, [selectedCell, slots, lookups, categories]);

  if (schedulesLoading) {
    return <FullPageSpinner />;
  }

  const planningTitle = displayedPlanName ?? "Planning";
  // Nom du fichier exporté = nom du PLAN affiché (retour fondateur 2026-07-18).
  // Il lisait `selectedSchedule.name`, c'est-à-dire le nom de la VERSION — que les
  // clients inventaient : le fichier remis aux coachs s'appelait « Version de période ».
  // Repli sur le nom de la version si le plan n'est pas encore résolu : un fichier au
  // nom imparfait vaut mieux qu'un « planning.xlsx » anonyme (revue #339).
  const exportName = displayedPlanName;
  const structureDiverged =
    null !== selectedSchedule && isSeasonPlanType(selectedSchedule.planType)
    && typeof selectedSchedule.generatedTeamCount === "number" && teams.length > 0
    && selectedSchedule.generatedTeamCount !== teams.length;

  return (
    <div>
      <div className="mb-4 flex items-center gap-3">
        {me?.club?.logoUrl ? <img src={me.club.logoUrl} alt="" className="size-8 shrink-0 rounded object-contain" /> : null}
        {null !== editingPlanningName ? (
          <input
            // eslint-disable-next-line jsx-a11y/no-autofocus -- inline rename field revealed on demand
            autoFocus
            aria-label="Nom du planning"
            value={editingPlanningName}
            onChange={(e) => setEditingPlanningName(e.target.value)}
            onKeyDown={(e) => {
              if ("Enter" === e.key) {
                // Le plan AFFICHÉ (cf. displayedPlan) : c'était `me.seasonPlan.id` en dur,
                // donc renommer un planning de période renommait celui de la saison.
                // Un nom vidé n'écrase rien — un plan sans nom n'a plus d'identité à lire.
                if (null !== displayedPlan && "" !== editingPlanningName.trim()) {
                  renamePlanning.mutate({ planId: displayedPlan.id, name: editingPlanningName.trim() });
                }
                setEditingPlanningName(null);
              } else if ("Escape" === e.key) {
                setEditingPlanningName(null);
              }
            }}
            onBlur={() => setEditingPlanningName(null)}
            className="h-9 rounded-md border border-input bg-background px-3 text-xl font-semibold"
          />
        ) : (
          <>
            {/* ADR-0002 inv. 12: THE plan's name lives here, on the plan — not in the version selector. */}
            <h1 className="border-l-[3px] border-accent pl-3 text-2xl font-semibold">{planningTitle}</h1>
            {/* « principal » qualifie LE planning de la saison (le plan SEASON), par
                opposition aux plannings secondaires de période — pas la version choisie. */}
            {null !== selectedSchedule && isSeasonPlanType(selectedSchedule.planType) ? (
              <span className="flex items-center gap-1 rounded-full bg-accent px-2 py-0.5 text-xs font-medium text-accent-foreground">
                <Star className="size-3" />
                principal
              </span>
            ) : null}
            {/* Pas de plan résolu = rien à renommer : proposer le geste enverrait
                l'écriture sur un id qu'on n'a pas (c'est ce qui la faisait retomber
                sur le plan de saison). */}
            {null !== displayedPlan && workingSeason && !workingSeason.isReadonly ? (
              <Button size="sm" variant="ghost" className="h-8 px-2" aria-label="Renommer le planning" title="Renommer le planning" onClick={() => setEditingPlanningName(displayedPlan.name)}>
                <Pencil className="size-4" />
              </Button>
            ) : null}
            {/* Supprimer : plannings SECONDAIRES uniquement (jamais le socle), et
                jamais pendant une génération en vol (la cascade emporterait la version
                en cours de solve — revue B2 F3) → retour cockpit. */}
            {null !== overlayDeleteEntryId && workingSeason && !workingSeason.isReadonly && !isGenerating ? (
              <DeletePlanningButton calendarEntryId={overlayDeleteEntryId} schedulePlanId={selectedSchedule?.schedulePlanId ?? null} title={displayedPlanName ?? "ce planning"} onDeleted={() => navigate("/")} iconOnly />
            ) : null}
          </>
        )}
        {/* P5-6 — porte contextuelle : joint le planning affiché au signalement. */}
        <FeedbackButton className="ml-auto" screen="/planning" scheduleId={validScheduleId} />
      </div>

      {/* Planning PÉRIMÉ (pas faux) : retouché à la main (F2b), une contrainte a changé (F2c),
          une DONNÉE DU CLUB a changé (P4-87), ou des équipes ont été ajoutées/retirées
          (structureDiverged, fusionné ICI plutôt qu'en bandeau séparé). UNE seule bannière qui
          nomme sa/ses cause(s) ; sur un planning validé (lecture seule) elle propose « rouvrir
          puis régénérer », jamais un geste qui finirait en 409. Voir lib/staleness. */}
      {(() => {
        const stale = isGenerating || null === selectedSchedule
          ? null
          : stalenessMessage({
            manuallyEdited: true === selectedSchedule.manuallyEditedSinceGeneration,
            constraintsChanged: true === selectedSchedule.constraintsChangedSinceGeneration,
            resourcesChanged: true === selectedSchedule.resourcesChangedSinceGeneration,
            structureDiverged,
            readOnly: isReadOnly,
          });
        return null === stale ? null : (
          <p className="mb-4 rounded-md border border-warning/40 bg-warning/10 px-3 py-2 text-sm text-foreground">
            {stale}
          </p>
        );
      })()}


      {0 === schedules.length ? (
        <EmptyState title="Aucun planning" description="Passez par l'assistant pour saisir vos données et générer un premier planning." />
      ) : (
        <>
          <div className="mb-4">
            <PlanningToolbar
              schedules={schedules}
              selectedScheduleId={validScheduleId}
              onSelectSchedule={setSelectedScheduleId}
              viewMode={viewMode}
              onViewMode={setViewMode}
              isGenerating={isGenerating || regenerateMutation.isPending || regenerateOverlayMutation.isPending}
              actionBusy={actionBusy}
              disableRegenerate={regenerateDisabled}
              outputCredits={null === credits ? null : { count: credits.remaining, blocked: !credits.canGenerate }}
              onRegenerate={() => {
                if (null === validScheduleId) {
                  return;
                }
                const select = { onSuccess: (created: { id: string }) => setSelectedScheduleId(created.id) };
                // An overlay "Régénérer" creates a NEW version UNDER its period's plan
                // (ADR-0002 C4); a season plan regenerates from the current structure.
                const overlayPlanId = !isSeasonPlanType(selectedSchedule?.planType) ? selectedSchedule?.schedulePlanId ?? null : null;
                if (null !== overlayPlanId) {
                  regenerateOverlayMutation.mutate(overlayPlanId, select);
                } else {
                  regenerateMutation.mutate(validScheduleId, select);
                }
              }}
              onValidate={() => setValidateOpen(true)}
              onReopen={() => reopen()}
              onDelete={() => validScheduleId && deleteMutation.mutate(validScheduleId)}
              onRegenerateFrom={() => setRegenerateFromOpen(true)}
              embedded={embedded}
              rightSlot={
                null !== validScheduleId && !isGenerating && slots.length > 0 ? (
                  <ExportMenu
                    scheduleId={validScheduleId}
                    venues={venues}
                    exportName={exportName}
                    screenFilterCount={resourceFilter.length}
                  />
                ) : null
              }
              // Le filtre part en ligne 1, contre le sélecteur de vue dont il porte le
              // libellé (P4-43). ⚠ Il n'est toujours PAS couplé à l'export (le rendu PDF
              // est serveur et ignore tout filtre client) — mais depuis P4-62 l'export
              // ANNONCE son périmètre quand l'écran est filtré : on ne masque jamais ce
              // qu'un export contient.
              filterSlot={<ResourceFilter viewMode={viewMode} groups={resourceGroups} selected={resourceFilter} onToggle={toggleResource} onClear={clearResourceFilter} />}
            />
          </div>

          {/* Ce planning a été généré quand un gymnase servait encore la période : ses
              séances restent affichées ET exportées, mais elles ne décrivent plus la
              période telle qu'elle est réglée. On le dit plutôt que de les escamoter. */}
          {!isGenerating && staleVenueSessions > 0 ? (
            <p className="mb-3 rounded-md border border-warning/40 bg-warning/10 px-3 py-2 text-sm text-foreground">
              {staleVenueSessions} séance(s) de ce planning sont placées dans un gymnase désactivé depuis pour cette période — régénérez-la pour qu'elles en sortent.
            </p>
          ) : null}

          {/* Génération en échec : la grille ne montre que les RÉSERVATIONS (pseudo-créneaux
              lecture seule) — on le dit, sinon elles passeraient pour un planning généré.
              Et l'export ne les contient pas : il rend les créneaux du serveur (§7.2 pt 3). */}
          {!isGenerating && isFailed && slots.length > 0 && 0 === generatedSlots.length ? (
            <p className="mb-3 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-foreground">
              La génération a échoué : aucun créneau n'a été placé. Seuls vos créneaux réservés sont affichés — ils restent acquis quoi qu'il arrive. Les exports sont vides pour ce planning.
            </p>
          ) : null}

          {isGenerating ? (
            <GenerationWaiting initial={clubInitial} logoUrl={me?.club?.logoUrl ?? null} />
          ) : 0 === slots.length ? (
            isFailed ? (
              <EmptyState title="Génération en échec" description="Aucun créneau n'a été placé, et ce planning n'a aucune réservation à afficher. Corrigez les contraintes signalées puis régénérez." />
            ) : (
              <EmptyState title="Planning vide" description="Ce planning ne contient aucun créneau placé pour le moment." />
            )
          ) : (
            // grid-rows-[minmax(0,1fr)] gives the single row a DEFINITE size (the
            // container height) — with the default `auto` row the children's h-full
            // cannot resolve, the WeekGrid lays out at full content height and
            // overflows the page instead of scrolling internally.
            //
            // The right column only exists when there is something to show: the
            // slot-detail panel (opened on click) or, for an editable planning,
            // the diagnostics. In read-only consultation with no slot selected the
            // grid takes the full width; closing the panel returns to full width.
            (() => {
              const showDetail = null !== selectedCell && null !== selectedSlot;
              // The diagnostics aside only claims grid width when it has content
              // to show: a selected slot's detail, or the (expanded) diagnostics.
              // ⚠ « Déplié » est un état de l'ASIDE, pas une promesse de contenu : c'est
              // l'amorce ci-dessus qui replie l'aside sur une version sans diagnostic. Le
              // rouvrir à la main reste possible et respecté, y compris à vide. Sélectionner
              // un créneau replie les diagnostics (cf. `slotCollapse`) : ils cohabitent alors
              // avec le détail dans l'aside dès qu'on les rouvre — chacun borné à la grille.
              const showDiagnostics = !isReadOnly && !diagnosticsCollapsed;
              const showAside = showDetail || showDiagnostics;
              // Barre repliée : le compte TOTAL + la sévérité la plus haute restent lisibles —
              // replier ne doit rien enterrer (« Diagnostics (6) · 2 erreurs »).
              const topSummary = topSeveritySummary(diagnostics);
              const height = embedded ? "lg:h-[max(calc(100vh-24rem),26rem)]" : "lg:h-[calc(100vh-16rem)]";
              return (
                <div className={`${showAside ? "lg:grid lg:grid-cols-[minmax(0,1fr)_20rem] lg:grid-rows-[minmax(0,1fr)] lg:gap-4" : ""} ${height}`}>
                  {/* min-h-0 is essential: without it the flex-1 grid wrapper keeps
                      its content height and overflows past the container, spilling
                      under the sticky footer (revue #204 — grille coupée en 2). */}
                  <div className="relative flex min-h-0 min-w-0 flex-col gap-2 lg:h-full">
                    {/* Collapsed diagnostics → a compact bar re-opens the aside;
                        the grid keeps full width until then (user request). */}
                    {!isReadOnly && diagnosticsCollapsed ? (
                      <button
                        type="button"
                        onClick={() => setDiagnosticsCollapsed(false)}
                        className="flex shrink-0 items-center gap-2 self-start rounded-md border border-border px-2 py-1 text-sm hover:bg-muted"
                      >
                        <AlertTriangle className={`size-4 ${diagnostics.length > 0 ? "text-warning" : "text-muted-foreground"}`} />
                        <span>Diagnostics du système{diagnostics.length > 0 ? ` (${diagnostics.length})` : ""}</span>
                        {null !== topSummary ? <span className="rounded-full bg-muted px-1.5 text-xs text-muted-foreground">{topSummary}</span> : null}
                      </button>
                    ) : null}
                    <div className="relative min-h-0 min-w-0 flex-1">
                      <WeekGrid
                        model={model}
                        selectedSlotId={selectedSlotId}
                        onSelectSlot={setSelectedSlotId}
                        highlightSlotIds={highlightSlotIds}
                        // Lecture seule (validé) ou FAILED (pseudo-créneaux sans existence
                        // serveur) → pas de bascule : le cadenas reste indicateur passif.
                        onToggleLock={isReadOnly || isFailed ? undefined : requestToggleLock}
                      />
                    </div>
                  </div>
                  {showAside ? (
                    <div className="mt-4 flex min-h-0 flex-col gap-4 lg:mt-0 lg:h-full">
                      {null !== selectedCell && null !== selectedSlot ? (
                        <SlotDetail
                          key={selectedSlot.id}
                          cell={selectedCell}
                          slot={selectedSlot}
                          venues={venues}
                          categoryLabel={categoryLabel}
                          constraints={constraints}
                          tagTeamIds={tagTeamIds}
                          // La description d'une contrainte NOMME sa cible : on passe les
                          // résolveurs de nom équipe/coach depuis les lookups déjà en main.
                          teamName={(id) => lookups.teams.get(id)?.name}
                          coachName={(id) => {
                            const c = lookups.coaches.get(id);
                            return undefined === c ? undefined : coachFullName(c);
                          }}
                          busy={busy}
                          moveState={moveState}
                          // Un pseudo-créneau de réservation (planning FAILED) n'existe pas
                          // côté serveur : déplacer/verrouiller le viserait dans le vide.
                          readOnly={isReadOnly || isFailed}
                          onClose={() => setSelectedSlotId(null)}
                          // Même point d'entrée que le cadenas de la grille : la règle
                          // RÉSERVATION (confirmation) vit dans `requestToggleLock`, pas ici.
                          onToggleLock={() => requestToggleLock(selectedSlot.id)}
                          onMove={(patch) => moveMutation.mutate({ id: selectedSlot.id, patch })}
                        />
                      ) : null}
                      {showDiagnostics ? (
                        <div className="min-h-[12rem] flex-1">
                          <DiagnosticsPanel diagnostics={diagnostics} slots={slots} emptySlots={emptySlots} lookups={lookups} onHighlight={highlightSlots} onFocusVenue={focusVenue} onOpenSlot={openSlot} onCollapse={() => setDiagnosticsCollapsed(true)} openMostSevere={embedded} seedToken={validScheduleId} pending={diagnosticsPending} />
                        </div>
                      ) : null}
                    </div>
                  ) : null}
                </div>
              );
            })()
          )}
        </>
      )}

      {validateOpen ? (
        <ValidateDialog
          hasAlerts={diagnostics.length > 0}
          siblingCount={selectedSchedule?.capabilities?.versionsDeletedOnValidate ?? 0}
          busy={validateMutation.isPending}
          onCancel={() => setValidateOpen(false)}
          onConfirm={() => validate()}
        />
      ) : null}

      <ConfirmDialog
        open={reopenOverlayCount !== null}
        destructive
        title="Rouvrir le planning principal ?"
        description={`Rouvrir ce planning principal supprimera ${reopenOverlayCount ?? 0} planning${(reopenOverlayCount ?? 0) > 1 ? "s" : ""} secondaire${(reopenOverlayCount ?? 0) > 1 ? "s" : ""} (à refaire ensuite).`}
        confirmLabel="Rouvrir et supprimer"
        confirmPhrase="modifier mon planning de saison"
        onConfirm={() => reopen(true)}
        onCancel={() => setReopenOverlayCount(null)}
      />

      <ConfirmDialog
        open={null !== pendingUnlockSlotId}
        title="Déverrouiller ce créneau réservé ?"
        description="Ce créneau vient d'une réservation de gymnase. En le déverrouillant, la prochaine génération pourra le déplacer ou le libérer — vérifiez auprès du gymnase avant de continuer."
        confirmLabel="Déverrouiller"
        onConfirm={() => {
          if (null !== pendingUnlockSlotId) {
            lockMutation.mutate({ id: pendingUnlockSlotId, lockLevel: "NONE" });
          }
          setPendingUnlockSlotId(null);
        }}
        onCancel={() => setPendingUnlockSlotId(null)}
      />

      <ConfirmDialog
        open={validateOverlayCount !== null}
        title="Valider cette version et remplacer le planning principal ?"
        description={`Cette version deviendra le planning principal ; ${validateOverlayCount ?? 0} planning${(validateOverlayCount ?? 0) > 1 ? "s" : ""} de période bâti${(validateOverlayCount ?? 0) > 1 ? "s" : ""} sur l'ancien principal ser${(validateOverlayCount ?? 0) > 1 ? "ont" : "a"} supprimé${(validateOverlayCount ?? 0) > 1 ? "s" : ""} (à refaire ensuite).`}
        confirmLabel="Valider et remplacer"
        destructive
        onConfirm={() => validate(true)}
        onCancel={() => setValidateOverlayCount(null)}
      />

      <ConfirmDialog
        open={regenerateFromOpen}
        title="Charger cette version ?"
        description={
          "number" === typeof selectedSchedule?.generatedTeamCount ? (
            <>
              La structure actuelle ({teams.length} équipe{teams.length > 1 ? "s" : ""}) sera remplacée par celle de cette version ({selectedSchedule.generatedTeamCount} équipe{selectedSchedule.generatedTeamCount > 1 ? "s" : ""}) et son planning s'affichera. Les données de structure actuelles seront écrasées ; vous pourrez ensuite « Régénérer » pour créer une nouvelle version.
            </>
          ) : null
        }
        confirmLabel="Charger"
        destructive
        onConfirm={() => {
          if (null !== validScheduleId) {
            regenerateFromMutation.mutate(validScheduleId, { onSuccess: (created) => setSelectedScheduleId(created.id) });
          }
          setRegenerateFromOpen(false);
        }}
        onCancel={() => setRegenerateFromOpen(false)}
      />
    </div>
  );
}
