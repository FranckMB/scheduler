import { api } from "@/shared/api/client";
import { collection, collectionAll } from "@/shared/api/collection";

export type Gender = "M" | "F" | "MIXTE";

export interface Team {
  id: string;
  name: string;
  sportCategoryId: string;
  priorityTierId: number;
  tierOrder: number;
  gender: Gender | null;
  sessionsPerWeek: number;
  isActive: boolean;
}

export interface SportCategory {
  id: string;
  name: string;
  sortOrder: number;
}

export interface PriorityTier {
  id: number;
  label: string;
  name: string;
  color: string | null;
}

export interface TeamPayload {
  name: string;
  sportCategoryId?: string;
  priorityTierId?: number;
  tierOrder?: number;
  gender?: Gender | null;
  sessionsPerWeek?: number;
  isActive?: boolean;
}

export const listTeams = (): Promise<Team[]> => collectionAll<Team>("teams");
export const listSportCategories = (): Promise<SportCategory[]> => collection<SportCategory>("sport_categories");
export const listPriorityTiers = (): Promise<PriorityTier[]> => collection<PriorityTier>("priority_tiers");

export const createTeam = (body: TeamPayload): Promise<Team> => api.post("teams", { json: body }).json();
export const updateTeam = (id: string, body: TeamPayload): Promise<Team> => api.put(`teams/${id}`, { json: body }).json();
export const deleteTeam = (id: string): Promise<void> => api.delete(`teams/${id}`).then(() => undefined);
