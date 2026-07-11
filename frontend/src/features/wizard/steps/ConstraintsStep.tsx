import { Check, Lock, Pencil, Plus, Trash2 } from "lucide-react";
import { useMemo, useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { Input } from "@/shared/components/ui/input";
import { Select } from "@/shared/components/ui/select";
import { compareTeamsByRank, groupTeamsByTier, tierGroupLabel } from "@/shared/lib/teamTiers";

import { groupCoaches } from "../lib/coaches";
import { cn } from "@/shared/lib/utils";

import type { Constraint, ConstraintFamily, ConstraintPayload, ConstraintRuleType } from "../api";
import { DAYS, dayLabel } from "../lib/days";
import { useCreateConstraint, useDeleteConstraint, usePriorityTiers, useUpdateConstraint, useWizardCoachPlayers, useWizardCoaches, useWizardConstraints, useWizardTeamTagAssignments, useWizardTeamTags, useWizardTeams, useWizardVenues } from "../queries";
import { useWizardStore } from "../store";
import { ReservationPanel } from "./ReservationPanel";

const FAMILIES: { key: ConstraintFamily; label: string }[] = [
  { key: "TIME", label: "Horaires" },
  { key: "DAY", label: "Jours" },
  { key: "FACILITY", label: "Gymnase" },
  { key: "COACH_AVAILABILITY", label: "Dispo coach" },
];

// BONUS removed from the offer (audit ENG-12): it never had a distinct
// semantic anywhere (no weight, no engine branch) — legacy rows are honored as
// PREFERRED by the engine. RULE_LABEL keeps it for displaying existing rows.
const RULES: ConstraintRuleType[] = ["PREFERRED", "HARD", "LOCK"];

/** Libellés gestionnaire (jamais l'enum brut à l'écran). */
const RULE_LABEL: Record<ConstraintRuleType, string> = {
  HARD: "Obligatoire",
  PREFERRED: "Préféré",
  BONUS: "Bonus",
  LOCK: "Verrouillé",
};

/** Coerce a JSON config value (unknown) into a day-number array. */
const asNums = (v: unknown): number[] => (Array.isArray(v) ? v.map(Number).filter((n) => !Number.isNaN(n)) : []);

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
  const periodEntryId = useWizardStore((s) => (s.mode === "period" ? s.calendarEntryId : null));
  const { data: constraints = [] } = useWizardConstraints(periodEntryId);
  const { data: teams = [] } = useWizardTeams();
  const { data: tiers = [] } = usePriorityTiers();
  const { data: tags = [] } = useWizardTeamTags();
  const { data: tagAssignments = [] } = useWizardTeamTagAssignments();
  const { data: coaches = [] } = useWizardCoaches();
  const { data: coachPlayers = [] } = useWizardCoachPlayers();
  const { data: venues = [] } = useWizardVenues();
  const create = useCreateConstraint();
  const update = useUpdateConstraint();
  const del = useDeleteConstraint();

  const [family, setFamily] = useState<ConstraintFamily>("TIME");
  const [mode, setMode] = useState<"constraint" | "reserve">("constraint");
  const [ruleType, setRuleType] = useState<ConstraintRuleType>("PREFERRED");
  // target: "" = toutes les équipes (CLUB) · "tag:NAME" = un groupe · sinon un id d'équipe (TEAM)
  const [target, setTarget] = useState("");
  const [minTime, setMinTime] = useState("");
  const [maxTime, setMaxTime] = useState("");
  // "finir avant" = maxEndTime (l'engine calcule fin = début + durée du créneau).
  const [endTime, setEndTime] = useState("");
  const [days, setDays] = useState<Set<number>>(new Set());
  // "à éviter" (forbiddenDays) vs "uniquement" (allowedDays — whitelist : SEULS
  // ces jours sont permis, l'engine interdit le complément). NB : forcedDays de
  // l'engine ne veut dire QUE « au moins une séance ces jours-là » — pas ce qu'on
  // veut ici (audit ENG-16).
  const [dayMode, setDayMode] = useState<"forbidden" | "forced">("forbidden");
  // "préfère" (preferredVenueId) · "évite" (forbiddenVenueId) · "impose"
  // (forcedVenueId, dur) · "au moins N" (minAtVenueId + minAtVenueCount, dur).
  const [venueMode, setVenueMode] = useState<"preferred" | "forbidden" | "forced" | "min">("preferred");
  const [venueId, setVenueId] = useState("");
  // Compteur du mode "au moins N séances dans ce gymnase" (défaut 1, le cas courant).
  const [venueMinCount, setVenueMinCount] = useState(1);
  const [coachId, setCoachId] = useState("");
  // "indisponible" (unavailableDays, blacklist) vs "disponible uniquement"
  // (availableDays, whitelist — l'engine intersecte les whitelists d'un coach).
  const [coachMode, setCoachMode] = useState<"unavailable" | "available">("unavailable");
  const [pendingDelete, setPendingDelete] = useState<Constraint | null>(null);
  // id de la contrainte en cours d'édition (null = création) — réutilise le même formulaire.
  const [editingId, setEditingId] = useState<string | null>(null);

  const teamName = new Map(teams.map((t) => [t.id, t.name]));
  const coachName = new Map(coaches.map((c) => [c.id, `${c.firstName} ${c.lastName}`.trim()]));
  // Group the coach picker: Salariés, then Coachs-joueurs, then Bénévoles (batch item 1).
  const coachGroups = groupCoaches(coaches, coachPlayers);
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
      if ("" === minTime && "" === maxTime && "" === endTime) {
        return null;
      }
      const config: Record<string, string> = { ...tagConfig };
      if ("" !== minTime) {
        config.minStartTime = minTime;
      }
      if ("" !== maxTime) {
        config.maxStartTime = maxTime;
      }
      if ("" !== endTime) {
        config.maxEndTime = endTime;
      }
      const parts = [maxTime && `pas après ${maxTime}`, minTime && `pas avant ${minTime}`, endTime && `fini avant ${endTime}`].filter(Boolean).join(", ");
      // maxEndTime (fin de séance) n'existe que dur côté engine : le chemin soft
      // (preferredTime) ne lit que min/maxStartTime → une "Fini avant" préférée
      // serait un placebo. Dès qu'on impose une fin, la règle est obligatoire.
      const timeRule: ConstraintRuleType = "" !== endTime ? "HARD" : ruleType;
      return { name: `${who} · ${parts}`, scope, scopeTargetId, family, ruleType: timeRule, config };
    }
    if ("DAY" === family) {
      if (0 === days.size) {
        return null;
      }
      if ("forced" === dayMode) {
        // "uniquement" = whitelist allowedDays (l'engine interdit tous les autres
        // jours) — PAS forcedDays qui n'impose qu'« au moins une séance » (ENG-16).
        return { name: `${who} · uniquement ${dayNames(days)}`, scope, scopeTargetId, family, ruleType: "HARD", config: { ...tagConfig, allowedDays: [...days] } };
      }
      return { name: `${who} · pas ${dayNames(days)}`, scope, scopeTargetId, family, ruleType, config: { ...tagConfig, forbiddenDays: [...days] } };
    }
    if ("FACILITY" === family) {
      if ("" === venueId) {
        return null;
      }
      if ("forced" === venueMode) {
        // "impose" = doit se dérouler dans ce gymnase (forcedVenueId), toujours dur.
        return { name: `${who} · impose ${venueName.get(venueId)}`, scope, scopeTargetId, family, ruleType: "HARD", config: { ...tagConfig, forcedVenueId: venueId } };
      }
      if ("min" === venueMode) {
        // "au moins N séances ici" = compte plancher (minAtVenueId + minAtVenueCount),
        // toujours dur. Le backend refuse N > séances/semaine de l'équipe avant génération.
        const count = Math.max(1, venueMinCount);
        return { name: `${who} · au moins ${count} à ${venueName.get(venueId)}`, scope, scopeTargetId, family, ruleType: "HARD", config: { ...tagConfig, minAtVenueId: venueId, minAtVenueCount: count } };
      }
      const config = { ...tagConfig, ...("preferred" === venueMode ? { preferredVenueId: venueId } : { forbiddenVenueId: venueId }) };
      const verb = "preferred" === venueMode ? "préfère" : "évite";
      return { name: `${who} · ${verb} ${venueName.get(venueId)}`, scope, scopeTargetId, family, ruleType, config };
    }
    // COACH_AVAILABILITY
    if ("" === coachId || 0 === days.size) {
      return null;
    }
    const coachDaysKey = "available" === coachMode ? "availableDays" : "unavailableDays";
    return {
      name: `${coachName.get(coachId)} · ${"available" === coachMode ? "dispo uniquement" : "indispo"} ${dayNames(days)}`,
      scope: "COACH",
      scopeTargetId: coachId,
      family,
      // Always hard: the engine enforces coach availability unconditionally.
      ruleType: "HARD",
      config: { coachId, [coachDaysKey]: [...days] },
    };
  }

  // Clears only the per-constraint value inputs, keeping the target/venue/rule
  // so several constraints for the same team can be added in a row (old add()).
  const clearInputs = () => {
    setMinTime("");
    setMaxTime("");
    setEndTime("");
    setDays(new Set());
  };

  // Full reset: also drops the target + exits edit mode (used after an edit or on cancel).
  const resetForm = () => {
    setEditingId(null);
    setTarget("");
    setDayMode("forbidden");
    setCoachMode("unavailable");
    setVenueMode("preferred");
    setVenueId("");
    setVenueMinCount(1);
    setCoachId("");
    setRuleType("PREFERRED");
    clearInputs();
  };

  const submit = () => {
    const payload = build();
    if (null === payload) {
      return;
    }
    if (null !== editingId) {
      // Edit: PUT the existing row, keep it active, then clear the whole form.
      update.mutate({ id: editingId, body: { ...payload, isActive: true } }, { onSuccess: resetForm });
      return;
    }
    // Create: keep the target/rule for rapid multi-add, clear only the values.
    // In period mode, attach the constraint to the entry → dated (excluded from base).
    create.mutate(periodEntryId ? { ...payload, calendarEntryId: periodEntryId } : payload, { onSuccess: clearInputs });
  };

  // Load an existing constraint into the shared form (reverse of build()): resolve
  // the target picker + per-family config back into the controlled inputs.
  const editConstraint = (c: Constraint) => {
    setMode("constraint");
    setFamily(c.family);
    const cfg = c.config;
    // Forced modes (impose/uniquement) + coach availability are pinned HARD by
    // build() and hide the rule selector — load them as PREFERRED so that if the
    // user later switches to a soft mode it does NOT stay a hard requirement (the
    // inherited HARD would otherwise leak through, keeping the venue/day forced).
    // `cfg.forcedDays` is the LEGACY key for the DAY "uniquement" mode (#120,
    // before ENG-16) — still recognised so an old row loads correctly and
    // auto-migrates to allowedDays on save.
    const isForced = ("FACILITY" === c.family && ("string" === typeof cfg.forcedVenueId || "string" === typeof cfg.minAtVenueId)) || ("DAY" === c.family && (Array.isArray(cfg.allowedDays) || Array.isArray(cfg.forcedDays))) || "COACH_AVAILABILITY" === c.family;
    setRuleType(isForced ? "PREFERRED" : c.ruleType);
    const tag = "string" === typeof cfg.targetTag ? cfg.targetTag : "";
    if ("COACH_AVAILABILITY" === c.family) {
      setCoachId("string" === typeof cfg.coachId ? cfg.coachId : (c.scopeTargetId ?? ""));
      const available = Array.isArray(cfg.availableDays);
      setCoachMode(available ? "available" : "unavailable");
      setDays(new Set(asNums(available ? cfg.availableDays : cfg.unavailableDays)));
    } else if ("TEAM" === c.scope && null !== c.scopeTargetId) {
      setTarget(c.scopeTargetId);
    } else {
      setTarget("" !== tag ? `tag:${tag}` : "");
    }
    if ("TIME" === c.family) {
      setMinTime("string" === typeof cfg.minStartTime ? cfg.minStartTime : "");
      setMaxTime("string" === typeof cfg.maxStartTime ? cfg.maxStartTime : "");
      setEndTime("string" === typeof cfg.maxEndTime ? cfg.maxEndTime : "");
    }
    if ("DAY" === c.family) {
      // allowedDays (current) OR forcedDays (legacy #120) both mean the "uniquement"
      // mode; loading the legacy key lets a re-save auto-migrate it to allowedDays.
      const only = Array.isArray(cfg.allowedDays) ? cfg.allowedDays : cfg.forcedDays;
      const onlyThese = Array.isArray(only);
      setDayMode(onlyThese ? "forced" : "forbidden");
      setDays(new Set(asNums(onlyThese ? only : cfg.forbiddenDays)));
    }
    if ("FACILITY" === c.family) {
      if ("string" === typeof cfg.forcedVenueId) {
        setVenueMode("forced");
        setVenueId(cfg.forcedVenueId);
      } else if ("string" === typeof cfg.minAtVenueId) {
        setVenueMode("min");
        setVenueId(cfg.minAtVenueId);
        setVenueMinCount("number" === typeof cfg.minAtVenueCount ? cfg.minAtVenueCount : 1);
      } else if ("string" === typeof cfg.forbiddenVenueId) {
        setVenueMode("forbidden");
        setVenueId(cfg.forbiddenVenueId);
      } else {
        setVenueMode("preferred");
        setVenueId("string" === typeof cfg.preferredVenueId ? cfg.preferredVenueId : "");
      }
    }
    setEditingId(c.id);
  };

  // Only groups (tags) that ACTUALLY concern a team of the club: the backend
  // always creates the 21 system tags, but a group with no assigned team (e.g.
  // FEMININE when the club has no female team) must never appear in the selector.
  const assignedTagIds = useMemo(() => new Set(tagAssignments.map((a) => a.tagId)), [tagAssignments]);
  const visibleTags = useMemo(() => tags.filter((t) => assignedTagIds.has(t.id)), [tags, assignedTagIds]);

  const teamPicker = (
    <Select aria-label="Cible" title="Qui est concerné : tout le club, un groupe (tag), ou une équipe précise" className="h-8 w-48" value={target} onChange={(e) => setTarget(e.target.value)}>
      <option value="">Toutes les équipes</option>
      {visibleTags.length > 0 ? (
        <optgroup label="Groupes">
          {visibleTags.map((t) => (
            <option key={t.id} value={`tag:${t.name}`}>
              {t.name}
            </option>
          ))}
        </optgroup>
      ) : null}
      {groupTeamsByTier(teams, tiers).map((group) => (
        <optgroup key={group.tier?.id ?? "orphan"} label={tierGroupLabel(group.tier)}>
          {group.teams.map((t) => (
            <option key={t.id} value={t.id}>
              {t.name}
            </option>
          ))}
        </optgroup>
      ))}
    </Select>
  );

  const list = constraints.filter((c) => c.family === family);

  // Group the list for readability: GROUP (tag) constraints first, then one
  // section per team in canonical rank order (Fanion S, A, B…), then club-wide,
  // coach, and any remainder — instead of a flat, unordered list.
  const sections = useMemo(() => {
    const byTag = new Map<string, Constraint[]>();
    const byTeam = new Map<string, Constraint[]>();
    const byCoach = new Map<string, Constraint[]>();
    const clubWide: Constraint[] = [];
    const other: Constraint[] = [];
    const push = (m: Map<string, Constraint[]>, k: string, c: Constraint): void => {
      m.set(k, [...(m.get(k) ?? []), c]);
    };
    for (const c of list) {
      const tag = "string" === typeof c.config?.targetTag ? (c.config.targetTag as string) : null;
      if ("CLUB" === c.scope && null !== tag) {
        push(byTag, tag, c);
      } else if ("TEAM" === c.scope && null !== c.scopeTargetId) {
        push(byTeam, c.scopeTargetId, c);
      } else if ("COACH" === c.scope && null !== c.scopeTargetId) {
        push(byCoach, c.scopeTargetId, c);
      } else if ("CLUB" === c.scope) {
        clubWide.push(c);
      } else {
        other.push(c);
      }
    }
    const out: { key: string; label: string; items: Constraint[] }[] = [];
    [...byTag.entries()].sort((a, b) => a[0].localeCompare(b[0])).forEach(([tag, items]) => out.push({ key: `g:${tag}`, label: `Groupe ${tag}`, items }));
    if (clubWide.length > 0) {
      out.push({ key: "club", label: "Toutes les équipes", items: clubWide });
    }
    [...teams].sort(compareTeamsByRank).forEach((t) => {
      const items = byTeam.get(t.id);
      if (items && items.length > 0) {
        out.push({ key: `t:${t.id}`, label: t.name, items });
      }
    });
    [...byCoach.entries()].forEach(([cid, items]) => out.push({ key: `c:${cid}`, label: coachName.get(cid) ?? "Coach", items }));
    if (other.length > 0) {
      out.push({ key: "other", label: "Autres", items: other });
    }
    return out;
  // coachName is a fresh Map each render; teams/list are the real inputs.
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [list, teams]);

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
                // Switching family cancels any in-progress edit (the form is shared).
                if (null !== editingId) {
                  resetForm();
                }
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
          onClick={() => {
            if (null !== editingId) {
              resetForm();
            }
            setMode("reserve");
          }}
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
        <ReservationPanel teams={teams} tiers={tiers} venues={venues} calendarEntryId={periodEntryId} />
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
            <label className="text-xs text-muted-foreground">
              Fini avant
              <Input aria-label="Fini avant" type="time" className="mt-0.5 h-8 w-28" value={endTime} onChange={(e) => setEndTime(e.target.value)} />
            </label>
          </>
        )}

        {"DAY" === family && (
          <>
            <Select aria-label="Type de jour" className="h-8 w-28" value={dayMode} onChange={(e) => setDayMode(e.target.value as "forbidden" | "forced")}>
              <option value="forbidden">à éviter</option>
              <option value="forced">uniquement</option>
            </Select>
            <DayPicker days={days} toggle={toggleDay} />
          </>
        )}

        {"FACILITY" === family && (
          <>
            <Select aria-label="Préférence" className="h-8 w-28" value={venueMode} onChange={(e) => setVenueMode(e.target.value as "preferred" | "forbidden" | "forced" | "min")}>
              <option value="preferred">préfère</option>
              <option value="forbidden">évite</option>
              <option value="forced">impose</option>
              <option value="min">au moins</option>
            </Select>
            {"min" === venueMode && (
              <label className="text-xs text-muted-foreground">
                Combien
                <Input aria-label="Nombre de séances" type="number" min={1} className="mt-0.5 h-8 w-16" value={venueMinCount} onChange={(e) => setVenueMinCount(Math.max(1, Number(e.target.value) || 1))} />
              </label>
            )}
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
              {(
                [
                  ["Salariés", coachGroups.salaries],
                  ["Coachs-joueurs", coachGroups.coachJoueurs],
                  ["Bénévoles", coachGroups.benevoles],
                ] as const
              ).map(([label, group]) =>
                group.length > 0 ? (
                  <optgroup key={label} label={label}>
                    {group.map((c) => (
                      <option key={c.id} value={c.id}>
                        {coachName.get(c.id)}
                      </option>
                    ))}
                  </optgroup>
                ) : null,
              )}
            </Select>
            <Select aria-label="Disponibilité" className="h-8 w-44" value={coachMode} onChange={(e) => setCoachMode(e.target.value as "unavailable" | "available")}>
              <option value="unavailable">indisponible</option>
              <option value="available">disponible uniquement</option>
            </Select>
            <DayPicker days={days} toggle={toggleDay} />
          </>
        )}

        {"COACH_AVAILABILITY" === family || ("TIME" === family && "" !== endTime) || ("DAY" === family && "forced" === dayMode) || ("FACILITY" === family && ("forced" === venueMode || "min" === venueMode)) ? (
          // Coach availability + "impose"/"uniquement" + "Fini avant" are ALWAYS
          // hard (a person can't be in two places; a forced venue/day and a
          // gym-closing end-bound are musts, not nudges) — the payload pins HARD,
          // so a rule selector here would be a lie.
          <span className="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">Obligatoire</span>
        ) : (
          <Select aria-label="Règle" className="h-8 w-28" value={ruleType} onChange={(e) => setRuleType(e.target.value as ConstraintRuleType)}>
            {RULES.map((r) => (
              <option key={r} value={r}>
                {RULE_LABEL[r]}
              </option>
            ))}
          </Select>
        )}
        {null !== editingId && (
          <Button size="sm" variant="ghost" className="ml-auto h-8" onClick={resetForm} title="Annuler la modification">
            Annuler
          </Button>
        )}
        <Button
          size="icon"
          className={cn("size-8", null === editingId && "ml-auto")}
          onClick={submit}
          disabled={create.isPending || update.isPending}
          title={null !== editingId ? "Enregistrer la contrainte" : "Ajouter la contrainte"}
          aria-label={null !== editingId ? "Enregistrer la contrainte" : "Ajouter la contrainte"}
        >
          {null !== editingId ? <Check className="size-4" /> : <Plus className="size-4" />}
        </Button>
      </div>

      {/* List for the active family — grouped by group (tag) then team (ranked). */}
      {0 === list.length ? (
        <EmptyHint>Aucune contrainte dans cette famille.</EmptyHint>
      ) : (
        <div className="flex flex-col gap-3">
          {sections.map((section) => (
            <div key={section.key}>
              <p data-testid="constraint-section" className="mb-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">{section.label}</p>
              <ul className="flex flex-col gap-1">
                {section.items.map((c: Constraint) => (
                  <li key={c.id} className={cn("flex items-center gap-2 rounded-md border bg-card px-3 py-1.5 text-sm", editingId === c.id ? "border-accent ring-1 ring-accent" : "border-border")}>
                    <span className="flex-1">{c.name}</span>
                    <span className="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">{RULE_LABEL[c.ruleType]}</span>
                    <button type="button" aria-label="Modifier" className="text-muted-foreground hover:text-foreground" onClick={() => editConstraint(c)}>
                      <Pencil className="size-4" />
                    </button>
                    <button type="button" aria-label="Supprimer" className="text-muted-foreground hover:text-destructive" onClick={() => setPendingDelete(c)}>
                      <Trash2 className="size-4" />
                    </button>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </div>
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
