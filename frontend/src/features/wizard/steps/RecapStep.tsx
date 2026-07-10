import { Trash2 } from "lucide-react";
import { AccordionSection } from "@/shared/components/ui/accordion";
import { Card, CardContent } from "@/shared/components/ui/card";

import type { TeamLevel } from "../api";
import { coachMeta, orderedCoaches } from "../lib/ranking";
import { coachTeamNames, countSlotsByVenue } from "../lib/summary";
import { useStepValidation } from "../lib/useStepValidation";
import { BlockerList } from "./BlockerList";
import { VenueSwatch } from "@/shared/components/ui/venue-swatch";

import { SectionCountTitle, SummaryRow, TeamTierAccordion } from "./StructureSummary";
import { useDeleteReservation, usePriorityTiers, useReservations, useVenueSlots, useWizardCoachPlayers, useWizardCoaches, useWizardConstraints, useWizardTeamCoaches, useWizardTeams, useWizardVenues } from "../queries";
import { useWizardStore } from "../store";
import { groupTeamsByTier } from "@/shared/lib/teamTiers";
import { dayLabel, hhmm } from "../lib/days";

// Manager-facing labels for the FFBB play levels (mirrors the teams step).
const LEVEL_LABEL: Record<TeamLevel, string> = {
  ELITE: "Élite",
  NATIONAL: "National",
  REGIONAL: "Régional",
  PRE_REGION: "Pré-région",
  DEPARTEMENTAL: "Départemental",
  HONNEUR: "Honneur",
  PROMOTION: "Promotion",
  LOISIR_ADULTE: "Loisir adulte",
  LOISIR_JEUNE: "Loisir jeune",
};

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
  const { data: reservations = [] } = useReservations(periodEntryId);
  const { data: tiers = [] } = usePriorityTiers();
  const deleteReservation = useDeleteReservation();
  // Blockers live in useStepValidation("recap") so the footer "Continuer vers la
  // génération" button is gated by the same rules (single source of truth).
  const { errors: blockers } = useStepValidation("recap");

  const salaried = coaches.filter((c) => c.isEmployee).length;
  const coachPlayerIds = new Set(coachPlayers.filter((cp) => cp.isActive).map((cp) => cp.coachId));
  const hardConstraints = constraints.filter((c) => c.ruleType === "HARD").length;
  const slotsByVenue = countSlotsByVenue(slots);

  const teamName = new Map(teams.map((t) => [t.id, t.name]));
  const venueName = new Map(venues.map((v) => [v.id, v.name]));
  // Reservations ordered by team rank (fanion S → A → B → C → D), then day + time.
  const teamRank = new Map(groupTeamsByTier(teams, tiers).flatMap((g) => g.teams).map((t, i) => [t.id, i]));
  const rankOf = (id: string): number => teamRank.get(id) ?? Number.MAX_SAFE_INTEGER;
  const sortedReservations = [...reservations].sort((a, b) => rankOf(a.teamId) - rankOf(b.teamId) || a.dayOfWeek - b.dayOfWeek || hhmm(a.startTime).localeCompare(hhmm(b.startTime)));
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
              defaultOpen={false}
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
                      <VenueSwatch color={v.color} className="size-3 border border-border" />
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
            : orderedCoaches(coaches, coachPlayerIds).map(({ coach: c }) => {
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
        </AccordionSection>
        <AccordionSection title={<SectionCountTitle label="Contraintes" count={constraints.length} />}>
          {0 === constraints.length ? empty : constraints.map((c) => <SummaryRow key={c.id} label={c.name} meta={c.ruleType} />)}
        </AccordionSection>
        <AccordionSection title={<SectionCountTitle label="Réservations" count={reservations.length} />}>
          {0 === sortedReservations.length
            ? empty
            : sortedReservations.map((r) => (
                <SummaryRow
                  key={r.id}
                  label={teamName.get(r.teamId) ?? "?"}
                  meta={
                    <span className="flex items-center gap-2">
                      {`${venueName.get(r.venueId) ?? "?"} · ${dayLabel(r.dayOfWeek)} ${hhmm(r.startTime)}`}
                      {/* The recap is the only place that lists EVERY reservation, so it must be
                          able to remove one whose availability slot no longer exists (orphan). */}
                      <button type="button" aria-label={`Retirer la réservation de ${teamName.get(r.teamId) ?? "l'équipe"}`} className="text-muted-foreground hover:text-destructive" onClick={() => deleteReservation.mutate(r.id)}>
                        <Trash2 className="size-4" />
                      </button>
                    </span>
                  }
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
