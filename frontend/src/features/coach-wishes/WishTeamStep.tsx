import { Input } from "@/shared/components/ui/input";

import { DAY_LABELS, frDate, sectionKey, type SectionState } from "./wishSections";

interface WishTeamStepProps {
  team: { id: string; name: string };
  weeks: string[];
  sections: Map<string, SectionState>;
  onPatch: (key: string, next: Partial<SectionState>) => void;
  onToggleDay: (key: string, day: number) => void;
}

/**
 * Une étape du parcours = UNE équipe et ses semaines (lot E, P2-24). Reprend le markup
 * fieldset de la page unique historique — le coach ne voit plus que son équipe courante.
 */
export function WishTeamStep({ team, weeks, sections, onPatch, onToggleDay }: WishTeamStepProps) {
  return (
    <div className="space-y-4">
      {weeks.map((week) => {
        const key = sectionKey(team.id, week);
        const s = sections.get(key) as SectionState;
        return (
          <fieldset key={key} className="rounded-lg border border-border bg-card p-3">
            <legend className="px-1 text-sm font-medium">
              {team.name} · semaine du {frDate(week)}
            </legend>
            <label className="mt-2 flex items-center gap-2 text-sm">
              Séances souhaitées
              <Input
                type="number"
                min={0}
                max={7}
                aria-label={`Séances souhaitées — ${team.name}, semaine du ${frDate(week)}`}
                className="h-8 w-16"
                value={s.slotsWanted}
                onChange={(e) => onPatch(key, { slotsWanted: Math.max(0, Math.min(7, Number(e.target.value) || 0)) })}
              />
            </label>
            <div className="mt-2">
              <span className="text-sm text-muted-foreground">Jours d'indisponibilité</span>
              <div className="mt-1 flex flex-wrap gap-1.5">
                {DAY_LABELS.map(({ day, label }) => (
                  <label key={day} className="flex items-center gap-1 rounded-md border border-border px-2 py-1.5 text-xs">
                    <input type="checkbox" className="size-3.5 accent-[var(--accent)]" checked={s.days.has(day)} onChange={() => onToggleDay(key, day)} aria-label={`${label} indisponible — ${team.name}, semaine du ${frDate(week)}`} />
                    {label}
                  </label>
                ))}
              </div>
            </div>
            <label className="mt-2 block text-sm">
              <span className="text-muted-foreground">Commentaire</span>
              <textarea
                className="mt-1 w-full rounded-md border border-border bg-background p-2 text-sm"
                rows={2}
                aria-label={`Commentaire — ${team.name}, semaine du ${frDate(week)}`}
                value={s.comment}
                onChange={(e) => onPatch(key, { comment: e.target.value })}
              />
            </label>
          </fieldset>
        );
      })}
    </div>
  );
}
