import { useMemo } from "react";

import { useSchedulePlanForEntry } from "@/features/cockpit/queries";
import { AccordionSection } from "@/shared/components/ui/accordion";
import { Card, CardContent } from "@/shared/components/ui/card";

import { FAMILY_LABEL, FAMILY_ORDER, groupConstraints } from "../lib/constraintOrder";
import { LEVEL_LABEL } from "../lib/labels";
import { coachMeta, groupedCoaches } from "../lib/ranking";
import { coachTeamNames, countSlotsByVenue } from "../lib/summary";
import { useStepValidation } from "../lib/useStepValidation";
import { BlockerList } from "./BlockerList";
import { VenueSwatch } from "@/shared/components/ui/venue-swatch";

import { SectionCountTitle, SummaryRow, TeamTierAccordion } from "./StructureSummary";
import { usePriorityTiers, useReservations, useVenueSlots, useWizardCoachPlayers, useWizardCoaches, useWizardConstraints, useWizardTeamCoaches, useWizardTeams, useWizardTeamTags, useWizardVenues } from "../queries";
import { useWizardStore } from "../store";
import { groupTeamsByTier } from "@/shared/lib/teamTiers";
import { dayLabel, hhmm } from "../lib/days";

// Manager-facing labels for the FFBB play levels (mirrors the teams step).
function Counter({ label, value, sub }: { label: string; value: number; sub?: string }) {
  return (
    <Card>
      <CardContent className="p-3">
        <div className="text-2xl font-semibold">{value}</div>
        <div className="text-xs text-muted-foreground">{label}</div>
        {sub ? <div className="text-xs text-muted-foreground">{sub}</div> : null}
      </CardContent>
    </Card>
  );
}

const empty = <p className="py-1.5 text-sm text-muted-foreground">—</p>;

