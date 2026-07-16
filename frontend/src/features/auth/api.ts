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

/** THE season's plan and where it stands (ADR-0002). See `MeResponse.seasonPlan`. */
export interface MeSeasonPlan {
  id: string;
  name: string;
  chosenScheduleId: string | null;
  hasFinishedVersion: boolean;
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
  /**
   * THE season's plan (ADR-0002) for the SELECTED season (X-Season-Id), else the
   * current one — the single seam onto "where is this season at?".
   *
   * `chosenScheduleId` = the version the manager settled on; it IS the season's
   * calendar, and null means the plan is still an espace de travail.
   * `hasFinishedVersion` = the club has generated at least once, which is what
   * unlocks the cockpit — independent of the pointer, so reopening a plan does
   * not throw the manager back into the guided wizard.
   *
   * null only for a season with no plan row at all: every creation path provisions
   * one, so treat it as an empty plan rather than a special case.
   */
  seasonPlan: MeSeasonPlan | null;
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

/**
 * Rename THE season plan (ADR-0002 inv. 12: the name lives on the plan, not on
 * the season — one writer, so a season edit can never clobber it).
 */
export function renamePlanning(planId: string, name: string): Promise<unknown> {
  return api.put(`schedule_plans/${planId}`, { json: { name } }).json();
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
