import { Plus, Trash2, X } from "lucide-react";
import { type FormEvent, useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { Input } from "@/shared/components/ui/input";
import { Select } from "@/shared/components/ui/select";

import type { Coach, CoachPlayerMembership, Team, TeamCoach, TeamCoachRole } from "../api";
import {
  useCreateCoach,
  useCreateCoachPlayer,
  useCreateTeamCoach,
  useDeleteCoach,
  useDeleteCoachPlayer,
  useDeleteTeamCoach,
  useUpdateCoach,
  useWizardCoachPlayers,
  useWizardCoaches,
  useWizardTeamCoaches,
  useWizardTeams,
} from "../queries";

function payload(coach: Coach, patch: Partial<Coach>) {
  return { firstName: coach.firstName, lastName: coach.lastName, email: coach.email, isEmployee: coach.isEmployee, isActive: coach.isActive, ...patch };
}

interface CardProps {
  coach: Coach;
  teams: Team[];
  teamName: Map<string, string>;
  coachLinks: TeamCoach[];
  playerLinks: CoachPlayerMembership[];
}

function CoachCard({ coach, teams, teamName, coachLinks, playerLinks }: CardProps) {
  const update = useUpdateCoach();
  const del = useDeleteCoach();
  const addTeamCoach = useCreateTeamCoach();
  const delTeamCoach = useDeleteTeamCoach();
  const addPlayer = useCreateCoachPlayer();
  const delPlayer = useDeleteCoachPlayer();

  const [first, setFirst] = useState(coach.firstName);
  const [last, setLast] = useState(coach.lastName);
  const [linkTeam, setLinkTeam] = useState("");
  const [linkRole, setLinkRole] = useState<TeamCoachRole | "PLAYER">("MAIN");

  const firstTeam = teams[0]?.id ?? "";
  const addLink = () => {
    const teamId = linkTeam || firstTeam;
    if ("" === teamId) {
      return;
    }
    if ("PLAYER" === linkRole) {
      addPlayer.mutate({ teamId, coachId: coach.id, isActive: true });
    } else {
      addTeamCoach.mutate({ teamId, coachId: coach.id, role: linkRole });
    }
  };

  return (
    <div className="rounded-lg border border-border bg-card p-3">
      <div className="flex flex-wrap items-center gap-2">
        <Input
          aria-label="Prénom"
          className="h-8 w-32"
          value={first}
          onChange={(e) => setFirst(e.target.value)}
          onBlur={() => first.trim() && first !== coach.firstName && update.mutate({ id: coach.id, body: payload(coach, { firstName: first.trim() }) })}
        />
        <Input
          aria-label="Nom"
          className="h-8 w-32"
          value={last}
          onChange={(e) => setLast(e.target.value)}
          onBlur={() => last !== coach.lastName && update.mutate({ id: coach.id, body: payload(coach, { lastName: last }) })}
        />
        <label className="flex items-center gap-1 text-xs text-muted-foreground">
          <input type="checkbox" checked={coach.isEmployee} onChange={(e) => update.mutate({ id: coach.id, body: payload(coach, { isEmployee: e.target.checked }) })} />
          Salarié
        </label>
        <Button size="icon" variant="ghost" className="ml-auto size-8 text-destructive" aria-label="Supprimer le coach" onClick={() => del.mutate(coach.id)}>
          <Trash2 className="size-4" />
        </Button>
      </div>

      <div className="mt-2 flex flex-wrap gap-1.5">
        {coachLinks.map((link) => (
          <span key={link.id} className="flex items-center gap-1 rounded-full bg-accent/15 px-2 py-0.5 text-xs">
            {teamName.get(link.teamId) ?? "?"} · {link.role === "MAIN" ? "coach" : "adjoint"}
            <button type="button" aria-label="Retirer" onClick={() => delTeamCoach.mutate(link.id)}>
              <X className="size-3" />
            </button>
          </span>
        ))}
        {playerLinks.map((link) => (
          <span key={link.id} className="flex items-center gap-1 rounded-full border border-border px-2 py-0.5 text-xs">
            {teamName.get(link.teamId) ?? "?"} · joueur
            <button type="button" aria-label="Retirer" onClick={() => delPlayer.mutate(link.id)}>
              <X className="size-3" />
            </button>
          </span>
        ))}
      </div>

      <div className="mt-2 flex flex-wrap items-center gap-2">
        <Select aria-label="Équipe" className="h-8 w-40" value={linkTeam || firstTeam} onChange={(e) => setLinkTeam(e.target.value)}>
          {teams.map((t) => (
            <option key={t.id} value={t.id}>
              {t.name}
            </option>
          ))}
        </Select>
        <Select aria-label="Rôle" className="h-8 w-28" value={linkRole} onChange={(e) => setLinkRole(e.target.value as TeamCoachRole | "PLAYER")}>
          <option value="MAIN">Coach</option>
          <option value="ASSISTANT">Adjoint</option>
          <option value="PLAYER">Joueur</option>
        </Select>
        <Button size="sm" variant="outline" className="ml-auto" onClick={addLink} disabled={0 === teams.length}>
          <Plus className="size-4" />
          Lier
        </Button>
      </div>
    </div>
  );
}

export function CoachesStep() {
  const { data: coaches = [] } = useWizardCoaches();
  const { data: teams = [] } = useWizardTeams();
  const { data: teamCoaches = [] } = useWizardTeamCoaches();
  const { data: coachPlayers = [] } = useWizardCoachPlayers();
  const create = useCreateCoach();

  const [first, setFirst] = useState("");
  const [last, setLast] = useState("");
  const [employee, setEmployee] = useState(false);

  const teamName = new Map(teams.map((t) => [t.id, t.name]));

  const add = (event: FormEvent) => {
    event.preventDefault();
    if ("" === first.trim()) {
      return;
    }
    create.mutate({ firstName: first.trim(), lastName: last.trim() || null, isEmployee: employee, isActive: true });
    setFirst("");
    setLast("");
    setEmployee(false);
  };

  return (
    <div>
      <p className="mb-4 text-sm text-muted-foreground">Ajoutez vos coachs, marquez les salariés, et liez-les à des équipes (coach, adjoint) ou aux équipes où ils jouent.</p>

      <form onSubmit={add} className="mb-6 flex flex-wrap items-center gap-2 rounded-lg border border-border bg-card p-3">
        <Input aria-label="Prénom" placeholder="Prénom" className="h-9 w-40" value={first} onChange={(e) => setFirst(e.target.value)} />
        <Input aria-label="Nom" placeholder="Nom" className="h-9 w-40" value={last} onChange={(e) => setLast(e.target.value)} />
        <label className="flex items-center gap-1 text-sm text-muted-foreground">
          <input type="checkbox" checked={employee} onChange={(e) => setEmployee(e.target.checked)} />
          Salarié
        </label>
        <Button type="submit" size="icon" className="ml-auto size-8" disabled={create.isPending} title="Ajouter le coach" aria-label="Ajouter le coach">
          <Plus className="size-4" />
        </Button>
      </form>

      {0 === coaches.length ? (
        <p className="text-sm text-muted-foreground">Aucun coach pour le moment.</p>
      ) : (
        <div className="flex flex-col gap-3">
          {coaches.map((coach) => (
            <CoachCard
              key={coach.id}
              coach={coach}
              teams={teams}
              teamName={teamName}
              coachLinks={teamCoaches.filter((l) => l.coachId === coach.id)}
              playerLinks={coachPlayers.filter((l) => l.coachId === coach.id)}
            />
          ))}
        </div>
      )}
    </div>
  );
}
