import { ArrowLeftRight } from "lucide-react";

import { DAYS, toHourMinute } from "./lib/grid";
import { toReplaceReasonLabel } from "./lib/toReplaceReason";
import type { SocleDeviationMoved, SocleDeviationUnplaced } from "./api";

const DAY_LABEL = new Map(DAYS.map((d) => [d.n, d.label]));

/** « 18:30 » → « 18h30 » : le format du fondateur pour ces lignes (présentation pure). */
const hLabel = (time: string): string => toHourMinute(time).replace(":", "h");

/** « Mar 18h30 Matéo » — un placement lisible (jour, heure, gymnase). */
const placementLabel = (day: number, time: string, venue: string): string => `${DAY_LABEL.get(day) ?? "?"} ${hLabel(time)} ${venue}`;

interface SocleDeviationPanelProps {
  moved: SocleDeviationMoved[];
  unplaced: SocleDeviationUnplaced[];
  teamName: (teamId: string) => string;
  venueName: (venueId: string) => string;
}

/**
 * P2-44 PR-5 (ADR-0004) — NOMME les écarts entre la version affichée d'un plan de FERMETURE et le
 * socle : un AGRÉGAT en tête (« N déplacées, M à replacer » — une longueur de liste, présentation
 * pure) puis le détail LIGNE À LIGNE. Deux catégories seulement (déplacée / non replacée). Les
 * données — équipes, placements, RAISON — viennent TOUTES du backend (`SocleDeviation`) : le front
 * ne redérive RIEN, il présente.
 *
 * Distinct de {@see ToReplaceList} (décision fondateur : les deux panneaux coexistent le temps de
 * la session d'écran) : titre « Écarts avec le planning de saison », gabarit neutre (pas le bandeau
 * warning), et il porte en plus les DÉPLACÉES que l'autre n'a jamais eues. Une raison NULLE (absence
 * inexpliquée par la sélection) est rendue SANS étiquette — jamais une raison inventée.
 */
export function SocleDeviationPanel({ moved, unplaced, teamName, venueName }: SocleDeviationPanelProps) {
  if (0 === moved.length && 0 === unplaced.length) {
    return null;
  }

  const parts: string[] = [];
  if (moved.length > 0) {
    parts.push(`${moved.length} séance${moved.length > 1 ? "s" : ""} déplacée${moved.length > 1 ? "s" : ""}`);
  }
  if (unplaced.length > 0) {
    parts.push(`${unplaced.length} à replacer`);
  }

  return (
    <section className="mb-4 rounded-md border border-border bg-muted/40 px-3 py-2 text-sm" aria-label={`Écarts avec le planning de saison (${moved.length + unplaced.length})`}>
      <p className="flex items-center gap-1.5 font-medium text-foreground">
        <ArrowLeftRight aria-hidden="true" className="size-4 text-muted-foreground" />
        Écarts avec le planning de saison
      </p>
      <p className="mt-0.5 text-xs text-muted-foreground">{parts.join(" · ")}</p>
      <ul className="mt-1.5 flex flex-col gap-1">
        {moved.map((entry, i) => (
          <li key={`m-${entry.teamId}-${i}`} className="flex flex-wrap items-center gap-y-0.5">
            <span className="font-medium text-foreground">{teamName(entry.teamId)}</span>
            {" · "}
            <span className="text-muted-foreground">{placementLabel(entry.from.dayOfWeek, entry.from.startTime, venueName(entry.from.venueId))}</span>
            {" → "}
            <span className="text-foreground">{placementLabel(entry.to.dayOfWeek, entry.to.startTime, venueName(entry.to.venueId))}</span>
          </li>
        ))}
        {unplaced.map((entry, i) => (
          <li key={`u-${entry.teamId}-${i}`} className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
            <span className="font-medium text-foreground">{teamName(entry.teamId)}</span>
            <span className="text-muted-foreground">{placementLabel(entry.dayOfWeek, entry.startTime, venueName(entry.venueId))}</span>
            {null !== entry.reason ? <span className="rounded-full border border-border px-2 py-0.5 text-xs text-foreground">{toReplaceReasonLabel(entry.reason)}</span> : null}
          </li>
        ))}
      </ul>
    </section>
  );
}
