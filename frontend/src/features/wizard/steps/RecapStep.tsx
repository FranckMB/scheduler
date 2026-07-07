import { AlertTriangle } from "lucide-react";
import type { ReactNode } from "react";

import { AccordionSection } from "@/shared/components/ui/accordion";
import { Card, CardContent } from "@/shared/components/ui/card";

import type { TeamLevel } from "../api";
import { orderedTeams } from "../lib/ranking";
import { useStepValidation } from "../lib/useStepValidation";
import { useVenueSlots, useWizardCoachPlayers, useWizardCoaches, useWizardConstraints, useWizardTeamCoaches, useWizardTeams, useWizardVenues } from "../queries";
import { useWizardStore } from "../store";

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

/** Section header: label + a count badge, fed to the shared AccordionSection. */
function sectionTitle(label: string, count: number): ReactNode {
  return (
    <span className="flex flex-1 items-center gap-2">
      <span>{label}</span>
      <span className="rounded-full bg-muted px-2 text-xs font-normal text-muted-foreground">{count}</span>
    </span>
  );
}

/** One item per row — readable list, not a comma-joined blob. */
function ItemRow({ label, meta }: { label: ReactNode; meta?: ReactNode }) {
  return (
    <div className="flex items-center justify-between gap-4 border-b border-border/60 py-1.5 text-sm last:border-0">
      <span className="text-foreground">{label}</span>
      {meta ? <span className="shrink-0 text-xs text-muted-foreground">{meta}</span> : null}
    </div>
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
  // Blockers live in useStepValidation("recap") so the footer "Continuer vers la
  // génération" button is gated by the same rules (single source of truth).
  const { errors: blockers } = useStepValidation("recap");

  const salaried = coaches.filter((c) => c.isEmployee).length;
  const coachPlayerIds = new Set(coachPlayers.filter((cp) => cp.isActive).map((cp) => cp.coachId));
  const hardConstraints = constraints.filter((c) => c.ruleType === "HARD").length;
  const slotsByVenue = new Map<string, number>();
  for (const s of slots) {
    slotsByVenue.set(s.venueId, (slotsByVenue.get(s.venueId) ?? 0) + 1);
  }

  const teamName = new Map(teams.map((t) => [t.id, t.name]));
  // A team's main coach (fallback: its first linked coach) — shown inline in italic.
  const mainCoachName = (teamId: string): string | null => {
    const links = teamCoaches.filter((tc) => tc.teamId === teamId);
    const link = links.find((tc) => "MAIN" === tc.role) ?? links[0];
    const coach = link ? coaches.find((c) => c.id === link.coachId) : undefined;
    return coach ? `${coach.firstName} ${coach.lastName}`.trim() : null;
  };
  // Teams a coach handles, by name (for "Maxime (SM1)" / "Emerick (SF1, U15F1)").
  const coachTeamNames = (coachId: string): string[] =>
    teamCoaches.filter((tc) => tc.coachId === coachId).map((tc) => teamName.get(tc.teamId) ?? "").filter((n) => "" !== n);

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
        <AccordionSection title={sectionTitle("Équipes", teams.length)}>
          {0 === teams.length
            ? empty
            : orderedTeams(teams).map(({ team: t }) => {
                const coach = mainCoachName(t.id);
                return (
                  <ItemRow
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
              })}
        </AccordionSection>
        <AccordionSection title={sectionTitle("Gymnases", venues.length)}>
          {0 === venues.length
            ? empty
            : venues.map((v) => (
                <ItemRow
                  key={v.id}
                  label={
                    <span className="flex items-center gap-2">
                      <span className="size-3 shrink-0 rounded-full border border-border" style={{ backgroundColor: v.color ?? "transparent" }} />
                      {v.name}
                    </span>
                  }
                  meta={`${slotsByVenue.get(v.id) ?? 0} créneau(x)`}
                />
              ))}
        </AccordionSection>
        <AccordionSection title={sectionTitle("Coachs", coaches.length)}>
          {0 === coaches.length
            ? empty
            : coaches.map((c) => {
                const teamsOf = coachTeamNames(c.id);
                return (
                  <ItemRow
                    key={c.id}
                    label={`${c.firstName}${teamsOf.length > 0 ? ` (${teamsOf.join(", ")})` : ""}`}
                    meta={[c.isEmployee ? "salarié" : null, coachPlayerIds.has(c.id) ? "coach-joueur" : null].filter(Boolean).join(" · ") || undefined}
                  />
                );
              })}
        </AccordionSection>
        <AccordionSection title={sectionTitle("Contraintes", constraints.length)}>
          {0 === constraints.length ? empty : constraints.map((c) => <ItemRow key={c.id} label={c.name} meta={c.ruleType} />)}
        </AccordionSection>
      </div>

      {blockers.length > 0 ? (
        <div className="mb-4 rounded-lg border border-destructive/50 bg-destructive/5 p-3">
          <div className="mb-1 flex items-center gap-2 text-sm font-medium text-destructive">
            <AlertTriangle className="size-4" />À corriger avant de générer
          </div>
          <ul className="list-inside list-disc text-sm text-destructive">
            {blockers.map((b) => (
              <li key={b}>{b}</li>
            ))}
          </ul>
        </div>
      ) : (
        <p className="text-sm text-emerald-500">Tout est prêt. Utilisez « Continuer vers la génération » en bas pour lancer.</p>
      )}
    </div>
  );
}
