import { Check, ChevronDown } from "lucide-react";
import { useId, useState } from "react";

import { cn } from "@/shared/lib/utils";

import type { GridResourceGroup } from "./lib/grid";
import type { ViewMode } from "./store";

const LABELS: Record<ViewMode, string> = {
  gymnase: "Gymnases",
  coach: "Coachs",
  equipe: "Équipes",
};

interface ResourceFilterProps {
  viewMode: ViewMode;
  /** Grouped resources — equipe view carries rank headers, other views one flat null-label group. */
  groups: GridResourceGroup[];
  selected: string[];
  onToggle: (id: string) => void;
  onClear: () => void;
}

export function ResourceFilter({ viewMode, groups, selected, onToggle, onClear }: ResourceFilterProps) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  // `useId` et non un id littéral : ce composant est monté DEUX fois dans la même page
  // (modale doléances — coachs et équipes), et deux `aria-controls` identiques
  // désigneraient le même panneau.
  const panelId = useId();

  if (groups.every((g) => 0 === g.resources.length)) {
    return null;
  }

  const needle = query.trim().toLowerCase();
  const filteredGroups = groups
    .map((g) => ({ ...g, resources: g.resources.filter((r) => r.label.toLowerCase().includes(needle)) }))
    .filter((g) => g.resources.length > 0);
  const count = selected.length;
  // Un filtre POSÉ doit se voir. Sans état visuel distinct, « Gymnases : 3 sélectionnés »
  // et « Gymnases : tous » portaient exactement le même habillage : une grille filtrée se
  // lisait comme une grille complète, et le gestionnaire concluait sur ce qu'il ne voyait
  // pas (P4-43). C'est ce silence, plus que la taille du bouton, qui le rendait invisible.
  const active = count > 0;
  const summary = active ? `${count} sélectionné${count > 1 ? "s" : ""}` : "tous";

  return (
    <div className="relative inline-block">
      {/* Motif « disclosure » : `aria-expanded` + `aria-controls`. Pas de
          `aria-haspopup="listbox"` — le panneau porte un champ de recherche et des boutons
          bascules, pas des `option` ; l'annoncer en listbox promettrait à l'AT une
          navigation qui n'existe pas. */}
      <button
        type="button"
        aria-expanded={open}
        aria-controls={panelId}
        onClick={() => setOpen((o) => !o)}
        className={cn(
          "flex h-8 items-center gap-2 rounded-md border bg-background px-3 text-sm",
          // ⚠ L'état actif ne porte AUCUN fond teinté, et son survol n'en pose pas non plus.
          // Mesuré : `text-accent` sur `bg-accent/10` tombe à 4.18:1 en thème clair, sur
          // `bg-muted` à 4.37:1 — sous les 4.5:1 que WCAG 1.4.3 exige d'un texte normal
          // (14 px medium n'est pas du « grand texte »). Même `accent/05` échoue (4.47:1).
          // Sur le fond nu : 4.77:1 en clair, 7.41:1 en sombre. La distinction se fait donc
          // par la bordure, la couleur du texte, la graisse — et le libellé lui-même.
          active ? "border-accent font-medium text-accent hover:ring-1 hover:ring-accent/50" : "border-border text-foreground hover:bg-muted",
        )}
      >
        <span className={cn("font-medium", active ? "" : "text-muted-foreground")}>{LABELS[viewMode]} :</span>
        <span>{summary}</span>
        <ChevronDown className={cn("size-3.5", active ? "" : "text-muted-foreground")} />
      </button>

      {open ? (
        <>
          {/* Voile de fermeture — `aria-hidden` sur un élément FOCUSABLE est une violation
              axe (`aria-hidden-focus`) : il faut aussi le sortir de l'ordre de tabulation,
              sinon le clavier atterrit sur un bouton que rien n'annonce. */}
          <button type="button" aria-hidden tabIndex={-1} className="fixed inset-0 z-50 cursor-default" onClick={() => setOpen(false)} />
          <div id={panelId} className="absolute z-[60] mt-1 w-72 rounded-md border border-border bg-card shadow-md">
            <div className="border-b border-border p-2">
              <input
                // eslint-disable-next-line jsx-a11y/no-autofocus -- search field inside a just-opened popover; focusing it is the expected behaviour
                autoFocus
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                placeholder="Rechercher…"
                className="h-8 w-full rounded-md border border-input bg-background px-2 text-sm outline-none focus:ring-2 focus:ring-ring"
              />
            </div>
            <ul className="max-h-64 overflow-y-auto p-1">
              {count > 0 ? (
                <li>
                  <button type="button" onClick={onClear} className="w-full rounded px-2 py-1 text-left text-xs text-muted-foreground hover:bg-muted">
                    Tout effacer ({count})
                  </button>
                </li>
              ) : null}
              {filteredGroups.map((group, gi) => (
                <li key={group.label ?? `flat-${gi}`}>
                  {null !== group.label ? (
                    <p className="px-2 pb-0.5 pt-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">{group.label}</p>
                  ) : null}
                  <ul>
                    {group.resources.map((resource) => {
                      // `isSelected` et non `active` : ce dernier nomme désormais l'état du
                      // BOUTON déclencheur (au moins une ressource cochée). Deux `active`
                      // imbriqués, et un style ajouté ici plus tard lirait le mauvais.
                      const isSelected = selected.includes(resource.id);
                      return (
                        <li key={resource.id}>
                          <button
                            type="button"
                            onClick={() => onToggle(resource.id)}
                            className="flex w-full items-center gap-2 rounded px-2 py-1 text-left text-sm hover:bg-muted"
                          >
                            <Check className={cn("size-4 shrink-0 text-accent", isSelected ? "" : "invisible")} />
                            <span className="truncate">{resource.label}</span>
                          </button>
                        </li>
                      );
                    })}
                  </ul>
                </li>
              ))}
              {0 === filteredGroups.length ? <li className="px-2 py-1 text-xs text-muted-foreground">Aucun résultat</li> : null}
            </ul>
          </div>
        </>
      ) : null}
    </div>
  );
}
