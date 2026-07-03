import { AlertTriangle, ChevronDown, ChevronRight } from "lucide-react";
import { type ReactNode, useState } from "react";

import { Card, CardContent } from "@/shared/components/ui/card";

import { useStepValidation } from "../lib/useStepValidation";
import { useVenueSlots, useWizardCoachPlayers, useWizardCoaches, useWizardConstraints, useWizardTeams, useWizardVenues } from "../queries";

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

function Section({ title, count, children }: { title: string; count: number; children: ReactNode }) {
  const [open, setOpen] = useState(false);
  return (
    <div className="rounded-md border border-border">
      <button type="button" onClick={() => setOpen((o) => !o)} className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-muted">
        {open ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />}
        <span className="flex-1 font-medium">{title}</span>
        <span className="rounded-full bg-muted px-2 text-xs text-muted-foreground">{count}</span>
      </button>
      {open ? <div className="max-h-64 overflow-y-auto border-t border-border px-3 py-1">{children}</div> : null}
    </div>
  );
}

/** One item per row — readable list, not a comma-joined blob. */
function ItemRow({ label, meta }: { label: string; meta?: ReactNode }) {
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
  const { data: constraints = [] } = useWizardConstraints();
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

  return (
    <div>
      <h2 className="mb-1 text-xl font-semibold">Récapitulatif</h2>
      <p className="mb-4 text-sm text-muted-foreground">Cartographie de votre club avant génération.</p>

      <div className="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
        <Counter label="Équipes" value={teams.length} />
        <Counter label="Gymnases" value={venues.length} />
        <Counter label="Coachs" value={coaches.length} sub={`dont ${salaried} salarié(s) · ${coachPlayerIds.size} coach-joueur(s)`} />
        <Counter label="Contraintes dures" value={hardConstraints} />
      </div>

      <div className="mb-4 flex flex-col gap-1.5">
        <Section title="Équipes" count={teams.length}>
          {0 === teams.length
            ? empty
            : teams.map((t) => <ItemRow key={t.id} label={t.name} meta={`${t.sessionsPerWeek} séance(s)/sem`} />)}
        </Section>
        <Section title="Gymnases" count={venues.length}>
          {0 === venues.length ? empty : venues.map((v) => <ItemRow key={v.id} label={v.name} meta={`${slotsByVenue.get(v.id) ?? 0} créneau(x)`} />)}
        </Section>
        <Section title="Coachs" count={coaches.length}>
          {0 === coaches.length
            ? empty
            : coaches.map((c) => (
                <ItemRow
                  key={c.id}
                  label={`${c.firstName} ${c.lastName}`.trim()}
                  meta={[c.isEmployee ? "salarié" : null, coachPlayerIds.has(c.id) ? "coach-joueur" : null].filter(Boolean).join(" · ") || undefined}
                />
              ))}
        </Section>
        <Section title="Contraintes" count={constraints.length}>
          {0 === constraints.length ? empty : constraints.map((c) => <ItemRow key={c.id} label={c.name} meta={c.ruleType} />)}
        </Section>
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
