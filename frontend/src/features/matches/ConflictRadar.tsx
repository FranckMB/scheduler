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
  if ("VENUE_UNAVAILABLE" === conflict.type && conflict.fixture) {
    return `Match ${teamName(teams, conflict.fixture.teamId)} du ${frDate(conflict.fixture.matchDate)} — gymnase indisponible, à repositionner`;
  }
  if ("TEAM_LINK_OVERLAP" === conflict.type && conflict.left && conflict.right) {
    return `Équipes liées en même temps — ${teamName(teams, conflict.left.teamId)} et ${teamName(teams, conflict.right.teamId)} (joueurs partagés)`;
  }
  return "Conflit";
}

/** « heure estimée » when a side's window borrows the team's habit (P1-4 PR C). */
function estimatedTag(conflict: Conflict): boolean {
  return true === conflict.fixture?.estimatedKickoff || true === conflict.left?.estimatedKickoff || true === conflict.right?.estimatedKickoff;
}

function frDate(ymd: string): string {
  return new Date(`${ymd}T12:00:00Z`).toLocaleDateString("fr-FR", { day: "numeric", month: "short" });
}

/** The same-coach conflict radar (server-computed). Empty = green "no clash" state. */
export function ConflictRadar({ conflicts, teams, coaches }: ConflictRadarProps) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <AlertTriangle className="size-4 text-warning" />
          Radar de conflits
          {conflicts.length > 0 ? <span className="rounded-full bg-warning/15 px-2 text-xs text-warning">{conflicts.length}</span> : null}
        </CardTitle>
      </CardHeader>
      <CardContent>
        {0 === conflicts.length ? (
          <p className="flex items-center gap-2 text-sm text-muted-foreground">
            <ShieldCheck className="size-4 text-success" />
            Aucun conflit détecté.
          </p>
        ) : (
          <ul className="flex flex-col gap-2">
            {conflicts.map((conflict, index) => (
              <li key={`${conflict.type}-${conflict.coachId ?? conflict.unavailabilityId ?? ""}-${index}`} className="rounded-md border border-warning/30 bg-warning/5 px-3 py-2 text-sm">
                {/* VENUE_UNAVAILABLE porte une indispo, pas un coach ni une fenêtre. */}
                <p className="font-medium">
                  {undefined !== conflict.coachId
                    ? coachName(coaches, conflict.coachId)
                    : "TEAM_LINK_OVERLAP" === conflict.type
                      ? "Passerelle violée"
                      : `Gymnase indisponible${null != conflict.label && "" !== conflict.label ? ` (${conflict.label})` : ""}`}
                </p>
                <p className="text-muted-foreground">
                  {conflictSummary(conflict, teams)}
                  {estimatedTag(conflict) ? <span className="ml-1 rounded bg-muted px-1 text-[10px] uppercase tracking-wide">heure estimée</span> : null}
                </p>
                {undefined !== conflict.start && undefined !== conflict.end ? (
                  <p className="text-xs text-muted-foreground">
                    {whenLabel(conflict.start)} → {whenLabel(conflict.end)}
                  </p>
                ) : null}
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  );
}
