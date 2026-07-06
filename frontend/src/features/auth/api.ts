import { api } from "@/shared/api/client";
import type { MembershipStatus } from "@/shared/stores/authStore";

export interface MeSeason {
  id: string;
  name: string;
  startDate: string;
  endDate: string;
  isCurrent: boolean;
  isReadonly: boolean;
}

export interface MeResponse {
  id: string;
  email: string;
  firstName: string;
  lastName: string;
  membershipStatus: MembershipStatus;
  role: string | null;
  club: {
    id: string;
    name: string;
    onboardingCompleted: boolean;
    logoUrl: string | null;
    accentColor: string | null;
    accentPalette: string[] | null;
    schoolZone: string | null;
  } | null;
  /** Gates of the SELECTED season (X-Season-Id), else the current one. */
  baselineScheduleId: string | null;
  /** Sticky cockpit-unlock milestone (ISO) of the selected season. */
  socleValidatedAt: string | null;
  hasGenerated: boolean;
  /** All the club's seasons, startDate ASC. */
  seasons: MeSeason[];
  currentSeasonId: string | null;
}

export interface TransitionSeasonResponse {
  seasonId: string;
  name: string;
  startDate: string;
  endDate: string;
  counts: Record<string, number>;
}

/** Copy the current season's entries into a fresh N+1 draft (409 carries existingSeasonId). */
export function transitionSeason(sourceSeasonId: string): Promise<TransitionSeasonResponse> {
  return api.post(`seasons/${sourceSeasonId}/transition`).json();
}

export interface RegisterPayload {
  email: string;
  password: string;
  firstName: string;
  lastName: string;
  ara: string;
  club_name: string;
}

export interface RegisterResponse {
  token: string;
  membershipStatus: MembershipStatus;
  user: { id: string; email: string };
}

export interface PendingMember {
  id: string;
  userId: string;
  email: string;
  firstName: string;
  lastName: string;
}

export function login(body: { email: string; password: string }): Promise<{ token: string }> {
  return api.post("login", { json: body }).json();
}

export function register(body: RegisterPayload): Promise<RegisterResponse> {
  return api.post("register", { json: body }).json();
}

export function getMe(): Promise<MeResponse> {
  return api.get("me").json();
}

export function forgotPassword(email: string): Promise<unknown> {
  return api.post("password/forgot", { json: { email } }).json();
}

export function resetPassword(body: { token: string; password: string }): Promise<unknown> {
  return api.post("password/reset", { json: body }).json();
}

export function getPendingMembers(): Promise<{ members: PendingMember[] }> {
  return api.get("memberships/pending").json();
}

export function approveMember(id: string): Promise<unknown> {
  return api.post(`memberships/${id}/approve`).json();
}

export async function rejectMember(id: string): Promise<void> {
  await api.post(`memberships/${id}/reject`);
}
