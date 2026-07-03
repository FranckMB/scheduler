import { closestCorners, DndContext, type DragEndEvent, DragOverlay, KeyboardSensor, PointerSensor, useDroppable, useSensor, useSensors } from "@dnd-kit/core";
import { arrayMove, SortableContext, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";
import { ArrowUpDown, ChevronDown, ChevronsDown, ChevronsUp, ChevronUp, GripVertical, Plus, Trash2 } from "lucide-react";
import { type FormEvent, useCallback, useEffect, useRef, useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { Input } from "@/shared/components/ui/input";
import { Select } from "@/shared/components/ui/select";
import { cn } from "@/shared/lib/utils";

import type { Gender, PriorityTier, SportCategory, Team, TeamPayload } from "../api";
import { useWizardFooter } from "../lib/footerSlot";
import { orderedTeams, teamsOfTier, usedTiers } from "../lib/ranking";
import { useCreateTeam, useDeleteTeam, usePriorityTiers, useReorderTeams, useSportCategories, useUpdateTeam, useWizardTeams } from "../queries";

// Manager-facing meaning of each priority tier (backend tier names are FFBB
// competition levels; here we explain what the rank means for scheduling).
const TIER_MEANING: Record<string, string> = {
  S: "Fanion",
  A: "Importante",
  B: "Moyenne",
  C: "De base",
  D: "Bonus",
};

const GENDERS: { value: Gender | ""; label: string }[] = [
  { value: "", label: "—" },
  { value: "M", label: "Homme" },
  { value: "F", label: "Femme" },
  { value: "MIXTE", label: "Mixte" },
];

/** Full label for a tier select: "S · Fanion" rather than the bare letter. */
const tierLabel = (t: PriorityTier): string => `${t.label} · ${TIER_MEANING[t.label] ?? t.name}`;

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
  onField: (team: Team, patch: Partial<TeamPayload>) => void;
  onDelete: (team: Team) => void;
}

function TeamRow({ team, number, categories, tiers, onField, onDelete }: RowProps) {
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
      <Select aria-label="Niveau" className="h-8 w-32" value={team.priorityTierId} onChange={(e) => onField(team, { priorityTierId: Number(e.target.value) })}>
        {tiers.map((t) => (
          <option key={t.id} value={t.id}>
            {tierLabel(t)}
          </option>
        ))}
      </Select>
      <Button size="icon" variant="ghost" className="size-8 text-destructive" aria-label="Supprimer" onClick={() => onDelete(team)}>
        <Trash2 className="size-4" />
      </Button>
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
        {tier.label} · {TIER_MEANING[tier.label] ?? tier.name}
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
  const { data: teams = [] } = useWizardTeams();
  const { data: categories = [] } = useSportCategories();
  const { data: tiers = [] } = usePriorityTiers();
  const create = useCreateTeam();
  const update = useUpdateTeam();
  const del = useDeleteTeam();
  const reorder = useReorderTeams();

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

  // --- Sort mode: local reordering, committed atomically on exit ---
  const { setFooterExtra } = useWizardFooter();
  const [sortMode, setSortMode] = useState(false);
  const [lanes, setLanes] = useState<Record<number, string[]>>({});
  const [activeId, setActiveId] = useState<string | null>(null);
  const lanesRef = useRef(lanes);
  const reorderRef = useRef(reorder);
  useEffect(() => {
    reorderRef.current = reorder;
  });
  const sortedTiers = [...tiers].sort((a, b) => a.id - b.id);
  const teamById = new Map(teams.map((t) => [t.id, t] as const));

  const setBothLanes = (next: Record<number, string[]>) => {
    lanesRef.current = next;
    setLanes(next);
  };

  // Enter → snapshot the current order into lanes; exit → persist the whole
  // ordering in ONE atomic call (every team gets an explicit tierOrder = index,
  // so anyone without a number gets one). Lanes are edited locally during sort;
  // the server is not re-read until exit, so manual order isn't reverted by the
  // name-sort.
  const toggleSort = useCallback(() => {
    if (sortMode) {
      const items: { id: string; priorityTierId: number; tierOrder: number }[] = [];
      for (const tier of tiers) {
        (lanesRef.current[tier.id] ?? []).forEach((id, index) => items.push({ id, priorityTierId: tier.id, tierOrder: index }));
      }
      if (items.length > 0) {
        reorderRef.current.mutate(items);
      }
      setSortMode(false);
      return;
    }
    const next: Record<number, string[]> = {};
    for (const tier of tiers) {
      next[tier.id] = teamsOfTier(teams, tier.id).map((t) => t.id);
    }
    lanesRef.current = next;
    setLanes(next);
    setSortMode(true);
  }, [sortMode, teams, tiers]);

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
      <h2 className="mb-1 text-xl font-semibold">Équipes</h2>
      <p className="mb-2 text-sm text-muted-foreground">
        Listez vos équipes et donnez à chacune un <strong>rang</strong> : il tranche quand les créneaux manquent — les mieux classées passent d'abord.
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
            <Input aria-label="Nom de l'équipe" placeholder="Nom de l'équipe" className="h-8 min-w-40 flex-1" value={name} onChange={(e) => setName(e.target.value)} />
            <Select aria-label="Catégorie" className="h-8 w-28" value={effectiveCat} onChange={(e) => setCatId(e.target.value)}>
              {categories.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </Select>
            <Select aria-label="Niveau" className="h-8 w-36" value={tierId} onChange={(e) => setTierId(Number(e.target.value))}>
              {tiers.map((t) => (
                <option key={t.id} value={t.id}>
                  {tierLabel(t)}
                </option>
              ))}
            </Select>
            <Select aria-label="Genre" className="h-8 w-28" value={gender} onChange={(e) => setGender(e.target.value as Gender | "")}>
              {GENDERS.map((g) => (
                <option key={g.value} value={g.value}>
                  {g.label}
                </option>
              ))}
            </Select>
            <Input aria-label="Séances/sem" type="number" min={1} className="h-8 w-16" value={sessions} onChange={(e) => setSessions(e.target.value)} />
            <Button type="submit" size="icon" className="ml-auto size-8" disabled={create.isPending} title="Ajouter l'équipe" aria-label="Ajouter l'équipe">
              <Plus className="size-4" />
            </Button>
          </form>

          {0 === teams.length ? (
            <p className="text-sm text-muted-foreground">Aucune équipe pour le moment.</p>
          ) : (
            <div className="flex flex-col gap-4">
              <div className="flex items-center gap-2 px-2 text-xs font-medium text-muted-foreground">
                <span className="w-6 text-center">#</span>
                <span className="flex-1">Nom de l'équipe</span>
                <span className="w-32">Catégorie</span>
                <span className="w-20">Genre</span>
                <span className="w-16">Séances</span>
                <span className="w-32">Niveau</span>
                <span className="w-8 text-right">Suppr.</span>
              </div>
              {tierGroups.map((tier) => {
                const group = teamsOfTier(teams, tier.id);
                return (
                  <section key={tier.id}>
                    <h3 className="mb-1 text-sm font-semibold">
                      {tier.label} · {TIER_MEANING[tier.label] ?? tier.name}
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

      {!sortMode && teams.length > 8 ? (
        <div className="fixed bottom-6 right-6 z-40 flex flex-col gap-1">
          <Button size="icon" variant="outline" aria-label="Haut de page" onClick={() => window.scrollTo({ top: 0, behavior: "smooth" })}>
            <ChevronsUp className="size-4" />
          </Button>
          <Button size="icon" variant="outline" aria-label="Bas de page" onClick={() => window.scrollTo({ top: document.body.scrollHeight, behavior: "smooth" })}>
            <ChevronsDown className="size-4" />
          </Button>
        </div>
      ) : null}
    </div>
  );
}
