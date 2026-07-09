import { closestCorners, DndContext, type DragEndEvent, DragOverlay, KeyboardSensor, PointerSensor, useDroppable, useSensor, useSensors } from "@dnd-kit/core";
import { arrayMove, SortableContext, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";
import { ArrowUpDown, ChevronDown, ChevronUp, GripVertical, Plus, Trash2 } from "lucide-react";
import { type FormEvent, useCallback, useEffect, useRef, useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { Input } from "@/shared/components/ui/input";
import { Select } from "@/shared/components/ui/select";
import { TIER_MEANING, tierGroupLabel } from "@/shared/lib/teamTiers";
import { cn } from "@/shared/lib/utils";

import type { Gender, PriorityTier, SportCategory, Team, TeamLevel, TeamPayload } from "../api";
import { useWizardFooter } from "../lib/footerSlot";
import { orderedTeams, teamsOfTier, usedTiers } from "../lib/ranking";
import { useCreateTeam, useDeleteTeam, usePriorityTiers, useReorderTeams, useSportCategories, useUpdateTeam, useWizardTeams } from "../queries";
import { useWizardStore } from "../store";
import { ReadonlyTeams } from "./StructureSummary";

const GENDERS: { value: Gender | ""; label: string }[] = [
  { value: "", label: "—" },
  { value: "M", label: "Homme" },
  { value: "F", label: "Femme" },
  { value: "MIXTE", label: "Mixte" },
];

// FFBB competition levels (backend App\Enum\TeamLevel). "" = non renseigné.
const LEVELS: { value: TeamLevel | ""; label: string }[] = [
  { value: "", label: "—" },
  { value: "ELITE", label: "Élite" },
  { value: "NATIONAL", label: "National" },
  { value: "REGIONAL", label: "Régional" },
  { value: "PRE_REGION", label: "Pré-région" },
  { value: "DEPARTEMENTAL", label: "Départemental" },
  { value: "HONNEUR", label: "Honneur" },
  { value: "PROMOTION", label: "Promotion" },
  { value: "LOISIR_ADULTE", label: "Loisir adulte" },
  { value: "LOISIR_JEUNE", label: "Loisir jeune" },
];

/** A team is "competitive" unless it plays at a loisir level (or has none set). */
const isCompetitive = (level: TeamLevel | null): boolean =>
  null !== level && "LOISIR_ADULTE" !== level && "LOISIR_JEUNE" !== level;

/** The "Bonus" tier is identified by its label ("D"), not a fixture row id. */
const isBonusTier = (tiers: PriorityTier[], tierId: number): boolean =>
  tiers.find((t) => t.id === tierId)?.label === "D";

function payload(team: Team, patch: Partial<TeamPayload>): TeamPayload {
  return {
    name: team.name,
    sportCategoryId: team.sportCategoryId,
    priorityTierId: team.priorityTierId,
    tierOrder: team.tierOrder,
    gender: team.gender,
    level: team.level,
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
  onField: (team: Team, patch: Partial<TeamPayload>) => void;
  onDelete: (team: Team) => void;
}

function TeamRow({ team, number, categories, tiers, onField, onDelete }: RowProps) {
  // Local edit buffers (saved on blur). name/sessions only change through this row.
  const [name, setName] = useState(team.name);
  const [sessions, setSessions] = useState(String(team.sessionsPerWeek));

  // Competitive team ranked "Bonus" (D) is likely a mistake — it will be
  // scheduled last. Non-blocking warning (the solver stays the authority).
  const bonusCompetitionWarning = isCompetitive(team.level) && isBonusTier(tiers, team.priorityTierId);

  return (
    <div className="border-t border-border py-1.5">
      <div className="flex items-center gap-2">
        <span className="w-6 shrink-0 text-center text-xs text-muted-foreground">{number}</span>
        <Input
          aria-label="Nom"
          className="h-8 flex-1"
          value={name}
          onChange={(e) => setName(e.target.value)}
          onBlur={() => name.trim() && name !== team.name && onField(team, { name: name.trim() })}
        />
        <Select aria-label="Catégorie" className="h-8 w-28" value={team.sportCategoryId} onChange={(e) => onField(team, { sportCategoryId: e.target.value })}>
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
        <Select aria-label="Niveau de jeu" className="h-8 w-32" value={team.level ?? ""} onChange={(e) => onField(team, { level: (e.target.value || null) as TeamLevel | null })}>
          {LEVELS.map((l) => (
            <option key={l.value} value={l.value}>
              {l.label}
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
        {/* Rang is not edited inline: changing a team's tier is done via the
            "Trier" mode (drag & drop between S/A/B/C/D zones). */}
        <Button size="icon" variant="ghost" className="size-8 text-destructive" aria-label="Supprimer" onClick={() => onDelete(team)}>
          <Trash2 className="size-4" />
        </Button>
      </div>
      {bonusCompetitionWarning && (
        <p role="alert" className="ml-8 mt-1 text-xs text-amber-500">
          Équipe en compétition classée Bonus (D) — elle passera en dernier ; vérifiez le rang.
        </p>
      )}
    </div>
  );
}

// --- Sort mode (drag & drop, inter-tier) --------------------------------------

interface SortRowProps {
  team: Team;
  canUp: boolean;
  canDown: boolean;
  onArrow: (dir: -1 | 1) => void;
}

/** Compact sortable row: drag handle + name + up/down arrows (a11y fallback). No delete/edit. */
function SortableTeamRow({ team, canUp, canDown, onArrow }: SortRowProps) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: team.id });
  const style = { transform: CSS.Transform.toString(transform), transition, opacity: isDragging ? 0.4 : 1 };
  return (
    <div ref={setNodeRef} style={style} className="flex items-center gap-2 rounded-md border border-border bg-background px-2 py-2">
      <button type="button" className="cursor-grab touch-none text-muted-foreground hover:text-foreground" aria-label="Déplacer l'équipe" {...attributes} {...listeners}>
        <GripVertical className="size-4" />
      </button>
      <span className="flex-1 text-sm">{team.name}</span>
      <Button size="icon" variant="ghost" className="size-7" aria-label="Monter" disabled={!canUp} onClick={() => onArrow(-1)}>
        <ChevronUp className="size-4" />
      </Button>
      <Button size="icon" variant="ghost" className="size-7" aria-label="Descendre" disabled={!canDown} onClick={() => onArrow(1)}>
        <ChevronDown className="size-4" />
      </Button>
    </div>
  );
}

interface TierZoneProps {
  tier: PriorityTier;
  teamIds: string[];
  teamById: Map<string, Team>;
  onArrow: (tierId: number, teamId: string, dir: -1 | 1) => void;
}

/** A droppable tier zone — always visible during sort so teams can be dropped across tiers. */
function TierZone({ tier, teamIds, teamById, onArrow }: TierZoneProps) {
  const { setNodeRef, isOver } = useDroppable({ id: `zone-${tier.id}` });
  return (
    <section>
      <h3 className="mb-1 text-sm font-semibold">
        {tierGroupLabel(tier)}
      </h3>
      <SortableContext items={teamIds} strategy={verticalListSortingStrategy}>
        <div ref={setNodeRef} className={cn("flex min-h-14 flex-col gap-1.5 rounded-lg border-2 border-dashed p-2 transition-colors", isOver ? "border-accent bg-accent/5" : "border-border")}>
          {0 === teamIds.length ? (
            <p className="py-2 text-center text-xs text-muted-foreground">Glissez une équipe ici</p>
          ) : (
            teamIds.map((id, i) => {
              const team = teamById.get(id);
              return team ? <SortableTeamRow key={id} team={team} canUp={i > 0} canDown={i < teamIds.length - 1} onArrow={(dir) => onArrow(tier.id, id, dir)} /> : null;
            })
          )}
        </div>
      </SortableContext>
    </section>
  );
}

const zoneTierId = (id: string): number | null => (id.startsWith("zone-") ? Number(id.slice(5)) : null);

export function TeamsStep() {
  const periodMode = useWizardStore((s) => s.mode === "period");
  if (periodMode) {
    return <ReadonlyTeams />;
  }
  return <TeamsEditor />;
}

function TeamsEditor() {
  const { data: teams = [] } = useWizardTeams();
  const { data: categories = [] } = useSportCategories();
  const { data: tiers = [] } = usePriorityTiers();
  const create = useCreateTeam();
  const update = useUpdateTeam();
  const del = useDeleteTeam();
  const reorder = useReorderTeams();

  const [name, setName] = useState("");
  const [nameError, setNameError] = useState(false);
  const nameRef = useRef<HTMLInputElement>(null);
  const [catId, setCatId] = useState("");
  const [tierId, setTierId] = useState(1);
  const [gender, setGender] = useState<Gender | "">("");
  const [level, setLevel] = useState<TeamLevel | "">("");
  const [sessions, setSessions] = useState("2");
  // Default to the first category until the user picks one (no effect needed).
  const effectiveCat = catId || categories[0]?.id || "";

  const numberOf = new Map(orderedTeams(teams).map((r) => [r.team.id, r.globalNumber]));
  const tierGroups = usedTiers(teams, tiers);

  const onField = (team: Team, patch: Partial<TeamPayload>) => {
    const extra: Partial<TeamPayload> = "priorityTierId" in patch ? { tierOrder: nextOrder(teams, patch.priorityTierId as number) } : {};
    update.mutate({ id: team.id, body: payload(team, { ...patch, ...extra }) });
  };

  const addTeam = (event: FormEvent) => {
    event.preventDefault();
    if ("" === name.trim()) {
      // Silent no-op was frustrating: surface why + jump to the empty field.
      setNameError(true);
      nameRef.current?.focus();
      return;
    }
    setNameError(false);
    create.mutate({
      name: name.trim(),
      sportCategoryId: effectiveCat || undefined,
      priorityTierId: tierId,
      tierOrder: nextOrder(teams, tierId),
      gender: gender || null,
      level: level || null,
      sessionsPerWeek: Number(sessions),
      isActive: true,
    });
    setName("");
    // Return focus to the name field so the next team can be typed straight away.
    nameRef.current?.focus();
  };

  // --- Sort mode: local reordering, committed atomically on exit ---
  const { setFooterExtra, setSuppressScrollJump } = useWizardFooter();
  const [sortMode, setSortMode] = useState(false);
  const [lanes, setLanes] = useState<Record<number, string[]>>({});
  const [activeId, setActiveId] = useState<string | null>(null);
  const lanesRef = useRef(lanes);
  const reorderRef = useRef(reorder);
  const tiersRef = useRef(tiers);
  // Dirty = the user reordered but hasn't committed yet. Guards the flush-on-exit.
  const dirtyRef = useRef(false);
  useEffect(() => {
    reorderRef.current = reorder;
    tiersRef.current = tiers;
  });

  // Build the atomic reorder payload from the current lanes and persist it.
  // Reads everything through refs so the callback is stable — the flush-on-exit
  // effect below must fire ONLY on unmount, never on a tiers refetch.
  const flushSort = useCallback(() => {
    if (!dirtyRef.current) {
      return;
    }
    const items: { id: string; priorityTierId: number; tierOrder: number }[] = [];
    for (const tier of tiersRef.current) {
      (lanesRef.current[tier.id] ?? []).forEach((id, index) => items.push({ id, priorityTierId: tier.id, tierOrder: index }));
    }
    dirtyRef.current = false;
    if (items.length > 0) {
      reorderRef.current.mutate(items);
    }
  }, []);

  // Commit any pending reorder when the step unmounts (e.g. the user clicks
  // "Suivant" while still in sort mode) — otherwise the order was lost.
  useEffect(() => () => flushSort(), [flushSort]);
  const sortedTiers = [...tiers].sort((a, b) => a.id - b.id);
  const teamById = new Map(teams.map((t) => [t.id, t] as const));

  const setBothLanes = (next: Record<number, string[]>) => {
    lanesRef.current = next;
    dirtyRef.current = true;
    setLanes(next);
  };

  // Enter → snapshot the current order into lanes; exit → persist the whole
  // ordering in ONE atomic call (every team gets an explicit tierOrder = index,
  // so anyone without a number gets one). Lanes are edited locally during sort;
  // the server is not re-read until exit, so manual order isn't reverted by the
  // name-sort.
  const toggleSort = useCallback(() => {
    if (sortMode) {
      flushSort();
      setSortMode(false);
      return;
    }
    const next: Record<number, string[]> = {};
    for (const tier of tiers) {
      next[tier.id] = teamsOfTier(teams, tier.id).map((t) => t.id);
    }
    lanesRef.current = next;
    dirtyRef.current = false;
    setLanes(next);
    setSortMode(true);
  }, [sortMode, teams, tiers, flushSort]);

  // Register the "Trier" toggle in the wizard footer, left of "Suivant".
  useEffect(() => {
    setFooterExtra(
      teams.length > 0 ? (
        <Button size="sm" variant={sortMode ? "default" : "outline"} onClick={toggleSort}>
          <ArrowUpDown className="size-4" />
          {sortMode ? "Terminer le tri" : "Trier"}
        </Button>
      ) : null,
    );
    return () => setFooterExtra(null);
  }, [sortMode, teams.length, toggleSort, setFooterExtra]);

  // Hide the floating scroll-jump arrows during drag-reorder (they'd sit over
  // the drop zones and a mis-click would scroll-jump mid-sort).
  useEffect(() => {
    setSuppressScrollJump(sortMode);
    return () => setSuppressScrollJump(false);
  }, [sortMode, setSuppressScrollJump]);

  const laneOf = (id: string): number | null => {
    const zone = zoneTierId(id);
    if (null !== zone) {
      return zone;
    }
    for (const [t, ids] of Object.entries(lanesRef.current)) {
      if (ids.includes(id)) {
        return Number(t);
      }
    }
    return null;
  };

  const onDragEnd = (event: DragEndEvent) => {
    setActiveId(null);
    const { active, over } = event;
    if (null === over) {
      return;
    }
    const from = laneOf(String(active.id));
    const to = laneOf(String(over.id));
    if (null === from || null === to) {
      return;
    }
    const next = { ...lanesRef.current };
    if (from === to) {
      const items = [...(next[from] ?? [])];
      const oldIdx = items.indexOf(String(active.id));
      const overZone = zoneTierId(String(over.id));
      const newIdx = null !== overZone ? items.length - 1 : items.indexOf(String(over.id));
      if (oldIdx < 0 || newIdx < 0 || oldIdx === newIdx) {
        return;
      }
      next[from] = arrayMove(items, oldIdx, newIdx);
    } else {
      const src = [...(next[from] ?? [])];
      const dst = [...(next[to] ?? [])];
      src.splice(src.indexOf(String(active.id)), 1);
      const overZone = zoneTierId(String(over.id));
      let idx = null !== overZone ? dst.length : dst.indexOf(String(over.id));
      if (idx < 0) {
        idx = dst.length;
      }
      dst.splice(idx, 0, String(active.id));
      next[from] = src;
      next[to] = dst;
    }
    setBothLanes(next);
  };

  const moveInLane = (laneId: number, teamId: string, dir: -1 | 1) => {
    const items = [...(lanesRef.current[laneId] ?? [])];
    const idx = items.indexOf(teamId);
    const j = idx + dir;
    if (idx < 0 || j < 0 || j >= items.length) {
      return;
    }
    setBothLanes({ ...lanesRef.current, [laneId]: arrayMove(items, idx, j) });
  };

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 4 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  );

  return (
    <div>
      {/* No inner "Équipes" heading: the sticky wizard header already shows
          "Étape 1/6 · Équipes" (WizardLayout). Keep the contextual description. */}
      <p className="mb-2 text-sm text-muted-foreground">
        Listez vos équipes et donnez à chacune un <strong>rang</strong> : il tranche quand les créneaux manquent — les mieux classées passent d'abord.
        Le <strong>niveau de jeu</strong> (division FFBB) est indépendant : il décrit la compétition, pas la priorité de placement.
      </p>
      <div className="mb-4 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
        {(Object.entries(TIER_MEANING) as [string, string][]).map(([label, meaning]) => (
          <span key={label}>
            <strong className="text-foreground">{label}</strong> {meaning}
          </span>
        ))}
      </div>

      {sortMode ? (
        <>
          <p className="mb-3 text-sm text-muted-foreground">
            Glissez une équipe par sa poignée pour la réordonner <strong>ou la déplacer dans un autre niveau</strong>. Les flèches restent disponibles.
          </p>
          <DndContext
            sensors={sensors}
            collisionDetection={closestCorners}
            onDragStart={(event) => setActiveId(String(event.active.id))}
            onDragCancel={() => setActiveId(null)}
            onDragEnd={onDragEnd}
          >
            <div className="flex flex-col gap-4">
              {sortedTiers.map((tier) => (
                <TierZone key={tier.id} tier={tier} teamIds={lanes[tier.id] ?? []} teamById={teamById} onArrow={moveInLane} />
              ))}
            </div>
            <DragOverlay>
              {null !== activeId ? <div className="rounded-md border border-accent bg-background px-3 py-2 text-sm shadow-lg">{teamById.get(activeId)?.name}</div> : null}
            </DragOverlay>
          </DndContext>
        </>
      ) : (
        <>
          <form onSubmit={addTeam} className="mb-6 flex flex-wrap items-end gap-2 rounded-lg border border-border bg-card p-3 text-sm">
            <Input
              ref={nameRef}
              aria-label="Nom de l'équipe"
              aria-invalid={nameError}
              placeholder="Nom de l'équipe"
              className={cn("h-8 min-w-0 flex-1", nameError ? "border-destructive focus-visible:ring-destructive" : "")}
              value={name}
              onChange={(e) => {
                setName(e.target.value);
                if (nameError) {
                  setNameError(false);
                }
              }}
            />
            <Select aria-label="Catégorie" className="h-8 w-28" value={effectiveCat} onChange={(e) => setCatId(e.target.value)}>
              {categories.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </Select>
            <Select aria-label="Genre" className="h-8 w-24" value={gender} onChange={(e) => setGender(e.target.value as Gender | "")}>
              {GENDERS.map((g) => (
                <option key={g.value} value={g.value}>
                  {g.label}
                </option>
              ))}
            </Select>
            <Select aria-label="Niveau de jeu" className="h-8 w-32" value={level} onChange={(e) => setLevel(e.target.value as TeamLevel | "")}>
              {LEVELS.map((l) => (
                <option key={l.value} value={l.value}>
                  {l.label}
                </option>
              ))}
            </Select>
            <Input aria-label="Séances/sem" type="number" min={1} className="h-8 w-16" value={sessions} onChange={(e) => setSessions(e.target.value)} />
            <Select aria-label="Rang" className="h-8 w-32" value={tierId} onChange={(e) => setTierId(Number(e.target.value))}>
              {tiers.map((t) => (
                <option key={t.id} value={t.id}>
                  {tierGroupLabel(t)}
                </option>
              ))}
            </Select>
            <Button type="submit" size="icon" className="ml-auto size-8" disabled={create.isPending} title="Ajouter l'équipe" aria-label="Ajouter l'équipe">
              <Plus className="size-4" />
            </Button>
          </form>
          {nameError ? (
            <p role="alert" className="-mt-4 mb-4 text-sm text-destructive">
              Le nom de l'équipe est obligatoire.
            </p>
          ) : null}

          {0 === teams.length ? (
            <EmptyHint>Aucune équipe pour le moment.</EmptyHint>
          ) : (
            <div className="flex flex-col gap-4">
              <div className="flex items-center gap-2 px-2 text-xs font-medium text-muted-foreground">
                <span className="w-6 text-center">#</span>
                <span className="flex-1">Nom de l'équipe</span>
                <span className="w-28">Catégorie</span>
                <span className="w-20">Genre</span>
                <span className="w-32">Niveau de jeu</span>
                <span className="w-16">Séances</span>
                <span className="w-8 text-right">Suppr.</span>
              </div>
              {tierGroups.map((tier) => {
                const group = teamsOfTier(teams, tier.id);
                return (
                  <section key={tier.id}>
                    <h3 className="mb-1 text-sm font-semibold">
                      {tierGroupLabel(tier)}
                    </h3>
                    <div className="rounded-lg border border-border bg-card px-2">
                      {group.map((team) => (
                        <TeamRow
                          key={team.id}
                          team={team}
                          number={numberOf.get(team.id) ?? 0}
                          categories={categories}
                          tiers={tiers}
                          onField={onField}
                          onDelete={(t) => del.mutate(t.id)}
                        />
                      ))}
                    </div>
                  </section>
                );
              })}
            </div>
          )}
        </>
      )}

    </div>
  );
}
