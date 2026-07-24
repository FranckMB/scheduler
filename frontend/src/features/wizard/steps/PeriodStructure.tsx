import { Loader2, Trash2 } from "lucide-react";
import { useEffect, useMemo, useState } from "react";

import { useCalendarEntry, useEntryConflicts, usePeriodAnchor, useSchedulePlanForEntry } from "@/features/cockpit/queries";
import { AccordionSection } from "@/shared/components/ui/accordion";
import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { VenueSwatch } from "@/shared/components/ui/venue-swatch";
import { groupTeamsByTier, tierGroupLabel } from "@/shared/lib/teamTiers";
import { cn } from "@/shared/lib/utils";
import { toast } from "@/shared/stores/toastStore";

import type { Constraint, ConstraintRuleType, Team, TeamPeriodOverride, Venue, VenuePeriodOverride, VenueTrainingSlot } from "../api";
import { findSlotConflict } from "../lib/slotOverlap";
import {
  useCreatePeriodConstraintOverride,
  useClearVenuePeriodMode,
  useCreatePeriodSlot,
  useCreateTeamPeriodOverride,
  useDeletePeriodConstraintOverride,
  useDeletePeriodSlot,
  useDeleteTeamPeriodOverride,
  usePeriodConstraintOverrides,
  usePeriodSlots,
  usePriorityTiers,
  useReservations,
  useResetVenuePeriodGrid,
  useSetVenuePeriodMode,
  useVenuePeriodOverrides,
  useWizardTeamTagAssignments,
  useWizardTeamTags,
  useTeamPeriodOverrides,
  useUpdatePeriodConstraintOverride,
  useUpdateTeamPeriodOverride,
  useWizardConstraints,
  useWizardTeams,
  useWizardVenues,
} from "../queries";
import { SectionCountTitle } from "./StructureSummary";
import { VenueAvailabilityGrid } from "./VenueAvailabilityGrid";
import { claimPeriodSeed, periodSeedWasClaimed } from "./periodSeed";

const fieldClass = "h-8 rounded-md border border-input bg-background px-2 text-sm";

// In-session guard against re-seeding between firing the Fanion-only seed and the
// plan query reflecting teamSelectionInitialized (the DURABLE, reload-proof signal).
// Module-level so it survives a step remount (WizardLayout unmounts inactive steps).
// Claimed ONCE and never un-claimed (un-claiming on a partial failure would re-run the
// seed against a still-empty cache and double-write): a failed seed is best-effort, the
// manager completes it with the "Fanion seul" ramp.
/**
 * Period-editable teams (F1): the club roster is READ-ONLY (grouped by rank), but
 * each team can be toggled off for the period and given a period-specific number
 * of sessions. A sparse TeamPeriodOverride backs each change; the base plan and
 * the Team's seasonal fields are never touched. Default on a fresh period: only
 * the top tier (Fanion) trains — the resumption ramp starts there.
 */
