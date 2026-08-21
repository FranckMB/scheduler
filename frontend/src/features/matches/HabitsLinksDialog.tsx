import { Plus, Sparkles, Trash2 } from "lucide-react";
import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { Modal } from "@/shared/components/ui/modal";
import { Select } from "@/shared/components/ui/select";
import { TeamSelect } from "@/shared/components/ui/team-select";

import type { Fixture, PriorityTier, Team, TeamLinkType, Venue } from "./api";
import { inferHabits } from "./lib/habitInference";
import { useCreateTeamLink, useCreateTeamMatchHabit, useDeleteTeamLink, useDeleteTeamMatchHabit, useTeamLinks, useTeamMatchHabits } from "./queries";

const DAY_LABELS = ["", "Lundi", "Mardi", "Mercredi", "Jeudi", "Vendredi", "Samedi", "Dimanche"];

interface HabitsLinksDialogProps {
  teams: Team[];
  tiers: PriorityTier[];
  venues: Venue[];
  fixtures: Fixture[];
  onClose: () => void;
}

/**
 * Habitudes & passerelles (P1-4 PR C) — the preferences layer of the module:
 * per-team habitual windows (declared, with inference SUGGESTIONS — accepted,
 * never imposed) and declared team bridges. Consumed today by the radar
 * estimation, the placement prefill and the grid ghosts; by the PR D solver
 * tomorrow.
 */
