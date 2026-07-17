import { CalendarPlus, Loader2, Trash2 } from "lucide-react";
import { type FormEvent, useEffect, useMemo, useState } from "react";

import { useCalendarEntry, useEntryConflicts, useSchedulePlanForEntry } from "@/features/cockpit/queries";
import { AccordionSection } from "@/shared/components/ui/accordion";
import { Button } from "@/shared/components/ui/button";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { VenueSwatch } from "@/shared/components/ui/venue-swatch";
import { groupTeamsByTier, tierGroupLabel } from "@/shared/lib/teamTiers";
import { cn } from "@/shared/lib/utils";
import { toast } from "@/shared/stores/toastStore";

import type { Constraint, ConstraintRuleType, Team, TeamPeriodOverride } from "../api";
import { countSlotsByVenue } from "../lib/summary";
import {
  useCreatePeriodConstraintOverride,
  useCreatePeriodSlot,
  useCreateTeamPeriodOverride,
  useDeletePeriodConstraintOverride,
  useDeletePeriodSlot,
  useDeleteTeamPeriodOverride,
  usePeriodConstraintOverrides,
  usePeriodSlots,
  usePriorityTiers,
  useWizardTeamTagAssignments,
  useWizardTeamTags,
  useTeamPeriodOverrides,
  useUpdatePeriodConstraintOverride,
  useUpdateTeamPeriodOverride,
  useVenueSlots,
  useWizardConstraints,
  useWizardTeams,
  useWizardVenues,
} from "../queries";
import { SectionCountTitle } from "./StructureSummary";
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
  const { data: overrides = [], isLoading } = useTeamPeriodOverrides(calendarEntryId);
  const { data: plan, isLoading: planLoading } = useSchedulePlanForEntry(calendarEntryId);
  const create = useCreateTeamPeriodOverride(calendarEntryId);
  const update = useUpdateTeamPeriodOverride(calendarEntryId);
  const del = useDeleteTeamPeriodOverride(calendarEntryId);
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
    const body = { calendarEntryId, teamId: t.id, isActive: next.isActive, sessionsPerWeek: next.sessions };
    return existing ? update.mutateAsync({ id: existing.id, body }) : create.mutateAsync(body);
  };
  // Fire-and-forget for single edits (checkbox/session). Swallow the rejection: the
  // global MutationCache already toasts it — this only avoids an unhandledrejection.
  const upsert = (t: Team, next: { isActive: boolean; sessions: number | null }) => void upsertAsync(t, next).catch(() => {});

  // Fanion-only default: on a FRESH period (no overrides yet, not already seeded this
  // session) deactivate every team below the top tier, ONCE — best-effort. Controls
  // stay disabled (busy) until the async writes settle so a first click can't race
  // them. The key is claimed BEFORE firing and never un-claimed: un-claiming on a
  // partial failure re-runs the effect against a still-empty override cache and
  // double-writes the teams that already succeeded. On a failure the global toast
  // informs; the manager applies "Fanion seul" (ramp) to complete.
  //
  // The DURABLE signal is plan.teamSelectionInitialized (server-set on the first
  // override, survives reload); seededPeriods only guards the in-session window
  // between firing the seed and the plan query reflecting the flag. ADR-0002 lot C:
  // the flag lives on the plan — the RESPONSE — not on the calendar event.
  useEffect(() => {
    // `false !== plan?.teamSelectionInitialized` bails on undefined AND on null too:
    // a fetch error leaves it undefined, and a period with no plan (inv. 9) returns
    // null — we must NOT seed an unknown period (it could be an already-configured one
    // whose GET blipped). Only seed when the plan loaded AND is explicitly uninitialised.
    if (isLoading || planLoading || 0 === teams.length || overrides.length > 0 || null === topTierId || false !== plan?.teamSelectionInitialized || periodSeedWasClaimed(calendarEntryId)) {
      return;
    }
    claimPeriodSeed(calendarEntryId);
    const belowTop = teams.filter((t) => t.priorityTierId !== topTierId);
    if (0 === belowTop.length) {
      return;
    }
    // eslint-disable-next-line react-hooks/set-state-in-effect -- one-shot: gate the controls while the async Fanion-only seed writes settle
    setBusy(true);
    void Promise.allSettled(belowTop.map((t) => create.mutateAsync({ calendarEntryId, teamId: t.id, isActive: false }))).finally(() => setBusy(false));
  }, [isLoading, planLoading, plan, teams, overrides, topTierId, calendarEntryId, create]);

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
    if (busy) {
      return;
    }
    setBusy(true);
    try {
      const results = await Promise.allSettled(groups.flatMap((g, i) => g.teams.map((t) => upsertAsync(t, { isActive: i <= upToIndex, sessions: overrideOf.get(t.id)?.sessionsPerWeek ?? null }))));
      // Only confirm when every write landed — failures are toasted by the global net.
      if (!results.some((r) => "rejected" === r.status)) {
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
        Choisissez qui reprend sur cette période. Par défaut seul le <strong>Fanion</strong> est actif — cochez les équipes au fur et à mesure de la montée en charge.
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

const WEEKDAYS = ["Lun", "Mar", "Mer", "Jeu", "Ven", "Sam", "Dim"];

/**
 * Period-editable venues (F1): the seasonal gyms are READ-ONLY (with the period's
 * closures marked), plus an editor for the period's OWN borrowed slots (a gym the
 * city lends just for this window) — additive on top of the seasonal set.
 */
export function PeriodVenues({ calendarEntryId }: { calendarEntryId: string }) {
  const { data: venues = [] } = useWizardVenues();
  const { data: seasonalSlots = [] } = useVenueSlots();
  const { data: periodSlots = [] } = usePeriodSlots(calendarEntryId);
  const { data: conflicts } = useEntryConflicts(calendarEntryId);
  const createSlot = useCreatePeriodSlot(calendarEntryId);
  const deleteSlot = useDeletePeriodSlot(calendarEntryId);
  const closed = new Set(conflicts?.venueIds ?? []);
  const slotsByVenue = countSlotsByVenue(seasonalSlots);
  const venueName = new Map(venues.map((v) => [v.id, v.name]));

  const [venueId, setVenueId] = useState("");
  const [day, setDay] = useState(1);
  const [start, setStart] = useState("18:00");
  const [duration, setDuration] = useState(90);

  const submit = (e: FormEvent) => {
    e.preventDefault();
    if ("" === venueId) {
      return;
    }
    createSlot.mutate(
      { venueId, dayOfWeek: day, startTime: start, durationMinutes: duration, capacity: 1 },
      { onSuccess: () => toast.success("Créneau ajouté pour la période") },
    );
  };

  return (
    <div className="space-y-4">
      <div className="space-y-1">
        <p className="text-sm font-medium">Gymnases du planning principal</p>
        {0 === venues.length ? (
          <EmptyHint>Aucun gymnase.</EmptyHint>
        ) : (
          <ul className="flex flex-col gap-1">
            {venues.map((v) => {
              const isClosed = closed.has(v.id);
              return (
                <li key={v.id} className={cn("flex items-center gap-2 rounded-md border px-3 py-1.5 text-sm", isClosed ? "border-destructive/50 bg-destructive/10" : "border-border bg-card")}>
                  <VenueSwatch color={v.color ?? "transparent"} className="size-3 border border-border" />
                  <span className={cn("flex-1", isClosed && "text-destructive line-through")}>{v.name}</span>
                  <span className={cn("shrink-0 text-xs", isClosed ? "font-semibold text-destructive" : "text-muted-foreground")}>{isClosed ? "INTERDIT cette période" : `${slotsByVenue.get(v.id) ?? 0} créneau(x)`}</span>
                </li>
              );
            })}
          </ul>
        )}
      </div>

      <div className="space-y-2">
        <p className="text-sm font-medium">Créneaux prêtés pour la période</p>
        {periodSlots.length > 0 ? (
          <ul className="flex flex-col gap-1">
            {periodSlots.map((s) => (
              <li key={s.id} className="flex items-center justify-between gap-2 rounded-md border border-accent/40 bg-accent/5 px-3 py-1.5 text-sm">
                <span>
                  {venueName.get(s.venueId) ?? "Gymnase"} — {WEEKDAYS[s.dayOfWeek - 1]} {s.startTime.slice(0, 5)} ({s.durationMinutes} min)
                </span>
                <button type="button" aria-label="Supprimer ce créneau" className="rounded p-1 text-muted-foreground hover:text-destructive" disabled={deleteSlot.isPending} onClick={() => deleteSlot.mutate(s.id)}>
                  <Trash2 className="size-4" />
                </button>
              </li>
            ))}
          </ul>
        ) : (
          <p className="text-xs text-muted-foreground">Aucun créneau ajouté pour cette période.</p>
        )}

        <form onSubmit={submit} className="flex flex-wrap items-end gap-2 rounded-md border border-border p-2">
          <select className={fieldClass} aria-label="Gymnase" value={venueId} onChange={(e) => setVenueId(e.target.value)}>
            <option value="">Gymnase…</option>
            {venues.map((v) => (
              <option key={v.id} value={v.id}>
                {v.name}
              </option>
            ))}
          </select>
          <select className={fieldClass} aria-label="Jour" value={day} onChange={(e) => setDay(Number(e.target.value))}>
            {WEEKDAYS.map((d, i) => (
              <option key={d} value={i + 1}>
                {d}
              </option>
            ))}
          </select>
          <input type="time" className={fieldClass} aria-label="Heure de début" value={start} onChange={(e) => setStart(e.target.value)} />
          <input type="number" min={15} step={15} className={cn(fieldClass, "w-20")} aria-label="Durée (min)" value={duration} onChange={(e) => setDuration(Number(e.target.value))} />
          <Button type="submit" size="sm" disabled={createSlot.isPending || "" === venueId}>
            <CalendarPlus className="size-4" />
            Ajouter
          </Button>
        </form>
      </div>
    </div>
  );
}

/** Libellés gestionnaire (jamais l'enum brut à l'écran). */
const RULE_LABEL: Record<ConstraintRuleType, string> = {
  HARD: "Obligatoire",
  PREFERRED: "Préféré",
  BONUS: "Bonus",
  LOCK: "Verrouillé",
};

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
 */
export function PeriodConstraints({ calendarEntryId }: { calendarEntryId: string }) {
  const { data: entry } = useCalendarEntry(calendarEntryId);
  const isClosure = "closure" === entry?.periodType;
  const isReprise = "holiday" === entry?.periodType;
  const isOverlay = isClosure || isReprise;
  const { data: constraints = [], isLoading } = useWizardConstraints(); // permanent (base) constraints
  const { data: teams = [] } = useWizardTeams();
  const { data: tags = [], isLoading: tagsLoading, isError: tagsError } = useWizardTeamTags();
  const { data: tagAssignments = [], isLoading: tagAssignmentsLoading, isError: tagAssignmentsError } = useWizardTeamTagAssignments();
  // Off an overlay period, null disables both queries (no wasted fetch).
  const { data: overrides = [], isLoading: overridesLoading, isError: overridesError } = usePeriodConstraintOverrides(isOverlay ? calendarEntryId : null);
  // Needed for BOTH period types: a TEAM constraint of a deactivated team is non-applicable
  // and dropped from the payload server-side — the checklist must mirror that. On a fetch
  // error we can't tell which teams are paused, so a TEAM constraint's applicability is
  // UNKNOWN → its toggle is disabled (non-TEAM scopes are unaffected and stay usable).
  const { data: teamOverrides = [], isLoading: teamOverridesLoading, isError: teamOverridesError } = useTeamPeriodOverrides(isOverlay ? calendarEntryId : null);
  const create = useCreatePeriodConstraintOverride(calendarEntryId);
  const update = useUpdatePeriodConstraintOverride(calendarEntryId);
  const del = useDeletePeriodConstraintOverride(calendarEntryId);
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
    if (mutating || notApplicable(c) || overridesError || teamStateUnknown(c)) {
      return; // a write is settling, the constraint can't ship / is unknown, or overrides are unavailable
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
      update.mutate({ id: existing.id, body: { calendarEntryId, constraintId: c.id, isActive: desired } }, { onSettled: settle });
      return;
    }
    create.mutate({ calendarEntryId, constraintId: c.id, isActive: desired }, { onSettled: settle });
  };

  if (!isOverlay) {
    return null;
  }

  return (
    <div className="mb-4 space-y-2 rounded-lg border border-border bg-card p-3">
      <p className="text-sm font-medium">Contraintes du planning principal</p>
      <p className="text-xs text-muted-foreground">Cochez celles à garder pendant cette période — le planning principal n'est pas modifié.</p>
      {/* Wait for all three queries to LOAD (constraints, the period's own overrides — else a
          toggle 422s on an existing row — and team overrides, which drive the reprise default
          + the non-applicable strike). On a fetch ERROR we still render: the backend stays
          authoritative on the payload, so a transient error only glitches the strike, and
          hiding the whole panel would strand the closure editor (query-error UX = dette P4-1). */}
      {isLoading || overridesLoading || teamOverridesLoading ? null : 0 === constraints.filter((c) => !hidden(c)).length ? (
        <EmptyHint>Aucune contrainte permanente.</EmptyHint>
      ) : (
        <ul className="flex flex-col gap-1">
          {constraints.filter((c) => !hidden(c)).map((c) => {
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
