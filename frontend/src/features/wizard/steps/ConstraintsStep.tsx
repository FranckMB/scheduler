import { Lock, Plus, Trash2 } from "lucide-react";
import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { Input } from "@/shared/components/ui/input";
import { Select } from "@/shared/components/ui/select";
import { cn } from "@/shared/lib/utils";

import type { Constraint, ConstraintFamily, ConstraintPayload, ConstraintRuleType, Team, Venue } from "../api";
import { DAYS, dayLabel, hhmm } from "../lib/days";
import { useCreateConstraint, useDeleteConstraint, useVenueSlots, useWizardCoaches, useWizardConstraints, useWizardTeamTags, useWizardTeams, useWizardVenues } from "../queries";
import { useWizardStore } from "../store";

const FAMILIES: { key: ConstraintFamily; label: string }[] = [
  { key: "TIME", label: "Horaires" },
  { key: "DAY", label: "Jours" },
  { key: "FACILITY", label: "Gymnase" },
  { key: "COACH_AVAILABILITY", label: "Dispo coach" },
];

const RULES: ConstraintRuleType[] = ["PREFERRED", "HARD", "BONUS", "LOCK"];

/** Libellés gestionnaire (jamais l'enum brut à l'écran). */
const RULE_LABEL: Record<ConstraintRuleType, string> = {
  HARD: "Obligatoire",
  PREFERRED: "Préféré",
  BONUS: "Bonus",
  LOCK: "Verrouillé",
};

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