export function HabitsLinksDialog({ teams, tiers, venues, fixtures, onClose }: HabitsLinksDialogProps) {
  const habitsQuery = useTeamMatchHabits();
  const linksQuery = useTeamLinks();
  const createHabit = useCreateTeamMatchHabit();
  const deleteHabit = useDeleteTeamMatchHabit();
  const createLink = useCreateTeamLink();
  const deleteLink = useDeleteTeamLink();

  const [habitTeamId, setHabitTeamId] = useState(teams[0]?.id ?? "");
  const [habitDay, setHabitDay] = useState(6);
  const [habitTime, setHabitTime] = useState("15:30");
  const [habitVenueId, setHabitVenueId] = useState("");

  const [linkTeamAId, setLinkTeamAId] = useState("");
  const [linkTeamBId, setLinkTeamBId] = useState("");
  const [linkType, setLinkType] = useState<TeamLinkType>("NOT_SIMULTANEOUS");

  const habits = habitsQuery.data ?? [];
  const links = linksQuery.data ?? [];
  const suggestions = inferHabits(fixtures, habits);

  const teamName = (id: string): string => teams.find((t) => t.id === id)?.name ?? "Équipe ?";
  const venueName = (id: string | null): string | null => (null === id ? null : (venues.find((v) => v.id === id)?.name ?? null));

  const teamHabits = habits.filter((h) => h.teamId === habitTeamId);
  const teamSuggestions = suggestions.filter((s) => s.teamId === habitTeamId);

  const addHabit = (): void => {
    if ("" === habitTeamId || "" === habitTime || createHabit.isPending) {
      return;
    }
    createHabit.mutate({ teamId: habitTeamId, dayOfWeek: habitDay, kickoffTime: habitTime, ...("" !== habitVenueId ? { venueId: habitVenueId } : {}) });
  };

  const addLink = (): void => {
    if ("" === linkTeamAId || "" === linkTeamBId || linkTeamAId === linkTeamBId || createLink.isPending) {
      return;
    }
    createLink.mutate({ teamAId: linkTeamAId, teamBId: linkTeamBId, linkType });
  };

  return (
    <Modal label="Habitudes et passerelles" title="Habitudes & passerelles" onClose={onClose} size="lg">
      <div className="flex flex-col gap-4">
        <section className="flex flex-col gap-2">
          <h3 className="text-sm font-semibold">Habitudes de match</h3>
          <p className="text-xs text-muted-foreground">
            « SF3 joue le dimanche à 17h30 » — l'habitude pré-remplit le placement, protège les week-ends dont le
            calendrier n'est pas encore sorti (bloc « fenêtre protégée » sur la grille), et donne une heure
            estimée aux matchs extérieur sans horaire.
          </p>

          <TeamSelect aria-label="Équipe de l'habitude" teams={teams} tiers={tiers} value={habitTeamId} onChange={(e) => setHabitTeamId(e.target.value)} />

          {habitsQuery.isError ? <p className="text-sm text-destructive">Les habitudes n’ont pas pu être chargées.</p> : null}

          <ul className="flex flex-col gap-1">
            {teamHabits.map((habit) => (
              <li key={habit.id} className="flex items-center justify-between gap-2 rounded-md border border-border px-2 py-1 text-sm">
                <span>
                  {DAY_LABELS[habit.dayOfWeek] ?? "?"} {habit.kickoffTime}
                  {null !== venueName(habit.venueId) ? ` · ${venueName(habit.venueId)}` : ""}
                </span>
                <Button
                  variant="ghost"
                  size="icon"
                  className="size-7"
                  aria-label={`Supprimer l'habitude ${DAY_LABELS[habit.dayOfWeek] ?? "?"} ${habit.kickoffTime}`}
                  disabled={deleteHabit.isPending}
                  onClick={() => deleteHabit.mutate(habit.id)}
                >
                  <Trash2 className="size-4" />
                </Button>
              </li>
            ))}
          </ul>

          {teamSuggestions.map((suggestion) => (
            <div key={`${suggestion.teamId}-${suggestion.dayOfWeek}`} className="flex items-center justify-between gap-2 rounded-md border border-dashed border-accent/50 bg-accent/5 px-2 py-1 text-sm">
              <span className="flex items-center gap-1">
                <Sparkles className="size-3.5 shrink-0 text-accent" />
                Suggestion : {DAY_LABELS[suggestion.dayOfWeek] ?? "?"} {suggestion.kickoffTime}
                {null !== venueName(suggestion.venueId) ? ` · ${venueName(suggestion.venueId)}` : ""} ({suggestion.count} matchs)
              </span>
              <Button
                variant="outline"
                size="sm"
                disabled={createHabit.isPending}
                onClick={() =>
                  createHabit.mutate({
                    teamId: suggestion.teamId,
                    dayOfWeek: suggestion.dayOfWeek,
                    kickoffTime: suggestion.kickoffTime,
                    ...(null !== suggestion.venueId ? { venueId: suggestion.venueId } : {}),
                  })
                }
              >
                Accepter
              </Button>
            </div>
          ))}

          <div className="flex items-end gap-2">
            <label className="flex flex-col gap-1 text-xs text-muted-foreground">
              Jour
              <Select aria-label="Jour de l'habitude" className="h-8 w-28" value={habitDay} onChange={(e) => setHabitDay(Number(e.target.value))}>
                {[1, 2, 3, 4, 5, 6, 7].map((day) => (
                  <option key={day} value={day}>
                    {DAY_LABELS[day]}
                  </option>
                ))}
              </Select>
            </label>
            <label className="flex flex-col gap-1 text-xs text-muted-foreground">
              Heure
              <input aria-label="Heure de l'habitude" type="time" className="h-8 rounded-md border border-border bg-background px-2 text-sm" value={habitTime} onChange={(e) => setHabitTime(e.target.value)} />
            </label>
            <label className="flex flex-col gap-1 text-xs text-muted-foreground">
              Gymnase (optionnel)
              <Select aria-label="Gymnase de l'habitude" className="h-8 w-36" value={habitVenueId} onChange={(e) => setHabitVenueId(e.target.value)}>
                <option value="">—</option>
                {venues.map((v) => (
                  <option key={v.id} value={v.id}>
                    {v.name}
                  </option>
                ))}
              </Select>
            </label>
            <Button size="icon" className="size-8" aria-label="Ajouter l'habitude" title="Ajouter l'habitude" disabled={"" === habitTeamId || "" === habitTime || createHabit.isPending} onClick={addHabit}>
              <Plus className="size-4" />
            </Button>
          </div>
        </section>

        <section className="flex flex-col gap-2 border-t border-border pt-3">
          <h3 className="text-sm font-semibold">Passerelles entre équipes</h3>
          <p className="text-xs text-muted-foreground">
            « SM1 et SM2 partagent des joueurs » → leurs matchs ne doivent pas se chevaucher (le radar alerte).
            « L'un après l'autre » = enchaînement souhaité, que le placement automatique respectera.
          </p>

          {linksQuery.isError ? <p className="text-sm text-destructive">Les passerelles n’ont pas pu être chargées.</p> : null}

          <ul className="flex flex-col gap-1">
            {links.map((link) => (
              <li key={link.id} className="flex items-center justify-between gap-2 rounded-md border border-border px-2 py-1 text-sm">
                <span>
                  {teamName(link.teamAId)} ↔ {teamName(link.teamBId)} ·{" "}
                  {"NOT_SIMULTANEOUS" === link.linkType ? "jamais en même temps" : "l'un après l'autre"}
                </span>
                <Button
                  variant="ghost"
                  size="icon"
                  className="size-7"
                  aria-label={`Supprimer la passerelle ${teamName(link.teamAId)} – ${teamName(link.teamBId)}`}
                  disabled={deleteLink.isPending}
                  onClick={() => deleteLink.mutate(link.id)}
                >
                  <Trash2 className="size-4" />
                </Button>
              </li>
            ))}
          </ul>

          <div className="flex items-end gap-2">
            <TeamSelect aria-label="Première équipe du lien" className="w-32" teams={teams} tiers={tiers} placeholder="Équipe A…" value={linkTeamAId} onChange={(e) => setLinkTeamAId(e.target.value)} />
            <TeamSelect aria-label="Seconde équipe du lien" className="w-32" teams={teams} tiers={tiers} placeholder="Équipe B…" value={linkTeamBId} onChange={(e) => setLinkTeamBId(e.target.value)} />
            <Select aria-label="Type de lien" className="h-9 w-44" value={linkType} onChange={(e) => setLinkType(e.target.value as TeamLinkType)}>
              <option value="NOT_SIMULTANEOUS">Jamais en même temps</option>
              <option value="BACK_TO_BACK">L'un après l'autre</option>
            </Select>
            <Button
              size="icon"
              className="size-8"
              aria-label="Ajouter la passerelle"
              title="Ajouter la passerelle"
              disabled={"" === linkTeamAId || "" === linkTeamBId || linkTeamAId === linkTeamBId || createLink.isPending}
              onClick={addLink}
            >
              <Plus className="size-4" />
            </Button>
          </div>
        </section>

        <div className="flex justify-end">
          <Button variant="outline" size="sm" onClick={onClose}>
            Fermer
          </Button>
        </div>
      </div>
    </Modal>
  );
}