export function PeriodTeams({ calendarEntryId }: { calendarEntryId: string }) {
  const { data: teams = [] } = useWizardTeams();
  const { data: tiers = [] } = usePriorityTiers();
  // Le TYPE de période gouverne le défaut d'équipes (E3) : reprise = Fanion + importantes,
  // fermeture = tout le club (structure verrouillée). Lu comme PeriodConstraints le fait.
  const { data: entry } = useCalendarEntry(calendarEntryId);
  const isClosure = "closure" === entry?.periodType;
  // Le PLAN entier est nécessaire ici (le garde de seed lit teamSelectionInitialized) ;
  // `usePeriodAnchor` fournit l'ancre ET son état — ne pas re-dériver un `?? null` nu.
  const { data: plan, isLoading: planLoading } = useSchedulePlanForEntry(calendarEntryId);
  const { planId: schedulePlanId, ready: anchorReady } = usePeriodAnchor(calendarEntryId);
  const { data: overrides = [], isLoading } = useTeamPeriodOverrides(schedulePlanId);
  const create = useCreateTeamPeriodOverride(schedulePlanId);
  const update = useUpdateTeamPeriodOverride(schedulePlanId);
  const del = useDeleteTeamPeriodOverride(schedulePlanId);
  const [busy, setBusy] = useState(false);

  const groups = groupTeamsByTier(teams, tiers);
  const topTierId = groups[0]?.tier?.id ?? null;
  const overrideOf = new Map<string, TeamPeriodOverride>(overrides.map((o) => [o.teamId, o]));

  const isActive = (t: Team): boolean => overrideOf.get(t.id)?.isActive ?? true;
  const sessionsOf = (t: Team): number => overrideOf.get(t.id)?.sessionsPerWeek ?? t.sessionsPerWeek;

  // Upsert the override, or DELETE it when the state is back to the seasonal default
  // (active + seasonal session count) — keeps the table sparse. Returns the mutation
  // promise so batch actions (seed / ramp) can await the whole set.
  const upsertAsync = (t: Team, next: { isActive: boolean; sessions: number | null }): Promise<unknown> => {
    const existing = overrideOf.get(t.id);
    const backToSeasonal = next.isActive && (null === next.sessions || next.sessions === t.sessionsPerWeek);
    if (backToSeasonal) {
      return existing ? del.mutateAsync(existing.id) : Promise.resolve();
    }
    if (null === schedulePlanId) {
      return Promise.resolve(); // plan pas encore chargé : pas d'ancre, pas d'écriture
    }
    const body = { schedulePlanId, teamId: t.id, isActive: next.isActive, sessionsPerWeek: next.sessions };
    return existing ? update.mutateAsync({ id: existing.id, body }) : create.mutateAsync(body);
  };
  // Fire-and-forget for single edits (checkbox/session). Swallow the rejection: the
  // global MutationCache already toasts it — this only avoids an unhandledrejection.
  const upsert = (t: Team, next: { isActive: boolean; sessions: number | null }) => void upsertAsync(t, next).catch(() => {});

  // Default team selection on a FRESH period (no overrides yet, not already seeded this
  // session), ONCE — best-effort. The DEPTH depends on the period type (E3) :
  //  - reprise (holiday) : Fanion + importantes reprennent (tiers S+A) ; le reste démarre
  //    en pause, coché au fil de la montée en charge.
  //  - fermeture (closure) : structure verrouillée → tout le club reste actif, rien n'est
  //    désactivé d'office (le gestionnaire décoche à la main une équipe loisir au besoin).
  // Controls stay disabled (busy) until the async writes settle so a first click can't
  // race them. The key is claimed BEFORE firing and never un-claimed: un-claiming on a
  // partial failure re-runs the effect against a still-empty override cache and
  // double-writes the teams that already succeeded. On a failure the global toast
  // informs; the manager applies a ramp preset to complete.
  //
  // The DURABLE signal is plan.teamSelectionInitialized (server-set on the first
  // override, survives reload); seededPeriods only guards the in-session window
  // between firing the seed and the plan query reflecting the flag. ADR-0002 lot C:
  // the flag lives on the plan — the RESPONSE — not on the calendar event.
  useEffect(() => {
    // `false !== plan?.teamSelectionInitialized` bails on undefined AND on null:
    // seed ONLY when the plan loaded and says explicitly "jamais configuré".
    //
    // Les cas de sortie sont voulus, et aucun n'est le cas nominal. `undefined` (plan OU
    // entry) = fetch en erreur/en vol : ne pas seeder une période inconnue (elle pourrait
    // être déjà configurée, son GET ayant simplement raté) — seeder à tort désactiverait
    // des équipes que le gestionnaire vient d'activer, et sans le type on ne connaît pas la
    // profondeur du seed. `null` (plan) = aucun plan : inatteignable pour une closure/holiday
    // depuis que le geste est atomique (l'entrée et son plan naissent ensemble, ou pas du
    // tout), et le mode période ne s'ouvre que sur ces deux types. Un null ici est donc une
    // anomalie, pas un état neuf : ne rien faire est le choix conservateur.
    if (isLoading || planLoading || undefined === entry || 0 === teams.length || overrides.length > 0 || null === topTierId || false !== plan?.teamSelectionInitialized || periodSeedWasClaimed(calendarEntryId)) {
      return;
    }
    claimPeriodSeed(calendarEntryId);
    // Fermeture (structure verrouillée) : tout le club reste actif → aucun retrait d'office.
    // Reprise : garder les 2 premiers RANGS (S+A) par RANG et non par occupation — un rang vide
    // (pas de Fanion) ne doit pas promouvoir un rang inférieur par un slice() positionnel des
    // groupes. Les rangs sont ordonnés par id (1=S…5=D). GARDE-FOU : un club SANS aucune équipe
    // en S/A (petit club tout en loisir) ne doit pas voir TOUT le monde désactivé — on retombe
    // alors sur le meilleur rang réellement présent (topTierId) : la reprise n'est jamais vide.
    const topRankIds = new Set([...tiers].sort((a, b) => a.id - b.id).slice(0, 2).map((t) => t.id));
    const keptByRank = teams.filter((t) => topRankIds.has(t.priorityTierId));
    const keepIds = new Set((keptByRank.length > 0 ? keptByRank : teams.filter((t) => t.priorityTierId === topTierId)).map((t) => t.id));
    const toDeactivate = isClosure ? [] : teams.filter((t) => !keepIds.has(t.id));
    if (0 === toDeactivate.length) {
      return;
    }
    // eslint-disable-next-line react-hooks/set-state-in-effect -- one-shot: gate the controls while the async default-selection writes settle
    setBusy(true);
    void Promise.allSettled(toDeactivate.map((t) => create.mutateAsync({ schedulePlanId: plan.id, teamId: t.id, isActive: false }))).finally(() => setBusy(false));
  }, [isLoading, planLoading, plan, entry, isClosure, teams, tiers, overrides, topTierId, calendarEntryId, create]);

  const toggle = (t: Team, value: boolean) => upsert(t, { isActive: value, sessions: overrideOf.get(t.id)?.sessionsPerWeek ?? null });
  const setSessions = (t: Team, raw: number) => {
    // Ignore an emptied / out-of-range field client-side (Number("") === 0; the field
    // caps at 7 but paste/typing can exceed it) rather than fire a doomed 422.
    if (!Number.isInteger(raw) || raw < 1 || raw > 7) {
      return;
    }
    upsert(t, { isActive: isActive(t), sessions: raw === t.sessionsPerWeek ? null : raw });
  };

  // Ramp presets: activate every team up to (and including) a tier group index —
  // awaited as a batch, controls disabled meanwhile.
  const rampTo = async (upToIndex: number) => {
    if (busy || !anchorReady) {
      return; // sans ancre, on n'écrit pas (et on ne prétend pas l'avoir fait)
    }
    setBusy(true);
    try {
      const results = await Promise.allSettled(groups.flatMap((g, i) => g.teams.map((t) => upsertAsync(t, { isActive: i <= upToIndex, sessions: overrideOf.get(t.id)?.sessionsPerWeek ?? null }))));
      // Only confirm when every write landed — failures are toasted by the global net.
      // `anchorReady` fait partie de la condition : sans ancre, upsertAsync bail en
      // Promise.resolve() pour chaque équipe → zéro rejet → on confirmait « Sélection
      // appliquée » alors qu'AUCUNE ligne n'était écrite, et l'overlay partait ensuite avec
      // tout le club actif. Un succès qui ment est pire qu'une erreur.
      if (anchorReady && !results.some((r) => "rejected" === r.status)) {
        toast.success("Sélection appliquée");
      }
    } finally {
      setBusy(false);
    }
  };

  if (0 === groups.length) {
    return <EmptyHint>Aucune équipe.</EmptyHint>;
  }

  return (
    <div className="space-y-3" aria-busy={busy}>
      <p className="text-sm text-muted-foreground">
        {isClosure ? (
          <>
            Tout le club reste actif sur cette période — <strong>décochez</strong> les équipes qui ne s'entraînent pas (ex. loisir).
          </>
        ) : (
          <>
            Choisissez qui reprend sur cette période. Par défaut le <strong>Fanion</strong> et les équipes <strong>importantes</strong> reprennent — cochez les autres au fur et à mesure de la montée en charge.
          </>
        )}
      </p>
      <div className="flex flex-wrap items-center gap-2">
        <Button size="sm" variant="outline" disabled={busy} onClick={() => void rampTo(0)}>
          Fanion seul
        </Button>
        {groups.length > 2 ? (
          <Button size="sm" variant="outline" disabled={busy} onClick={() => void rampTo(1)}>
            + importantes
          </Button>
        ) : null}
        <Button size="sm" variant="outline" disabled={busy} onClick={() => void rampTo(groups.length - 1)}>
          Tout le club
        </Button>
        {busy ? (
          <span className="flex items-center gap-1 text-xs text-muted-foreground">
            <Loader2 className="size-3 animate-spin" />
            Application…
          </span>
        ) : null}
      </div>

      <div className="flex flex-col gap-1.5">
        {groups.map((g) => (
          <AccordionSection key={g.tier?.id ?? "orphan"} defaultOpen title={<SectionCountTitle label={tierGroupLabel(g.tier)} count={g.teams.length} />}>
            {g.teams.map((t) => {
              const active = isActive(t);
              return (
                <div key={t.id} className="flex items-center justify-between gap-3 border-b border-border/60 py-1.5 text-sm last:border-0">
                  <label className="flex items-center gap-2">
                    <input type="checkbox" checked={active} disabled={busy} onChange={(e) => toggle(t, e.target.checked)} aria-label={`${t.name} active cette période`} />
                    <span className={cn(!active && "text-muted-foreground line-through")}>{t.name}</span>
                  </label>
                  <label className={cn("flex items-center gap-1 text-xs text-muted-foreground", !active && "opacity-50")}>
                    séances
                    <input
                      type="number"
                      min={1}
                      max={7}
                      className={cn(fieldClass, "w-14")}
                      value={sessionsOf(t)}
                      disabled={!active || busy}
                      onChange={(e) => setSessions(t, Number(e.target.value))}
                      aria-label={`Séances de ${t.name} cette période`}
                    />
                  </label>
                </div>
              );
            })}
          </AccordionSection>
        ))}
      </div>
    </div>
  );
}


