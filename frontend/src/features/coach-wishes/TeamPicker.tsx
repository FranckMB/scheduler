import { useState } from "react";

import type { PriorityTier, Team } from "@/features/wizard/api";
import { groupTeamsByTier, tierGroupLabel } from "@/shared/lib/teamTiers";
import { cn } from "@/shared/lib/utils";

/**
 * P3-15 — CHOISIR PARMI 49 ÉQUIPES SANS DÉROULER 49 LIGNES.
 *
 * Le retour du fondateur ne visait pas la modale mais son contenu : « elle affiche TOUTES
 * les équipes et elle est donc illisible ». Une ligne par équipe, c'est 49 lignes sur un
 * club réel, et aucun geste de masse — alors que le cas courant est « je sollicite tout le
 * monde ».
 *
 * Trois leviers, dans cet ordre d'importance :
 *  1. AU REPOS, une seule ligne : « 49 équipes sur 49 · Modifier ». Le cas courant ne
 *     demande plus aucun geste, puisque tout est coché par défaut (voir `CampaignDialog`).
 *  2. DÉPLIÉ, des puces qui s'enroulent au lieu de lignes empilées : 49 équipes tiennent
 *     en quelques lignes. Même idiome que le sélecteur de jours des contraintes.
 *  3. Des raccourcis « tout / aucune », globaux ET par rang — le rang étant déjà le tri de
 *     référence partout où une équipe se choisit (`groupTeamsByTier`).
 *
 * ⚠ Les puces sont des `button` avec `aria-pressed`, pas des cases déguisées : un lecteur
 * d'écran doit entendre un état, pas deviner une couleur.
 */
export function TeamPicker({ teams, tiers, selected, onChange }: { teams: Team[]; tiers: PriorityTier[]; selected: Set<string>; onChange: (next: Set<string>) => void }) {
  const [open, setOpen] = useState(false);
  const groups = groupTeamsByTier(teams, tiers);

  const setMany = (ids: string[], picked: boolean) => {
    const next = new Set(selected);
    for (const id of ids) {
      if (picked) {
        next.add(id);
      } else {
        next.delete(id);
      }
    }
    onChange(next);
  };
  const toggle = (id: string) => setMany([id], !selected.has(id));

  const chosen = teams.filter((t) => selected.has(t.id)).length;
  const summary = 0 === teams.length ? "Aucune équipe avec un coach rattaché" : chosen === teams.length ? `Toutes les équipes (${teams.length})` : `${chosen} équipe${chosen > 1 ? "s" : ""} sur ${teams.length}`;

  return (
    <div className="mt-4">
      <div className="flex flex-wrap items-center gap-2">
        <span className="text-sm font-medium">Équipes</span>
        {/* Le résumé est un TEXTE, pas seulement un compteur dans le bouton : il reste lu
            quand le sélecteur est déplié, et sert de repère après un « tout / aucune ». */}
        <span className="text-sm text-muted-foreground">{summary}</span>
        {teams.length > 0 ? (
          <button type="button" aria-expanded={open} className="text-sm text-accent underline underline-offset-2" onClick={() => setOpen((prev) => !prev)}>
            {open ? "Replier" : "Modifier"}
          </button>
        ) : null}
      </div>

      {open && teams.length > 0 ? (
        <div className="mt-2 space-y-2 rounded-md border border-border p-2">
          <div className="flex items-center gap-2 text-xs">
            <span className="text-muted-foreground">Toutes :</span>
            <BulkButton label="tout cocher" onClick={() => setMany(teams.map((t) => t.id), true)} />
            <BulkButton label="tout décocher" onClick={() => setMany(teams.map((t) => t.id), false)} />
          </div>

          {groups.map((group) => {
            const ids = group.teams.map((t) => t.id);
            const allPicked = ids.every((id) => selected.has(id));
            const label = tierGroupLabel(group.tier);
            return (
              <div key={group.tier?.id ?? "orphan"}>
                <div className="flex items-center gap-2">
                  <span className="text-xs font-medium text-muted-foreground">{label}</span>
                  {/* Un seul bouton par groupe, dont le LIBELLÉ dit ce qu'il va faire —
                      deux boutons « tout / aucune » par rang doubleraient la hauteur qu'on
                      vient de gagner. */}
                  <BulkButton label={allPicked ? `décocher ${label}` : `cocher ${label}`} onClick={() => setMany(ids, !allPicked)} />
                </div>
                <div className="mt-1 flex flex-wrap gap-1">
                  {group.teams.map((t) => {
                    const picked = selected.has(t.id);
                    return (
                      <button
                        key={t.id}
                        type="button"
                        aria-pressed={picked}
                        onClick={() => toggle(t.id)}
                        className={cn("rounded-md border px-2 py-1 text-xs", picked ? "border-accent bg-accent text-accent-foreground" : "border-border text-muted-foreground")}
                      >
                        {t.name}
                      </button>
                    );
                  })}
                </div>
              </div>
            );
          })}
        </div>
      ) : null}
    </div>
  );
}

function BulkButton({ label, onClick }: { label: string; onClick: () => void }) {
  return (
    <button type="button" onClick={onClick} className="rounded border border-border px-2 py-0.5 text-xs text-muted-foreground hover:text-foreground">
      {label}
    </button>
  );
}
