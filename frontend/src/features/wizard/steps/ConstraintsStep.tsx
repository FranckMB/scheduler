import { Plus, Trash2 } from "lucide-react";
import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { Input } from "@/shared/components/ui/input";
import { Select } from "@/shared/components/ui/select";
import { cn } from "@/shared/lib/utils";

import type { Constraint, ConstraintFamily, ConstraintPayload, ConstraintRuleType } from "../api";
import { DAYS, dayLabel } from "../lib/days";
import { useCreateConstraint, useDeleteConstraint, useWizardCoaches, useWizardConstraints, useWizardTeams, useWizardVenues } from "../queries";

const FAMILIES: { key: ConstraintFamily; label: string }[] = [
  { key: "TIME", label: "Horaires" },
  { key: "DAY", label: "Jours" },
  { key: "FACILITY", label: "Gymnase" },
  { key: "COACH_AVAILABILITY", label: "Dispo coach" },
  { key: "FACILITY_CAPACITY", label: "Capacité" },
];

const RULES: ConstraintRuleType[] = ["PREFERRED", "HARD", "BONUS", "LOCK"];

function DayPicker({ days, toggle }: { days: Set<number>; toggle: (n: number) => void }) {
  return (
    <div className="flex flex-wrap gap-1">
      {DAYS.map((d) => (
        <button
          key={d.n}
          type="button"
          onClick={() => toggle(d.n)}
          className={cn("rounded-md border px-2 py-1 text-xs", days.has(d.n) ? "border-accent bg-accent text-accent-foreground" : "border-border text-muted-foreground")}
        >
          {d.label}
        </button>
      ))}
    </div>
  );
}