/**
 * Period-editable venues (#8, PR-B) — une carte par gymnase.
 *
 * Une période POSSÈDE sa grille : ses créneaux sont une COPIE du modèle de saison prise à
 * la naissance du plan. Ce panneau montre donc la grille RÉELLEMENT SERVIE au solveur, et
 * la rend pilotable gymnase par gymnase. Le planning principal n'est jamais affecté.
 *
 * DEUX CONTRÔLES, pas trois positions (arbitrage fondateur) :
 *  - un ÉTAT persisté, actif / désactivé, qui ne touche JAMAIS à la grille ;
 *  - deux ACTIONS destructives sur la grille — la reprendre depuis le planning principal,
 *    ou la vider — chacune confirmée en annonçant les réservations emportées.
 * Le troisième « mode » (hériter) n'est pas un état : c'est le défaut, et l'absence de
 * ligne en base. Réactiver un gymnase ne coûte donc jamais la saisie déjà faite, ce qu'un
 * sélecteur à trois positions ne permettait pas (revue #8, round 4).
 *
 * ⚠️ La grille d'un gymnase DÉSACTIVÉ est GELÉE (visible, non modifiable) : la table ne
 * stocke qu'un mode par gymnase, donc « vider » écraserait l'état désactivé. Pour la
 * modifier, on réactive d'abord — et l'écran le dit.
 */
