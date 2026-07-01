import { useQueryClient } from "@tanstack/react-query";
import { AlertTriangle, ChevronDown, ChevronRight, Rocket } from "lucide-react";
import { type ReactNode, useState } from "react";
import { useNavigate } from "react-router-dom";

import { Button } from "@/shared/components/ui/button";
import { Card, CardContent } from "@/shared/components/ui/card";
import { Spinner } from "@/shared/components/ui/spinner";

import { useConstraintValidation, useLaunchGeneration, useVenueSlots, useWizardCoaches, useWizardConstraints, useWizardTeams, useWizardVenues } from "../queries";

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
      {open ? <div className="border-t border-border px-4 py-2 text-sm text-muted-foreground">{children}</div> : null}
    </div>
  );
}

export function RecapStep() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { data: teams = [] } = useWizardTeams();
  const { data: venues = [] } = useWizardVenues();
  const { data: slots = [] } = useVenueSlots();
  const { data: coaches = [] } = useWizardCoaches();
  const { data: constraints = [] } = useWizardConstraints();
  const { data: validation } = useConstraintValidation(true);
  const launch = useLaunchGeneration();

  const salaried = coaches.filter((c) => c.isEmployee).length;
  const hardConstraints = constraints.filter((c) => c.ruleType === "HARD").length;
  const venuesWithSlot = new Set(slots.map((s) => s.venueId));
  const emptyVenues = venues.filter((v) => !venuesWithSlot.has(v.id));

  const blockers: string[] = [];
  if (0 === teams.length) {
    blockers.push("Aucune équipe.");
  }
  if (0 === coaches.length) {
    blockers.push("Aucun coach.");
  }
  if (0 === venues.length) {
    blockers.push("Aucun gymnase.");
  }
  if (emptyVenues.length > 0) {
    blockers.push(`Gymnase(s) sans créneau : ${emptyVenues.map((v) => v.name).join(", ")}.`);
  }
  if (validation && !validation.valid) {
    for (const messages of Object.values(validation.errors)) {
      blockers.push(...messages);
    }
    for (const conflict of validation.conflicts) {
      blockers.push(conflict.reason);
    }
  }

  const canGenerate = 0 === blockers.length && !launch.isPending;

  const generate = async () => {
    await launch.mutateAsync(`Planning ${new Date().toLocaleDateString("fr-FR")}`);
    // Onboarding just completed server-side — refetch /me so the guard lets us
    // leave the wizard, then land on the work loop where the plan is generating.
    await queryClient.invalidateQueries({ queryKey: ["me"] });
    navigate("/");
  };

  return (
    <div>
      <h2 className="mb-1 text-xl font-semibold">Récapitulatif</h2>
      <p className="mb-4 text-sm text-muted-foreground">Cartographie de votre club avant génération.</p>

      <div className="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
        <Counter label="Équipes" value={teams.length} />
        <Counter label="Gymnases" value={venues.length} />
        <Counter label="Coachs" value={coaches.length} sub={`dont ${salaried} salarié(s)`} />
        <Counter label="Contraintes dures" value={hardConstraints} />
      </div>

      <div className="mb-4 flex flex-col gap-1.5">
        <Section title="Équipes" count={teams.length}>{teams.map((t) => t.name).join(", ") || "—"}</Section>
        <Section title="Gymnases" count={venues.length}>{venues.map((v) => v.name).join(", ") || "—"}</Section>
        <Section title="Coachs" count={coaches.length}>{coaches.map((c) => `${c.firstName} ${c.lastName}`.trim()).join(", ") || "—"}</Section>
        <Section title="Contraintes" count={constraints.length}>{constraints.map((c) => c.name).join(" · ") || "—"}</Section>
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
        <p className="mb-4 text-sm text-emerald-500">Tout est prêt. Vous pouvez générer le planning.</p>
      )}

      <div className="flex justify-end">
        <Button size="lg" disabled={!canGenerate} onClick={generate}>
          {launch.isPending ? <Spinner className="size-4" /> : <Rocket className="size-4" />}
          Générer le planning
        </Button>
      </div>
    </div>
  );
}
