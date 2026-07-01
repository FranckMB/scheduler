import { ChevronDown, ChevronUp, Plus, Trash2 } from "lucide-react";
import { type FormEvent, useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { Input } from "@/shared/components/ui/input";
import { Select } from "@/shared/components/ui/select";

import type { Gender, PriorityTier, SportCategory, Team, TeamPayload } from "../api";
import { useCreateTeam, useDeleteTeam, usePriorityTiers, useSportCategories, useUpdateTeam, useWizardTeams } from "../queries";
import { orderedTeams, teamsOfTier, usedTiers } from "../lib/ranking";

const GENDERS: { value: Gender | ""; label: string }[] = [
  { value: "", label: "—" },
  { value: "M", label: "M" },
  { value: "F", label: "F" },
  { value: "MIXTE", label: "Mixte" },
];

function payload(team: Team, patch: Partial<TeamPayload>): TeamPayload {
  return {
    name: team.name,
    sportCategoryId: team.sportCategoryId,
    priorityTierId: team.priorityTierId,
    tierOrder: team.tierOrder,
    gender: team.gender,
    sessionsPerWeek: team.sessionsPerWeek,
    isActive: team.isActive,
    ...patch,
  };
}

const nextOrder = (teams: Team[], tierId: number): number => {
  const group = teamsOfTier(teams, tierId);
  return 0 === group.length ? 0 : Math.max(...group.map((t) => t.tierOrder)) + 1;
};

interface RowProps {
  team: Team;
  number: number;
  categories: SportCategory[];
  tiers: PriorityTier[];
  canUp: boolean;
  canDown: boolean;
  onField: (team: Team, patch: Partial<TeamPayload>) => void;
  onMove: (team: Team, dir: -1 | 1) => void;
  onDelete: (team: Team) => void;
}

function TeamRow({ team, number, categories, tiers, canUp, canDown, onField, onMove, onDelete }: RowProps) {
  // Local edit buffers (saved on blur). name/sessions only change through this row.
  const [name, setName] = useState(team.name);
  const [sessions, setSessions] = useState(String(team.sessionsPerWeek));

  return (
    <div className="flex items-center gap-2 border-t border-border py-1.5">
      <span className="w-6 shrink-0 text-center text-xs text-muted-foreground">{number}</span>
      <Input
        aria-label="Nom"
        className="h-8 flex-1"
        value={name}
        onChange={(e) => setName(e.target.value)}
        onBlur={() => name.trim() && name !== team.name && onField(team, { name: name.trim() })}
      />
      <Select aria-label="Catégorie" className="h-8 w-32" value={team.sportCategoryId} onChange={(e) => onField(team, { sportCategoryId: e.target.value })}>
        {categories.map((c) => (
          <option key={c.id} value={c.id}>
            {c.name}
          </option>
        ))}
      </Select>
      <Select aria-label="Genre" className="h-8 w-20" value={team.gender ?? ""} onChange={(e) => onField(team, { gender: (e.target.value || null) as Gender | null })}>
        {GENDERS.map((g) => (
          <option key={g.value} value={g.value}>
            {g.label}
          </option>
        ))}
      </Select>
      <Input
        aria-label="Séances/sem"
        type="number"
        min={1}
        className="h-8 w-16"
        value={sessions}
        onChange={(e) => setSessions(e.target.value)}
        onBlur={() => Number(sessions) !== team.sessionsPerWeek && onField(team, { sessionsPerWeek: Number(sessions) })}
      />
      <Select aria-label="Tier" className="h-8 w-16" value={team.priorityTierId} onChange={(e) => onField(team, { priorityTierId: Number(e.target.value) })}>
        {tiers.map((t) => (
          <option key={t.id} value={t.id}>
            {t.label}
          </option>
        ))}
      </Select>
      <Button size="icon" variant="ghost" className="size-8" aria-label="Monter" disabled={!canUp} onClick={() => onMove(team, -1)}>
        <ChevronUp className="size-4" />
      </Button>
      <Button size="icon" variant="ghost" className="size-8" aria-label="Descendre" disabled={!canDown} onClick={() => onMove(team, 1)}>
        <ChevronDown className="size-4" />
      </Button>
      <Button size="icon" variant="ghost" className="size-8 text-destructive" aria-label="Supprimer" onClick={() => onDelete(team)}>
        <Trash2 className="size-4" />
      </Button>
    </div>
  );
}

export function TeamsStep() {
  const { data: teams = [] } = useWizardTeams();
  const { data: categories = [] } = useSportCategories();
  const { data: tiers = [] } = usePriorityTiers();
  const create = useCreateTeam();
  const update = useUpdateTeam();
  const del = useDeleteTeam();

  const [name, setName] = useState("");
  const [catId, setCatId] = useState("");
  const [tierId, setTierId] = useState(1);
  const [gender, setGender] = useState<Gender | "">("");
  const [sessions, setSessions] = useState("2");
  // Default to the first category until the user picks one (no effect needed).
  const effectiveCat = catId || categories[0]?.id || "";

  const numberOf = new Map(orderedTeams(teams).map((r) => [r.team.id, r.globalNumber]));
  const tierGroups = usedTiers(teams, tiers);

  const onField = (team: Team, patch: Partial<TeamPayload>) => {
    const extra: Partial<TeamPayload> = "priorityTierId" in patch ? { tierOrder: nextOrder(teams, patch.priorityTierId as number) } : {};
    update.mutate({ id: team.id, body: payload(team, { ...patch, ...extra }) });
  };

  const onMove = (team: Team, dir: -1 | 1) => {
    const group = teamsOfTier(teams, team.priorityTierId);
    const idx = group.findIndex((t) => t.id === team.id);
    const other = group[idx + dir];
    if (undefined === other) {
      return;
    }
    update.mutate({ id: team.id, body: payload(team, { tierOrder: other.tierOrder }) });
    update.mutate({ id: other.id, body: payload(other, { tierOrder: team.tierOrder }) });
  };

  const addTeam = (event: FormEvent) => {
    event.preventDefault();
    if ("" === name.trim()) {
      return;
    }
    create.mutate({
      name: name.trim(),
      sportCategoryId: effectiveCat || undefined,
      priorityTierId: tierId,
      tierOrder: nextOrder(teams, tierId),
      gender: gender || null,
      sessionsPerWeek: Number(sessions),
      isActive: true,
    });
    setName("");
  };

  return (
    <div>
      <h2 className="mb-1 text-xl font-semibold">Équipes</h2>
      <p className="mb-4 text-sm text-muted-foreground">
        Ajoutez vos équipes, classez-les par tier (S &gt; A &gt; B &gt; C &gt; D) et ordonnez-les dans chaque tier — les plus importantes remontent en tête.
      </p>

      <form onSubmit={addTeam} className="mb-6 flex flex-wrap items-end gap-2 rounded-lg border border-border bg-card p-3">
        <Input aria-label="Nom de l'équipe" placeholder="Nom de l'équipe" className="h-9 flex-1" value={name} onChange={(e) => setName(e.target.value)} />
        <Select aria-label="Catégorie" className="h-9 w-36" value={effectiveCat} onChange={(e) => setCatId(e.target.value)}>
          {categories.map((c) => (
            <option key={c.id} value={c.id}>
              {c.name}
            </option>
          ))}
        </Select>
        <Select aria-label="Tier" className="h-9 w-20" value={tierId} onChange={(e) => setTierId(Number(e.target.value))}>
          {tiers.map((t) => (
            <option key={t.id} value={t.id}>
              {t.label}
            </option>
          ))}
        </Select>
        <Select aria-label="Genre" className="h-9 w-24" value={gender} onChange={(e) => setGender(e.target.value as Gender | "")}>
          {GENDERS.map((g) => (
            <option key={g.value} value={g.value}>
              {g.label}
            </option>
          ))}
        </Select>
        <Input aria-label="Séances/sem" type="number" min={1} className="h-9 w-20" value={sessions} onChange={(e) => setSessions(e.target.value)} />
        <Button type="submit" disabled={create.isPending}>
          <Plus className="size-4" />
          Ajouter
        </Button>
      </form>

      {0 === teams.length ? (
        <p className="text-sm text-muted-foreground">Aucune équipe pour le moment.</p>
      ) : (
        <div className="flex flex-col gap-4">
          {tierGroups.map((tier) => {
            const group = teamsOfTier(teams, tier.id);
            return (
              <section key={tier.id}>
                <h3 className="mb-1 text-sm font-semibold">
                  {tier.label} · {tier.name}
                </h3>
                <div className="rounded-lg border border-border bg-card px-2">
                  {group.map((team, i) => (
                    <TeamRow
                      key={team.id}
                      team={team}
                      number={numberOf.get(team.id) ?? 0}
                      categories={categories}
                      tiers={tiers}
                      canUp={i > 0}
                      canDown={i < group.length - 1}
                      onField={onField}
                      onMove={onMove}
                      onDelete={(t) => del.mutate(t.id)}
                    />
                  ))}
                </div>
              </section>
            );
          })}
        </div>
      )}
    </div>
  );
}