export function PeriodVenues({ calendarEntryId }: { calendarEntryId: string }) {
  const { data: venues = [] } = useWizardVenues();
  // Les réglages pendent au PLAN (inv. 5) ; les CONFLITS se lisent par l'ENTRÉE — le radar
  // parle du FAIT (« Barros fermé »), pas de la réponse qu'on lui apporte.
  const { planId: schedulePlanId, ready: anchorReady } = usePeriodAnchor(calendarEntryId);
  const { data: periodSlots = [] } = usePeriodSlots(schedulePlanId);
  const { data: overrides = [] } = useVenuePeriodOverrides(schedulePlanId);
  const { data: conflicts } = useEntryConflicts(calendarEntryId);
  const closed = new Set(conflicts?.venueIds ?? []);
  const overrideOf = new Map(overrides.map((o) => [o.venueId, o]));

  if (0 === venues.length) {
    return <EmptyHint>Aucun gymnase.</EmptyHint>;
  }

  if (!anchorReady || null === schedulePlanId) {
    return <p className="text-xs text-muted-foreground">Chargement des créneaux de la période…</p>;
  }

  return (
    <div className="space-y-3">
      <p className="text-xs text-muted-foreground">
        Ces créneaux sont repris de votre planning principal à l’ouverture de la période. Les modifier ici ne change que cette période.
      </p>
      {venues.map((venue) => (
        <PeriodVenueCard
          key={venue.id}
          venue={venue}
          schedulePlanId={schedulePlanId}
          slots={periodSlots.filter((s) => s.venueId === venue.id)}
          override={overrideOf.get(venue.id) ?? null}
          isClosedByConstraint={closed.has(venue.id)}
        />
      ))}
    </div>
  );
}