export function ConstraintsStep() {
  const { data: constraints = [] } = useWizardConstraints();
  const { data: teams = [] } = useWizardTeams();
  const { data: coaches = [] } = useWizardCoaches();
  const { data: venues = [] } = useWizardVenues();
  const create = useCreateConstraint();
  const del = useDeleteConstraint();

  const [family, setFamily] = useState<ConstraintFamily>("TIME");
  const [ruleType, setRuleType] = useState<ConstraintRuleType>("PREFERRED");
  const [teamId, setTeamId] = useState(""); // "" = toutes les équipes (CLUB)
  const [minTime, setMinTime] = useState("");
  const [maxTime, setMaxTime] = useState("");
  const [days, setDays] = useState<Set<number>>(new Set());
  const [venueMode, setVenueMode] = useState<"preferred" | "forbidden">("preferred");
  const [venueId, setVenueId] = useState("");
  const [coachId, setCoachId] = useState("");
  const [maxTeams, setMaxTeams] = useState("2");

  const teamName = new Map(teams.map((t) => [t.id, t.name]));
  const coachName = new Map(coaches.map((c) => [c.id, `${c.firstName} ${c.lastName}`.trim()]));
  const venueName = new Map(venues.map((v) => [v.id, v.name]));
  const who = "" === teamId ? "Toutes les équipes" : (teamName.get(teamId) ?? "?");
  const toggleDay = (n: number) => setDays((prev) => (prev.has(n) ? new Set([...prev].filter((x) => x !== n)) : new Set([...prev, n])));
  const dayNames = (set: Set<number>) => [...set].sort((a, b) => a - b).map(dayLabel).join(", ");

  function build(): ConstraintPayload | null {
    if ("TIME" === family) {
      if ("" === minTime && "" === maxTime) {
        return null;
      }
      const config: Record<string, string> = {};
      if ("" !== minTime) {
        config.minStartTime = minTime;
      }
      if ("" !== maxTime) {
        config.maxStartTime = maxTime;
      }
      const parts = [maxTime && `pas après ${maxTime}`, minTime && `pas avant ${minTime}`].filter(Boolean).join(", ");
      return { name: `${who} · ${parts}`, scope: teamId ? "TEAM" : "CLUB", scopeTargetId: teamId || null, family, ruleType, config };
    }
    if ("DAY" === family) {
      if (0 === days.size) {
        return null;
      }
      return { name: `${who} · pas ${dayNames(days)}`, scope: teamId ? "TEAM" : "CLUB", scopeTargetId: teamId || null, family, ruleType, config: { forbiddenDays: [...days] } };
    }
    if ("FACILITY" === family) {
      if ("" === venueId) {
        return null;
      }
      const config = "preferred" === venueMode ? { preferredVenueId: venueId } : { forbiddenVenueId: venueId };
      const verb = "preferred" === venueMode ? "préfère" : "évite";
      return { name: `${who} · ${verb} ${venueName.get(venueId)}`, scope: teamId ? "TEAM" : "CLUB", scopeTargetId: teamId || null, family, ruleType, config };
    }
    if ("COACH_AVAILABILITY" === family) {
      if ("" === coachId || 0 === days.size) {
        return null;
      }
      return {
        name: `${coachName.get(coachId)} · indispo ${dayNames(days)}`,
        scope: "COACH",
        scopeTargetId: coachId,
        family,
        ruleType,
        config: { coachId, unavailableDays: [...days] },
      };
    }
    // FACILITY_CAPACITY
    if ("" === venueId) {
      return null;
    }
    return {
      name: `${venueName.get(venueId)} · max ${maxTeams} équipes`,
      scope: "FACILITY",
      scopeTargetId: venueId,
      family,
      ruleType,
      config: { venueId, maxTeams: Number(maxTeams) },
    };
  }

  const add = () => {
    const payload = build();
    if (null === payload) {
      return;
    }
    create.mutate(payload);
    setMinTime("");
    setMaxTime("");
    setDays(new Set());
  };

  const teamPicker = (
    <Select aria-label="Équipe" className="h-8 w-44" value={teamId} onChange={(e) => setTeamId(e.target.value)}>
      <option value="">Toutes les équipes</option>
      {teams.map((t) => (
        <option key={t.id} value={t.id}>
          {t.name}
        </option>
      ))}
    </Select>
  );

  const list = constraints.filter((c) => c.family === family);

  return (
    <div>
      <h2 className="mb-1 text-xl font-semibold">Contraintes</h2>
      <p className="mb-4 text-sm text-muted-foreground">
        Le solveur gère déjà les règles de base (pas 2 équipes au même endroit, coach jamais en double…). Ici, ajoutez seulement vos préférences et restrictions explicites.
      </p>

      {/* Family tabs */}
      <div className="mb-3 flex flex-wrap gap-1 border-b border-border">
        {FAMILIES.map((f) => (
          <button
            key={f.key}
            type="button"
            onClick={() => setFamily(f.key)}
            className={cn("-mb-px border-b-2 px-3 py-1.5 text-sm", f.key === family ? "border-accent font-medium text-foreground" : "border-transparent text-muted-foreground hover:text-foreground")}
          >
            {f.label}
          </button>
        ))}
      </div>

      {/* Per-family add form */}
      <div className="mb-4 flex flex-wrap items-end gap-2 rounded-lg border border-border bg-card p-3">
        {("TIME" === family || "DAY" === family || "FACILITY" === family) && teamPicker}

        {"TIME" === family && (
          <>
            <label className="text-xs text-muted-foreground">
              Pas avant
              <Input aria-label="Pas avant" type="time" className="mt-0.5 h-8 w-28" value={minTime} onChange={(e) => setMinTime(e.target.value)} />
            </label>
            <label className="text-xs text-muted-foreground">
              Pas après
              <Input aria-label="Pas après" type="time" className="mt-0.5 h-8 w-28" value={maxTime} onChange={(e) => setMaxTime(e.target.value)} />
            </label>
          </>
        )}

        {"DAY" === family && <DayPicker days={days} toggle={toggleDay} />}

        {"FACILITY" === family && (
          <>
            <Select aria-label="Préférence" className="h-8 w-24" value={venueMode} onChange={(e) => setVenueMode(e.target.value as "preferred" | "forbidden")}>
              <option value="preferred">préfère</option>
              <option value="forbidden">évite</option>
            </Select>
            <Select aria-label="Gymnase" className="h-8 w-44" value={venueId} onChange={(e) => setVenueId(e.target.value)}>
              <option value="">— gymnase —</option>
              {venues.map((v) => (
                <option key={v.id} value={v.id}>
                  {v.name}
                </option>
              ))}
            </Select>
          </>
        )}

        {"COACH_AVAILABILITY" === family && (
          <>
            <Select aria-label="Coach" className="h-8 w-44" value={coachId} onChange={(e) => setCoachId(e.target.value)}>
              <option value="">— coach —</option>
              {coaches.map((c) => (
                <option key={c.id} value={c.id}>
                  {coachName.get(c.id)}
                </option>
              ))}
            </Select>
            <span className="text-xs text-muted-foreground">indispo</span>
            <DayPicker days={days} toggle={toggleDay} />
          </>
        )}

        {"FACILITY_CAPACITY" === family && (
          <>
            <Select aria-label="Gymnase" className="h-8 w-44" value={venueId} onChange={(e) => setVenueId(e.target.value)}>
              <option value="">— gymnase —</option>
              {venues.map((v) => (
                <option key={v.id} value={v.id}>
                  {v.name}
                </option>
              ))}
            </Select>
            <label className="text-xs text-muted-foreground">
              Max équipes
              <Input aria-label="Max équipes" type="number" min={1} max={2} className="mt-0.5 h-8 w-16" value={maxTeams} onChange={(e) => setMaxTeams(e.target.value)} />
            </label>
          </>
        )}

        <Select aria-label="Règle" className="h-8 w-28" value={ruleType} onChange={(e) => setRuleType(e.target.value as ConstraintRuleType)}>
          {RULES.map((r) => (
            <option key={r} value={r}>
              {r}
            </option>
          ))}
        </Select>
        <Button size="sm" onClick={add} disabled={create.isPending}>
          <Plus className="size-4" />
          Ajouter
        </Button>
      </div>

      {/* List for the active family */}
      {0 === list.length ? (
        <p className="text-sm text-muted-foreground">Aucune contrainte dans cette famille.</p>
      ) : (
        <ul className="flex flex-col gap-1">
          {list.map((c: Constraint) => (
            <li key={c.id} className="flex items-center gap-2 rounded-md border border-border bg-card px-3 py-1.5 text-sm">
              <span className="flex-1">{c.name}</span>
              <span className="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">{c.ruleType}</span>
              <button type="button" aria-label="Supprimer" className="text-muted-foreground hover:text-destructive" onClick={() => del.mutate(c.id)}>
                <Trash2 className="size-4" />
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