/** Reserve an existing availability slot for a team (applied as a HARD lock at generation). */
function ReservationPanel({ teams, venues }: { teams: Team[]; venues: Venue[] }) {
  const { data: slots = [] } = useVenueSlots();
  const { reservations, addReservation, removeReservation } = useWizardStore();
  const [teamId, setTeamId] = useState("");
  const [slotId, setSlotId] = useState("");

  const teamName = new Map(teams.map((t) => [t.id, t.name]));
  const venueName = new Map(venues.map((v) => [v.id, v.name]));
  const slotLabel = (venueId: string, dayOfWeek: number, startTime: string) => `${venueName.get(venueId) ?? "?"} · ${dayLabel(dayOfWeek)} ${hhmm(startTime)}`;

  const add = () => {
    const team = teamId || teams[0]?.id;
    const slot = slots.find((s) => s.id === slotId);
    if (undefined === team || undefined === slot) {
      return;
    }
    addReservation({ teamId: team, venueId: slot.venueId, dayOfWeek: slot.dayOfWeek, startTime: slot.startTime, durationMinutes: slot.durationMinutes });
  };

  return (
    <div>
      <p className="mb-3 text-xs text-muted-foreground">Fixez une équipe sur un créneau précis : le solveur devra l'y placer (verrou). Appliqué au lancement de la génération.</p>
      <div className="mb-4 flex flex-wrap items-end gap-2 rounded-lg border border-border bg-card p-3">
        <Select aria-label="Équipe" className="h-8 w-44" value={teamId || teams[0]?.id || ""} onChange={(e) => setTeamId(e.target.value)}>
          {teams.map((t) => (
            <option key={t.id} value={t.id}>
              {t.name}
            </option>
          ))}
        </Select>
        <span className="text-xs text-muted-foreground">sur</span>
        <Select aria-label="Créneau" className="h-8 w-64" value={slotId} onChange={(e) => setSlotId(e.target.value)}>
          <option value="">— créneau —</option>
          {slots.map((s) => (
            <option key={s.id} value={s.id}>
              {slotLabel(s.venueId, s.dayOfWeek, s.startTime)}
            </option>
          ))}
        </Select>
        <Button size="sm" onClick={add} disabled={"" === slotId || 0 === teams.length}>
          <Lock className="size-4" />
          Réserver
        </Button>
      </div>

      {0 === reservations.length ? (
        <p className="text-sm text-muted-foreground">Aucune réservation. Le solveur reste libre de placer les équipes.</p>
      ) : (
        <ul className="flex flex-col gap-1">
          {reservations.map((r) => (
            <li key={r.id} className="flex items-center gap-2 rounded-md border border-border bg-card px-3 py-1.5 text-sm">
              <Lock className="size-3.5 text-accent" />
              <span className="flex-1">
                {teamName.get(r.teamId) ?? "?"} → {slotLabel(r.venueId, r.dayOfWeek, r.startTime)}
              </span>
              <button type="button" aria-label="Retirer" className="text-muted-foreground hover:text-destructive" onClick={() => removeReservation(r.id)}>
                <Trash2 className="size-4" />
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

export function ConstraintsStep() {
  const { data: constraints = [] } = useWizardConstraints();
  const { data: teams = [] } = useWizardTeams();
  const { data: tags = [] } = useWizardTeamTags();
  const { data: coaches = [] } = useWizardCoaches();
  const { data: venues = [] } = useWizardVenues();
  const create = useCreateConstraint();
  const del = useDeleteConstraint();

  const [family, setFamily] = useState<ConstraintFamily>("TIME");
  const [mode, setMode] = useState<"constraint" | "reserve">("constraint");
  const [ruleType, setRuleType] = useState<ConstraintRuleType>("PREFERRED");
  // target: "" = toutes les équipes (CLUB) · "tag:NAME" = un groupe · sinon un id d'équipe (TEAM)
  const [target, setTarget] = useState("");
  const [minTime, setMinTime] = useState("");
  const [maxTime, setMaxTime] = useState("");
  const [days, setDays] = useState<Set<number>>(new Set());
  const [venueMode, setVenueMode] = useState<"preferred" | "forbidden">("preferred");
  const [venueId, setVenueId] = useState("");
  const [coachId, setCoachId] = useState("");
  const [pendingDelete, setPendingDelete] = useState<Constraint | null>(null);

  const teamName = new Map(teams.map((t) => [t.id, t.name]));
  const coachName = new Map(coaches.map((c) => [c.id, `${c.firstName} ${c.lastName}`.trim()]));
  const venueName = new Map(venues.map((v) => [v.id, v.name]));

  // Resolve the target into scope + optional tag (CLUB+targetTag → N team constraints backend-side).
  const isTag = target.startsWith("tag:");
  const tagName = isTag ? target.slice(4) : "";
  const teamTargetId = !isTag && "" !== target ? target : "";
  const scope: "CLUB" | "TEAM" = "" !== teamTargetId ? "TEAM" : "CLUB";
  const scopeTargetId = "" !== teamTargetId ? teamTargetId : null;
  const tagConfig: Record<string, string> = isTag ? { targetTag: tagName } : {};
  const who = "" !== teamTargetId ? (teamName.get(teamTargetId) ?? "?") : isTag ? `Groupe ${tagName}` : "Toutes les équipes";
  const toggleDay = (n: number) => setDays((prev) => (prev.has(n) ? new Set([...prev].filter((x) => x !== n)) : new Set([...prev, n])));
  const dayNames = (set: Set<number>) => [...set].sort((a, b) => a - b).map(dayLabel).join(", ");

  function build(): ConstraintPayload | null {
    if ("TIME" === family) {
      if ("" === minTime && "" === maxTime) {
        return null;
      }
      const config: Record<string, string> = { ...tagConfig };
      if ("" !== minTime) {
        config.minStartTime = minTime;
      }
      if ("" !== maxTime) {
        config.maxStartTime = maxTime;
      }
      const parts = [maxTime && `pas après ${maxTime}`, minTime && `pas avant ${minTime}`].filter(Boolean).join(", ");
      return { name: `${who} · ${parts}`, scope, scopeTargetId, family, ruleType, config };
    }
    if ("DAY" === family) {
      if (0 === days.size) {
        return null;
      }
      return { name: `${who} · pas ${dayNames(days)}`, scope, scopeTargetId, family, ruleType, config: { ...tagConfig, forbiddenDays: [...days] } };
    }
    if ("FACILITY" === family) {
      if ("" === venueId) {
        return null;
      }
      const config = { ...tagConfig, ...("preferred" === venueMode ? { preferredVenueId: venueId } : { forbiddenVenueId: venueId }) };
      const verb = "preferred" === venueMode ? "préfère" : "évite";
      return { name: `${who} · ${verb} ${venueName.get(venueId)}`, scope, scopeTargetId, family, ruleType, config };
    }
    // COACH_AVAILABILITY
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
    <Select aria-label="Cible" title="Qui est concerné : tout le club, un groupe (tag), ou une équipe précise" className="h-8 w-48" value={target} onChange={(e) => setTarget(e.target.value)}>
      <option value="">Toutes les équipes</option>
      {tags.length > 0 ? (
        <optgroup label="Groupes">
          {tags.map((t) => (
            <option key={t.id} value={`tag:${t.name}`}>
              {t.name}
            </option>
          ))}
        </optgroup>
      ) : null}
      <optgroup label="Équipes">
        {teams.map((t) => (
          <option key={t.id} value={t.id}>
            {t.name}
          </option>
        ))}
      </optgroup>
    </Select>
  );

  const list = constraints.filter((c) => c.family === family);

  return (
    <div>
      <p className="mb-4 text-sm text-muted-foreground">
        Le solveur gère déjà les règles de base (pas 2 équipes au même endroit, coach jamais en double…). Ici, ajoutez vos préférences et restrictions : ciblez
        <strong> tout le club</strong>, un <strong>groupe</strong> (ex. les jeunes → pas de créneau après 19h50) ou une <strong>équipe</strong> précise. La capacité d'un gymnase se règle
        sur l'écran <strong>Gymnases</strong> (1 ou 2 équipes par créneau).
      </p>

      {/* Family + reservation tabs */}
      <div className="mb-3 flex flex-wrap gap-1 border-b border-border">
        {FAMILIES.map((f) => {
          const active = "constraint" === mode && f.key === family;
          return (
            <button
              key={f.key}
              type="button"
              onClick={() => {
                setMode("constraint");
                setFamily(f.key);
              }}
              className={cn("-mb-px border-b-2 px-3 py-1.5 text-sm", active ? "border-accent font-medium text-foreground" : "border-transparent text-muted-foreground hover:text-foreground")}
            >
              {f.label}
            </button>
          );
        })}
        <button
          type="button"
          onClick={() => setMode("reserve")}
          className={cn(
            "-mb-px flex items-center gap-1 border-b-2 px-3 py-1.5 text-sm",
            "reserve" === mode ? "border-accent font-medium text-foreground" : "border-transparent text-muted-foreground hover:text-foreground",
          )}
        >
          <Lock className="size-3.5" />
          Réserver
        </button>
      </div>

      {"reserve" === mode ? (
        <ReservationPanel teams={teams} venues={venues} />
      ) : (
        <>
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

        {"DAY" === family && (
          <>
            <span className="text-xs text-muted-foreground">à éviter :</span>
            <DayPicker days={days} toggle={toggleDay} />
          </>
        )}

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

        <Select aria-label="Règle" className="h-8 w-28" value={ruleType} onChange={(e) => setRuleType(e.target.value as ConstraintRuleType)}>
          {RULES.map((r) => (
            <option key={r} value={r}>
              {RULE_LABEL[r]}
            </option>
          ))}
        </Select>
        <Button size="icon" className="ml-auto size-8" onClick={add} disabled={create.isPending} title="Ajouter la contrainte" aria-label="Ajouter la contrainte">
          <Plus className="size-4" />
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
              <span className="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">{RULE_LABEL[c.ruleType]}</span>
              <button type="button" aria-label="Supprimer" className="text-muted-foreground hover:text-destructive" onClick={() => setPendingDelete(c)}>
                <Trash2 className="size-4" />
              </button>
            </li>
          ))}
        </ul>
      )}
        </>
      )}

      <ConfirmDialog
        open={pendingDelete !== null}
        title="Supprimer cette contrainte ?"
        description={pendingDelete ? <>« {pendingDelete.name} » sera définitivement supprimée.</> : null}
        confirmLabel="Supprimer"
        onCancel={() => setPendingDelete(null)}
        onConfirm={() => {
          if (pendingDelete) {
            del.mutate(pendingDelete.id);
          }
          setPendingDelete(null);
        }}
      />
    </div>
  );
}