function PeriodVenueCard({
  venue,
  schedulePlanId,
  slots,
  override,
  isClosedByConstraint,
}: {
  venue: Venue;
  schedulePlanId: string;
  slots: VenueTrainingSlot[];
  override: VenuePeriodOverride | null;
  isClosedByConstraint: boolean;
}) {
  const [pending, setPending] = useState<"reset" | "clear" | null>(null);
  const [selectedSlotId, setSelectedSlotId] = useState<string | null>(null);
  const { data: reservations = [] } = useReservations(schedulePlanId);
  const createSlot = useCreatePeriodSlot(schedulePlanId);
  const deleteSlot = useDeletePeriodSlot(schedulePlanId);
  const setMode = useSetVenuePeriodMode(schedulePlanId);
  const clearMode = useClearVenuePeriodMode(schedulePlanId);
  const resetGrid = useResetVenuePeriodGrid(schedulePlanId);

  const isDisabled = "DISABLED" === override?.mode;
  const busy = setMode.isPending || clearMode.isPending || resetGrid.isPending;
  // Ce que les deux actions destructives emporteront — annoncé AVANT, jamais découvert
  // après (« un changement de créneau de gymnase supprime tous les créneaux réservés »).
  const reservationCount = reservations.filter((r) => r.venueId === venue.id).length;

  const toggleActive = () => {
    if (isDisabled) {
      // Réactiver = revenir au défaut. Le backend ne touche PAS à la grille sur ce
      // chemin : c'est ce qui rend « désactiver » réversible sans coût.
      clearMode.mutate(override.id);
      return;
    }
    setMode.mutate({ venueId: venue.id, mode: "DISABLED", existingId: override?.id });
  };

  const doReset = () => {
    // Depuis une grille VIERGE, retirer la ligne vide ET recopie — un seul appel, même
    // effet. Sinon l'action dédiée, atomique elle aussi.
    if ("BLANK" === override?.mode) {
      clearMode.mutate(override.id, { onSuccess: () => toast.success("Grille reprise du planning principal") });
    } else {
      resetGrid.mutate(venue.id, { onSuccess: () => toast.success("Grille reprise du planning principal") });
    }
    setPending(null);
  };

  const doClear = () => {
    setMode.mutate({ venueId: venue.id, mode: "BLANK", existingId: override?.id }, { onSuccess: () => toast.success("Grille vidée") });
    setPending(null);
  };

  return (
    <section className={cn("rounded-lg border p-3", isDisabled ? "border-border bg-muted/40" : "border-border bg-card")}>
      <header className="mb-2 flex flex-wrap items-center gap-2">
        <VenueSwatch color={venue.color ?? "transparent"} className="size-3 border border-border" />
        <span className={cn("font-medium", isDisabled && "text-muted-foreground line-through")}>{venue.name}</span>
        {isDisabled ? <span className="rounded bg-muted px-1.5 py-0.5 text-xs font-semibold text-muted-foreground">Désactivé cette période</span> : null}
        {isClosedByConstraint ? <span className="rounded bg-destructive/10 px-1.5 py-0.5 text-xs font-semibold text-destructive">INTERDIT cette période</span> : null}
        <span className="text-xs text-muted-foreground">
          {slots.length} créneau{slots.length > 1 ? "x" : ""}
        </span>
        <Button type="button" size="sm" variant="outline" className="ml-auto" disabled={busy} onClick={toggleActive}>
          {isDisabled ? "Réactiver" : "Désactiver"}
        </Button>
      </header>

      {isDisabled ? (
        <p className="mb-2 text-xs text-muted-foreground">
          Ce gymnase ne sera pas utilisé pour cette période. Sa grille est conservée telle quelle — réactivez-le pour la modifier.
        </p>
      ) : null}

      <div className={cn(isDisabled && "pointer-events-none opacity-50")} aria-disabled={isDisabled}>
        <VenueAvailabilityGrid
          venue={venue}
          slots={slots}
          selectedSlotId={selectedSlotId}
          onAdd={(dayOfWeek, startTime) => {
            if (isDisabled) {
              return;
            }
            if (null !== findSlotConflict(slots, dayOfWeek, startTime, 90)) {
              toast.error("Ce créneau en chevauche un autre sur ce gymnase.");
              return;
            }
            createSlot.mutate({ venueId: venue.id, dayOfWeek, startTime, durationMinutes: 90, capacity: 1 });
          }}
          onSelect={(slot) => setSelectedSlotId(slot.id === selectedSlotId ? null : slot.id)}
        />
      </div>

      <div className="mt-2 flex flex-wrap items-center gap-2">
        <Button type="button" size="sm" variant="outline" disabled={isDisabled || busy} onClick={() => setPending("reset")}>
          Reprendre la grille du planning principal
        </Button>
        <Button type="button" size="sm" variant="outline" disabled={isDisabled || busy || 0 === slots.length} onClick={() => setPending("clear")}>
          Vider la grille
        </Button>
        {null !== selectedSlotId && !isDisabled ? (
          <Button
            type="button"
            size="sm"
            variant="destructive"
            disabled={deleteSlot.isPending}
            onClick={() => {
              deleteSlot.mutate(selectedSlotId);
              setSelectedSlotId(null);
            }}
          >
            <Trash2 className="size-4" />
            Supprimer ce créneau
          </Button>
        ) : null}
      </div>

      <ConfirmDialog
        open={null !== pending}
        destructive
        title={"reset" === pending ? `Reprendre la grille de ${venue.name} ?` : `Vider la grille de ${venue.name} ?`}
        description={
          "reset" === pending
            ? `Les créneaux de ${venue.name} pour cette période seront remplacés par ceux de votre planning principal.${reservationCount > 0 ? ` ${reservationCount} réservation${reservationCount > 1 ? "s" : ""} sur ce gymnase ser${reservationCount > 1 ? "ont" : "a"} supprimée${reservationCount > 1 ? "s" : ""}.` : ""}`
            : `Tous les créneaux de ${venue.name} pour cette période seront supprimés, à ressaisir.${reservationCount > 0 ? ` ${reservationCount} réservation${reservationCount > 1 ? "s" : ""} sur ce gymnase part${reservationCount > 1 ? "iront" : "ira"} avec eux.` : ""}`
        }
        confirmLabel={"reset" === pending ? "Reprendre" : "Vider"}
        onConfirm={"reset" === pending ? doReset : doClear}
        onCancel={() => setPending(null)}
      />
    </section>
  );
}

