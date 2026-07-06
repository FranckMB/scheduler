import { AlertTriangle, ShieldCheck } from "lucide-react";

import { Card, CardContent, CardHeader, CardTitle } from "@/shared/components/ui/card";

import type { Coach, Conflict, Team } from "./api";

interface ConflictRadarProps {
  conflicts: Conflict[];
  teams: Map<string, Team>;
  coaches: Map<string, Coach>;
}

function coachName(coaches: Map<string, Coach>, id: string): string {
  const coach = coaches.get(id);
  return coach ? `${coach.firstName} ${coach.lastName}` : "Coach ?";
}

function teamName(teams: Map<string, Team>, id: string): string {
  return teams.get(id)?.name ?? "Équipe ?";
}

/** "sam. 4 oct. 15:30" from an ISO datetime. */
function whenLabel(iso: string): string {
  const date = new Date(iso);
  return date.toLocaleString("fr-FR", { weekday: "short", day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" });
}

function conflictSummary(conflict: Conflict, teams: Map<string, Team>): string {
  if ("MATCH_MATCH" === conflict.type && conflict.left && conflict.right) {
    return `Deux matchs — ${teamName(teams, conflict.left.teamId)} et ${teamName(teams, conflict.right.teamId)}`;
  }
  if ("MATCH_TRAINING" === conflict.type && conflict.fixture && conflict.training) {
    return `Match ${teamName(teams, conflict.fixture.teamId)} × entraînement ${teamName(teams, conflict.training.teamId)}`;
  }
  return "Conflit";
}

/** The same-coach conflict radar (server-computed). Empty = green "no clash" state. */
export function ConflictRadar({ conflicts, teams, coaches }: ConflictRadarProps) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <AlertTriangle className="size-4 text-amber-500" />
          Radar de conflits
          {conflicts.length > 0 ? <span className="rounded-full bg-amber-500/15 px-2 text-xs text-amber-600 dark:text-amber-400">{conflicts.length}</span> : null}
        </CardTitle>
      </CardHeader>
      <CardContent>
        {0 === conflicts.length ? (
          <p className="flex items-center gap-2 text-sm text-muted-foreground">
            <ShieldCheck className="size-4 text-emerald-500" />
            Aucun conflit détecté.
          </p>
        ) : (
          <ul className="flex flex-col gap-2">
            {conflicts.map((conflict, index) => (
              <li key={`${conflict.type}-${conflict.coachId}-${index}`} className="rounded-md border border-amber-500/30 bg-amber-500/5 px-3 py-2 text-sm">
                <p className="font-medium">{coachName(coaches, conflict.coachId)}</p>
                <p className="text-muted-foreground">{conflictSummary(conflict, teams)}</p>
                <p className="text-xs text-muted-foreground">
                  {whenLabel(conflict.start)} → {whenLabel(conflict.end)}
                </p>
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  );
}