export function RecapStep() {
  const { data: teams = [] } = useWizardTeams();
  const { data: venues = [] } = useWizardVenues();
  const { data: slots = [] } = useVenueSlots();
  const { data: coaches = [] } = useWizardCoaches();
  const { data: coachPlayers = [] } = useWizardCoachPlayers();
  const { data: teamCoaches = [] } = useWizardTeamCoaches();
  const periodEntryId = useWizardStore((s) => (s.mode === "period" ? s.calendarEntryId : null));
  const { data: constraints = [] } = useWizardConstraints(periodEntryId);
  // Les réservations pendent au PLAN (inv. 5, lot C3) — les contraintes, elles, restent
  // lues par l'entrée (elles décrivent le FAIT).
  const { data: reservations = [] } = useReservations(useSchedulePlanForEntry(periodEntryId).data?.id ?? null);
  const { data: tiers = [] } = usePriorityTiers();
  const { data: tags = [] } = useWizardTeamTags();
  // Blockers live in useStepValidation("recap") so the footer "Continuer vers la
  // génération" button is gated by the same rules (single source of truth).
  const { errors: blockers } = useStepValidation("recap");

  const salaried = coaches.filter((c) => c.isEmployee).length;
  const coachPlayerIds = new Set(coachPlayers.filter((cp) => cp.isActive).map((cp) => cp.coachId));
  const hardConstraints = constraints.filter((c) => c.ruleType === "HARD").length;
  const slotsByVenue = countSlotsByVenue(slots);

  const teamName = new Map(teams.map((t) => [t.id, t.name]));
  const venueName = new Map(venues.map((v) => [v.id, v.name]));
  const coachName = new Map(coaches.map((c) => [c.id, `${c.firstName} ${c.lastName}`.trim()]));
  // Reservations ordered by team rank (fanion S → A → B → C → D), then day + time.
  const teamRank = new Map(groupTeamsByTier(teams, tiers).flatMap((g) => g.teams).map((t, i) => [t.id, i]));
  const rankOf = (id: string): number => teamRank.get(id) ?? Number.MAX_SAFE_INTEGER;
  const sortedReservations = [...reservations].sort((a, b) => rankOf(a.teamId) - rankOf(b.teamId) || a.dayOfWeek - b.dayOfWeek || hhmm(a.startTime).localeCompare(hhmm(b.startTime)));
  // Coaches split into staffing groups (Salariés / Coachs-joueurs / Bénévoles),
  // each shown under its own header (user request — same as the constraint tab).
  const coachGroups = useMemo(() => {
    const g = groupedCoaches(coaches, new Set(coachPlayers.filter((cp) => cp.isActive).map((cp) => cp.coachId)));
    return ([["salaried", "Salariés"], ["player", "Coachs-joueurs"], ["other", "Bénévoles"]] as const).map(([k, label]) => ({ label, coaches: g[k] })).filter((s) => s.coaches.length > 0);
  }, [coaches, coachPlayers]);
  // Constraints grouped by FAMILY, then by the family's own grouping dimension
  // (coach→staffing, gymnase→venue, horaire/jours→axis) — same as each tab.
  const constraintFamilies = useMemo(() => {
    const ctx = {
      teams,
      tiers,
      tags,
      coaches,
      coachPlayerIds: new Set(coachPlayers.filter((cp) => cp.isActive).map((cp) => cp.coachId)),
      venues,
      coachName: (id: string) => coachName.get(id) ?? "Coach",
      venueName: (id: string) => venueName.get(id) ?? "Gymnase",
    };
    const isKnownFamily = (f: string): boolean => (FAMILY_ORDER as readonly string[]).includes(f);
    return [
      ...FAMILY_ORDER.map((family) => ({ family: family as string, sections: groupConstraints(constraints.filter((c) => c.family === family), family, ctx) })),
      { family: "OTHER", sections: groupConstraints(constraints.filter((c) => !isKnownFamily(c.family)), "OTHER", ctx) },
    ].filter((g) => g.sections.length > 0);
    // coachName/venueName are fresh Maps each render; the real inputs are the data.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [constraints, teams, tiers, tags, coaches, coachPlayers, venues]);
  // A team's main coach (fallback: its first linked coach) — shown inline in italic.
  const mainCoachName = (teamId: string): string | null => {
    const links = teamCoaches.filter((tc) => tc.teamId === teamId);
    const link = links.find((tc) => "MAIN" === tc.role) ?? links[0];
    const coach = link ? coaches.find((c) => c.id === link.coachId) : undefined;
    return coach ? `${coach.firstName} ${coach.lastName}`.trim() : null;
  };

  return (
    <div>
      <p className="mb-4 text-sm text-muted-foreground">Cartographie de votre club avant génération.</p>

      <div className="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
        <Counter label="Équipes" value={teams.length} />
        <Counter label="Gymnases" value={venues.length} />
        <Counter label="Coachs" value={coaches.length} sub={`dont ${salaried} salarié(s) · ${coachPlayerIds.size} coach-joueur(s)`} />
        <Counter label="Contraintes dures" value={hardConstraints} />
      </div>

      <div className="mb-4 flex flex-col gap-1.5">
        <AccordionSection title={<SectionCountTitle label="Équipes" count={teams.length} />}>
          {0 === teams.length ? (
            empty
          ) : (
            <TeamTierAccordion
              teams={teams}
              // Tiers open by default: the ranks (S · Fanion → D · Bonus) must be
              // visible at first glance — collapsed inner accordions read as an
              // unsorted flat list to the manager.
              renderRow={(t) => {
                const coach = mainCoachName(t.id);
                return (
                  <SummaryRow
                    key={t.id}
                    label={
                      <span className="flex flex-wrap items-baseline gap-x-2">
                        <span>{t.name}</span>
                        {coach ? <span className="text-xs italic text-muted-foreground">{coach}</span> : null}
                        {t.level ? <span className="text-xs italic text-muted-foreground">· {LEVEL_LABEL[t.level]}</span> : null}
                      </span>
                    }
                    meta={`${t.sessionsPerWeek} séance(s)/sem`}
                  />
                );
              }}
            />
          )}
        </AccordionSection>
        <AccordionSection title={<SectionCountTitle label="Gymnases" count={venues.length} />}>
          {0 === venues.length
            ? empty
            : venues.map((v) => (
                <SummaryRow
                  key={v.id}
                  label={
                    <span className="flex items-center gap-2">
                      <VenueSwatch color={v.color ?? "transparent"} className="size-3 border border-border" />
                      {v.name}
                    </span>
                  }
                  meta={`${slotsByVenue.get(v.id) ?? 0} créneau(x)`}
                />
              ))}
        </AccordionSection>
        <AccordionSection title={<SectionCountTitle label="Coachs" count={coaches.length} />}>
          {0 === coaches.length
            ? empty
            : coachGroups.map((group) => (
                <div key={group.label} className="mb-2 last:mb-0">
                  <p className="px-1 pb-1 pt-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">{group.label}</p>
                  {group.coaches.map((c) => {
                    const teamsOf = coachTeamNames(c.id, teamCoaches, teamName);
                    const fullName = `${c.firstName} ${c.lastName}`.trim();
                    return (
                      <SummaryRow
                        key={c.id}
                        label={`${fullName}${teamsOf.length > 0 ? ` (${teamsOf.join(", ")})` : ""}`}
                        meta={coachMeta(c.isEmployee, coachPlayerIds.has(c.id))}
                      />
                    );
                  })}
                </div>
              ))}
        </AccordionSection>
        <AccordionSection title={<SectionCountTitle label="Contraintes" count={constraints.length} />}>
          {0 === constraints.length
            ? empty
            : constraintFamilies.map((group) => (
                <div key={group.family} className="mb-3 last:mb-0">
                  <p className="px-1 pb-1 pt-2 text-xs font-semibold uppercase tracking-wide text-foreground">{FAMILY_LABEL[group.family] ?? "Autres"}</p>
                  {group.sections.map((section) => (
                    <div key={section.key} className="mb-1 last:mb-0">
                      <p className="px-2 text-[11px] font-medium text-muted-foreground">{section.label}</p>
                      {section.items.map((c) => (
                        <SummaryRow key={c.id} label={c.name} meta={c.ruleType} />
                      ))}
                    </div>
                  ))}
                </div>
              ))}
        </AccordionSection>
        <AccordionSection title={<SectionCountTitle label="Réservations" count={reservations.length} />}>
          {0 === sortedReservations.length
            ? empty
            : sortedReservations.map((r) => (
                <SummaryRow
                  key={r.id}
                  label={teamName.get(r.teamId) ?? "?"}
                  meta={`${venueName.get(r.venueId) ?? "?"} · ${dayLabel(r.dayOfWeek)} ${hhmm(r.startTime)}`}
                />
              ))}
        </AccordionSection>
      </div>

      {blockers.length > 0 ? (
        <BlockerList blockers={blockers} className="mb-4" />
      ) : (
        <p className="text-sm text-success">Tout est prêt. Utilisez « Continuer vers la génération » en bas pour lancer.</p>
      )}
    </div>
  );
}