/** Libellés gestionnaire (jamais l'enum brut à l'écran). */
const RULE_LABEL: Record<ConstraintRuleType, string> = {
  HARD: "Obligatoire",
  PREFERRED: "Préféré",
  BONUS: "Bonus",
  LOCK: "Verrouillé",
};

/** L'onglet-famille où une contrainte héritée se range. FACILITY_CAPACITY n'a pas
 *  d'onglet propre (aucun formulaire ne la crée) → rangée avec « Gymnase ». */
const familyTabOf = (family: Constraint["family"]): Constraint["family"] => ("FACILITY_CAPACITY" === family ? "FACILITY" : family);

/**
 * Period-editable constraints: the club's PERMANENT constraints are inherited into the
 * overlay (read-only), each toggle-able for the window via a sparse ConstraintPeriodOverride
 * that DEVIATES from the smart default. No row = the default applies; the base plan and the
 * Constraint's own isActive are never touched.
 *
 * - Fermeture (closure): default = keep every constraint (B3+F2 — unchanged).
 * - Reprise (holiday): default FOLLOWS the team selection — CLUB/COACH kept, a TEAM
 *   constraint kept only if its team reprend (not deactivated), FACILITY dropped.
 *
 * Rendered only for overlay-generating periods (closure | holiday), on a CONFIRMED entry
 * (never during its load window, so the wrong default can't flash).
 *
 * `family` (fondateur 2026-07-24) : rendue DANS l'onglet-famille de ConstraintsStep, la
 * section ne montre que les héritées de CET onglet — plus d'écran à part au-dessus. Sans
 * `family` (tests/usages historiques), liste complète inchangée. Un onglet sans héritée
 * ne rend RIEN (pas de section vide répétée par onglet) — mais uniquement une fois les
 * requêtes RÉSOLUES et SANS erreur : masquer pendant le chargement ferait clignoter le
 * cadre, et masquer sur erreur laisserait croire qu'aucune contrainte n'est héritée
 * (P4-1 : le panneau reste visible sur erreur, cf. plus bas).
 *
 * ⚠️ Le composant DOIT rester monté tant que le gestionnaire travaille la période :
 * `inflight` (et les onSettled par mutation) sérialisent les écritures d'override, et un
 * démontage en cours d'écriture les perd — d'où le rendu conditionnel de l'appelant, qui
 * doit dépendre de l'onglet actif SANS démonter (voir ConstraintsStep).
 */
