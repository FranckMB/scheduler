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

/** FFBB institutional contact block (lot C) — league or committee. */
export interface FfbbOrganisme {
  name: string;
  address: string | null;
  postalCode: string | null;
  city: string | null;
  phone: string | null;
  email: string | null;
  logoUrl: string | null;
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
    accentColorDark: string | null;
    accentPalette: string[] | null;
    schoolZone: string | null;
    league: string | null;
    ffbbClubCode: string | null;
    committeeCode: string | null;
    contactPhone: string | null;
    contactEmail: string | null;
    address: string | null;
    correspondentName: string | null;
    correspondentPhone: string | null;
    correspondentEmail: string | null;
    presidentName: string | null;
    presidentPhone: string | null;
    presidentEmail: string | null;
    mainVenueName: string | null;
    mainVenueAddress: string | null;
    // FFBB autofill (lot C): institutional club data + shared league/committee blocks.
    postalCode: string | null;
    city: string | null;
    website: string | null;
    latitude: number | null;
    longitude: number | null;
    ffbbCommittee: FfbbOrganisme | null;
    ffbbLeague: FfbbOrganisme | null;
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
  /** RGPD : acceptation CGU + politique de confidentialité (obligatoire). */
  consent: boolean;
}

/** Register never authenticates: it returns an identical neutral 202 for a fresh
 *  or an already-registered email (A3 anti-enumeration). The JWT is issued only by
 *  verifyEmail once the emailed link is followed. */
export interface RegisterResponse {
  status: string;
}

export interface VerifyEmailResponse {
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

export function verifyEmail(token: string): Promise<VerifyEmailResponse> {
  return api.post("register/verify", { json: { token } }).json();
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
