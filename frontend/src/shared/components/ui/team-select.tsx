import type { SelectHTMLAttributes } from "react";

import { groupTeamsByTier, type TeamLike, tierGroupLabel, type TierLike } from "@/shared/lib/teamTiers";

import { Select } from "./select";

interface TeamSelectProps<T extends TeamLike> extends Omit<SelectHTMLAttributes<HTMLSelectElement>, "children"> {
  teams: T[];
  tiers: TierLike[];
  /** Optional leading option (e.g. a placeholder) rendered before the groups. */
  placeholder?: string;
}

/**
 * A team picker whose options are grouped by priority tier (S/A/B/C/D), the
 * same découpage as the teams step — so the selector order mirrors the manager's
 * ranking. Falls back to a flat list when the tiers are not loaded yet.
 */
export function TeamSelect<T extends TeamLike>({ teams, tiers, placeholder, ...props }: TeamSelectProps<T>) {
  const groups = tiers.length > 0 ? groupTeamsByTier(teams, tiers) : [];
  return (
    <Select {...props}>
      {placeholder !== undefined ? <option value="">{placeholder}</option> : null}
      {groups.length > 0
        ? groups.map((group) => (
            <optgroup key={group.tier.id} label={tierGroupLabel(group.tier)}>
              {group.teams.map((team) => (
                <option key={team.id} value={team.id}>
                  {team.name}
                </option>
              ))}
            </optgroup>
          ))
        : teams.map((team) => (
            <option key={team.id} value={team.id}>
              {team.name}
            </option>
          ))}
    </Select>
  );
}
