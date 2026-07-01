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

// --- Venues + availability slots (W2) ---

export interface Venue {
  id: string;
  name: string;
  color: string | null;
  canSplit: boolean;
  isActive: boolean;
}

export interface VenueTrainingSlot {
  id: string;
  venueId: string;
  dayOfWeek: number;
  startTime: string;
  durationMinutes: number;
  capacity: number;
}

export interface VenuePayload {
  name: string;
  color?: string | null;
  canSplit?: boolean;
  isActive?: boolean;
}

export interface SlotPayload {
  venueId: string;
  dayOfWeek: number;
  startTime: string;
  durationMinutes: number;
  capacity: number;
}

export const listVenues = (): Promise<Venue[]> => collectionAll<Venue>("venues");
export const listVenueSlots = (): Promise<VenueTrainingSlot[]> => collectionAll<VenueTrainingSlot>("venue_training_slots");
export const createVenue = (body: VenuePayload): Promise<Venue> => api.post("venues", { json: { source: "manual", ...body } }).json();
export const updateVenue = (id: string, body: VenuePayload): Promise<Venue> => api.put(`venues/${id}`, { json: { source: "manual", ...body } }).json();
export const deleteVenue = (id: string): Promise<void> => api.delete(`venues/${id}`).then(() => undefined);
export const createSlot = (body: SlotPayload): Promise<VenueTrainingSlot> => api.post("venue_training_slots", { json: body }).json();
export const deleteSlot = (id: string): Promise<void> => api.delete(`venue_training_slots/${id}`).then(() => undefined);