export function PeriodConstraints({ calendarEntryId, family }: { calendarEntryId: string; family?: Constraint["family"] }) {
  const { data: entry } = useCalendarEntry(calendarEntryId);
  // Inv. 5 (lot C2) : les bascules de contraintes pendent au PLAN, pas au déclencheur.
  // Ce composant ne vit qu'en mode période (calendarEntryId requis), donc `ready` équivaut
  // ici à `null !== planId` — on garde la forme nulle, qui narrow le type pour l'écriture.
  const { planId: schedulePlanId } = usePeriodAnchor(calendarEntryId);
  const isClosure = "closure" === entry?.periodType;
  const isReprise = "holiday" === entry?.periodType;
  const isOverlay = isClosure || isReprise;
  const { data: constraints = [], isLoading, isError: constraintsError } = useWizardConstraints(); // permanent (base) constraints
  const { data: teams = [] } = useWizardTeams();
  const { data: tags = [], isLoading: tagsLoading, isError: tagsError } = useWizardTeamTags();
  const { data: tagAssignments = [], isLoading: tagAssignmentsLoading, isError: tagAssignmentsError } = useWizardTeamTagAssignments();
  // Off an overlay period, null disables both queries (no wasted fetch).
  const { data: overrides = [], isLoading: overridesLoading, isError: overridesError } = usePeriodConstraintOverrides(isOverlay ? schedulePlanId : null);
  // Needed for BOTH period types: a TEAM constraint of a deactivated team is non-applicable
  // and dropped from the payload server-side — the checklist must mirror that. On a fetch
  // error we can't tell which teams are paused, so a TEAM constraint's applicability is
  // UNKNOWN → its toggle is disabled (non-TEAM scopes are unaffected and stay usable).
  const { data: teamOverrides = [], isLoading: teamOverridesLoading, isError: teamOverridesError } = useTeamPeriodOverrides(isOverlay ? schedulePlanId : null);
  const create = useCreatePeriodConstraintOverride(schedulePlanId);
  const update = useUpdatePeriodConstraintOverride(schedulePlanId);
  const del = useDeletePeriodConstraintOverride(schedulePlanId);
  // Optimistic intended-active state per constraint while its write is in flight (the
  // sparse row only lands after the refetch). Also serializes toggles: each of create/update/
  // del is a SINGLE shared observer, so firing the same hook for a second constraint before
  // the first settles would rebind it and drop the first's onSettled — one write at a time
  // keeps every onSettled reliable.
  const [inflight, setInflight] = useState<Map<string, boolean>>(new Map());
  const deactivatedTeamIds = useMemo(() => new Set(teamOverrides.filter((o) => !o.isActive).map((o) => o.teamId)), [teamOverrides]);
  const activeTeamIds = useMemo(() => new Set(teams.filter((t) => !deactivatedTeamIds.has(t.id)).map((t) => t.id)), [teams, deactivatedTeamIds]);
  const tagTeamIdsByName = useMemo(() => {
    const tagNameById = new Map(tags.map((t) => [t.id, t.name]));
    const byTag = new Map<string, Set<string>>();
    for (const assignment of tagAssignments) {
      const tagName = tagNameById.get(assignment.tagId);
      if (undefined === tagName) {
        continue;
      }
      const teamIds = byTag.get(tagName) ?? new Set<string>();
      teamIds.add(assignment.teamId);
      byTag.set(tagName, teamIds);
    }
    return byTag;
  }, [tags, tagAssignments]);
  const activeTagTeamIdsByName = useMemo(() => {
    const byTag = new Map<string, Set<string>>();
    for (const [tagName, teamIds] of tagTeamIdsByName) {
      byTag.set(tagName, new Set([...teamIds].filter((teamId) => activeTeamIds.has(teamId))));
    }
    return byTag;
  }, [activeTeamIds, tagTeamIdsByName]);
  const needsTagResolution = constraints.some((c) => "string" === typeof c.config?.targetTag && "" !== c.config.targetTag);
  const tagResolutionReady = !needsTagResolution || (!tagsLoading && !tagAssignmentsLoading && !tagsError && !tagAssignmentsError);
  // Smart default, mirrored server-side (ScheduleConstraintBuilder::inheritedPermanents, reprise predicate).
  const defaultKept = (c: Constraint): boolean => {
    if (isClosure) {
      return true; // fermeture: everything kept by default (B3+F2 unchanged)
    }
    switch (c.scope) {
      case "FACILITY":
        return false;
      case "TEAM":
        return !deactivatedTeamIds.has(c.scopeTargetId ?? "");
      default:
        return true; // CLUB, COACH
    }
  };
  // A TEAM constraint whose team is paused can't ship (server-side buildForOverlay drops it) —
  // show it struck & disabled, never toggle-able, so the checklist matches the payload.
  const notApplicable = (c: Constraint): boolean => "TEAM" === c.scope && deactivatedTeamIds.has(c.scopeTargetId ?? "");
  const overrideOf = new Map(overrides.map((o) => [o.constraintId, o]));
  const activeTagTeamIds = (tagName: string): Set<string> => activeTagTeamIdsByName.get(tagName) ?? new Set();
  const targetTagOf = (c: Constraint): string | null => ("string" === typeof c.config?.targetTag && "" !== c.config.targetTag ? (c.config.targetTag as string) : null);
  const hidden = (c: Constraint): boolean => {
    const tagName = targetTagOf(c);
    return tagResolutionReady && "CLUB" === c.scope && null !== tagName && 0 === activeTagTeamIds(tagName).size;
  };
  const shown = (c: Constraint): boolean => !hidden(c) && (undefined === family || familyTabOf(c.family) === family);
  const activeOf = (c: Constraint): boolean => (notApplicable(c) ? false : inflight.has(c.id) ? (inflight.get(c.id) as boolean) : (overrideOf.get(c.id)?.isActive ?? defaultKept(c)));
  const mutating = inflight.size > 0;
  // Toggle = upsert-or-delete-to-default (mirrors the team override): back to the default
  // drops the row (stays sparse); a deviation upserts (create, or PUT if a row exists).
  // A TEAM constraint whose applicability can't be determined (team overrides failed to load)
  // is locked — non-TEAM scopes don't depend on the team selection and stay usable.
  const teamStateUnknown = (c: Constraint): boolean => teamOverridesError && "TEAM" === c.scope;
  const toggle = (c: Constraint, desired: boolean) => {
    // overridesError: without the override list we can't tell create-vs-update, so a toggle
    // would POST an existing row → 422. Render read-only until it reloads (P4-1 query-error UX).
    if (mutating || notApplicable(c) || overridesError || teamStateUnknown(c) || null === schedulePlanId) {
      // a write is settling, the constraint can't ship / is unknown, overrides are
      // unavailable — ou le plan n'est pas chargé : sans ancre (inv. 5) il n'y a nulle
      // part où écrire le réglage.
      return;
    }
    const settle = () =>
      setInflight((m) => {
        const next = new Map(m);
        next.delete(c.id);
        return next;
      });
    setInflight((m) => new Map(m).set(c.id, desired));
    const existing = overrideOf.get(c.id);
    if (desired === defaultKept(c)) {
      if (undefined === existing) {
        settle();
        return;
      }
      del.mutate(existing.id, { onSettled: settle });
      return;
    }
    if (undefined !== existing) {
      update.mutate({ id: existing.id, body: { schedulePlanId, constraintId: c.id, isActive: desired } }, { onSettled: settle });
      return;
    }
    create.mutate({ schedulePlanId, constraintId: c.id, isActive: desired }, { onSettled: settle });
  };

  if (!isOverlay) {
    return null;
  }
  const visible = constraints.filter(shown);
  // DEUX niveaux de chargement, à ne pas confondre (revue #284 round 2) :
  // - PRÉSENCE de la section : ce qui décide QUELLES contraintes s'affichent — la requête
  //   des contraintes de base, plus la résolution des tags dont `hidden` dépend. Les
  //   overrides n'en font PAS partie : ils ne pilotent que l'ÉTAT des cases. Les y inclure
  //   faisait disparaître puis réapparaître la section quand le plan se résolvait (les
  //   requêtes d'override, désactivées tant que planId est null, rapportent isLoading=false
  //   avant de basculer à true) — un saut de mise en page pire que le flash corrigé.
  // - CORPS : attend en plus les overrides, sinon les cases s'afficheraient au mauvais état.
  const presenceSettled = !isLoading && tagResolutionReady;
  const bodyLoading = !presenceSettled || overridesLoading || teamOverridesLoading;
  // Dans un onglet, on ne rend RIEN tant qu'il n'y a rien à montrer (l'EmptyHint global n'a
  // de sens que pour la liste complète) : rien pendant que la présence se décide — sinon le
  // cadre titré apparaît puis disparaît — et rien si l'onglet est vide une fois décidée.
  // Une fois la section rendue, elle ne peut plus disparaître : `visible` ne dépend que de
  // requêtes déjà résolues. SUR ERREUR des contraintes, on rend malgré tout (P4-1) : une
  // liste vide par échec n'est pas « rien à hériter ».
  if (undefined !== family && !constraintsError && (!presenceSettled || 0 === visible.length)) {
    return null;
  }

  return (
    <div className="mb-4 space-y-2 rounded-lg border border-border bg-card p-3">
      <p className="text-sm font-medium">Contraintes du planning principal</p>
      <p className="text-xs text-muted-foreground">Cochez celles à garder pendant cette période — le planning principal n'est pas modifié.</p>
      {/* Le corps attend les requêtes qui pilotent l'ÉTAT des cases (overrides de la période —
          sinon un toggle 422 sur une ligne existante — et overrides d'équipes, qui donnent le
          défaut reprise et le barré non-applicable). Sur ERREUR des contraintes on le dit
          explicitement : afficher « Aucune contrainte permanente » serait le mensonge exact
          que le panneau doit éviter — le gestionnaire validerait la période en croyant que
          rien n'est hérité (revue #284 round 2). */}
      {constraintsError ? (
        <p className="text-xs text-destructive">
          Impossible de charger les contraintes du planning principal. Elles restent appliquées selon leur réglage actuel — rechargez la page pour les ajuster.
        </p>
      ) : bodyLoading ? null : 0 === visible.length ? (
        <EmptyHint>Aucune contrainte permanente.</EmptyHint>
      ) : (
        <ul className="flex flex-col gap-1">
          {visible.map((c) => {
            const active = activeOf(c);
            const naf = notApplicable(c);
            const tagName = targetTagOf(c);
            const tagUnknown = "CLUB" === c.scope && null !== tagName && !tagResolutionReady;
            return (
              <li key={c.id} className="flex items-center justify-between gap-3 border-b border-border/60 py-1.5 text-sm last:border-0">
                <label className="flex items-center gap-2">
                  <input type="checkbox" checked={active} disabled={mutating || naf || overridesError || teamStateUnknown(c) || tagUnknown} onChange={(e) => toggle(c, e.target.checked)} aria-label={`${c.name} appliquée cette période`} />
                  <span className={cn(!active && "text-muted-foreground line-through")}>{c.name}</span>
                  {naf ? <span className="text-xs text-muted-foreground">(équipe en pause)</span> : null}
                </label>
                <span className="shrink-0 text-xs text-muted-foreground">{RULE_LABEL[c.ruleType]}</span>
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}
