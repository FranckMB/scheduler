import type { Conflict } from "../api";

/**
 * Grouping/labelling of the GRADED diagnostic (P1-4 PR E2, cadrage §8). The
 * severity itself is emitted by the SERVER — this lib only sorts, groups and
 * labels; it never re-derives gravity (one truth, not two).
 */

export interface DiagnosticGroup {
  severity: number;
  title: string;
  /** Visual tone of the group (worst first). */
  tone: "destructive" | "warning" | "muted";
  /** Folded by default (severity 7: N info lines, not N alert cards). */
  folded: boolean;
  conflicts: Conflict[];
}

const GROUP_TITLES: Record<number, string> = {
  1: "Collision de salle",
  2: "Hors fenêtre ligue",
  3: "Coach principal en double",
  4: "Placement fragilisé",
  5: "À surveiller",
  6: "Calendriers incomplets",
  7: "Angles morts",
};

function toneOf(severity: number): DiagnosticGroup["tone"] {
  if (severity <= 2) {
    return "destructive";
  }
  return severity <= 5 ? "warning" : "muted";
}

/** Sort by severity (1 first) and group; severities 6-7 come folded with a count
 * (structural information, not collisions — N teams must read as N lines max). */
export function groupBySeverity(conflicts: Conflict[]): DiagnosticGroup[] {
  const bySeverity = new Map<number, Conflict[]>();
  for (const conflict of conflicts) {
    const severity = conflict.severity ?? 5;
    const bucket = bySeverity.get(severity) ?? [];
    bucket.push(conflict);
    bySeverity.set(severity, bucket);
  }

  return [...bySeverity.entries()]
    .sort(([a], [b]) => a - b)
    .map(([severity, items]) => ({
      severity,
      title: GROUP_TITLES[severity] ?? "Autres",
      tone: toneOf(severity),
      folded: severity >= 6,
      conflicts: items,
    }));
}
