import { api } from "@/shared/api/client";
import type { AssignableRole } from "@/shared/lib/roles";
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
  currentStructureHash: string | null;
}

/**
 * P1-3 — droits de l'offre EFFECTIVE du club, calculés SERVEUR (PlanEntitlements)
 * et servis tels quels : le front n'en RECALCULE aucun (piège P2-8). `creditsMax`
 * n'est non-null qu'en Découverte bridée (pool de sorties actif) — null en
 * payant/bêta/démo, et c'est LE drapeau « faut-il afficher les mécanismes de
 * crédits ? ». Les booléens `can*` sont les seuls juges du « peut-on encore
 * sortir ? » ; `creditsMax - creditsUsed` n'est qu'un affichage.
 */
export interface ClubEntitlements {
  planCode: string;
  planName: string;
  /** Cap d'équipes de l'offre (null = illimité : Découverte, bêta, sans-limite, démo). */
  maxTeams: number | null;
  teamsUsed: number;
  /** Taille du pool de crédits ; null hors Découverte bridée. */
  creditsMax: number | null;
  creditsUsed: number;
  canGenerate: boolean;
  canPlaceMatches: boolean;
  canExportPdf: boolean;
  seasonTransition: boolean;
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
  website: string | null;
}

export interface MeResponse {
  id: string;
  email: string;
  /** P4-74 — adresse en attente de confirmation (le compte garde son adresse actuelle). null = aucune. */
  pendingEmail: string | null;
  firstName: string;
  lastName: string;
  membershipStatus: MembershipStatus;
  role: string | null;
  /** P3-4 : la demande de CRÉATION du club (la plus récente) — null dès qu'un membership existe. */
  clubRequest: { status: "pending" | "approved" | "refused" | "expired"; clubName: string; ara: string; clubEmailKnown: boolean } | null;
  club: {
    id: string;
    name: string;
    onboardingCompleted: boolean;
    logoUrl: string | null;
    accentColor: string | null;
    accentColorDark: string | null;
    accentPalette: string[] | null;
    schoolZone: string | null;
    /** P2-21 lot A — vérité serveur : les équipes viennent de l'import FFBB. */
    ffbbTeamsImported?: boolean;
    /** P4-16/P2-4 — « aujourd'hui » simulé d'un club démo (null = horloge réelle). */
    demoToday?: string | null;
    league: string | null;
    ffbbClubCode: string | null;
    committeeCode: string | null;
    contactPhone: string | null;
    contactEmail: string | null;
    address: string | null;
    // FFBB autofill (lot C): institutional club data + shared league/committee blocks.
    postalCode: string | null;
    city: string | null;
    website: string | null;
    latitude: number | null;
    longitude: number | null;
    ffbbCommittee: FfbbOrganisme | null;
    ffbbLeague: FfbbOrganisme | null;
    /** P1-3 — droits de l'offre effective ; absent tant qu'aucune saison n'est résolue. */
    entitlements?: ClubEntitlements;
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
  /** P5-3b — jeton Cloudflare Turnstile. Optionnel : ignoré tant que Turnstile est
   *  inactif côté serveur (aucune sitekey). Absent/invalide quand actif → 403. */
  turnstileToken?: string;
}

/**
 * P5-3b — config publique de la page d'inscription. `turnstileSiteKey` non-null
 * = Turnstile actif (le front rend le widget) ; null = inactif (écran actuel).
 */
export interface RegisterConfig {
  turnstileSiteKey: string | null;
}

/** GET /api/register/config — publique (préfixe ^/api/register). */
export function registerConfig(): Promise<RegisterConfig> {
  return api.get("register/config").json();
}

/** Register never authenticates: it returns an identical neutral 202 for a fresh
 *  or an already-registered email (A3 anti-enumeration). The JWT is issued only by
 *  verifyEmail once the emailed link is followed. */
export interface RegisterResponse {
  status: string;
}

/** SEC-16 : plus de `token` — l'identité est posée en cookie httpOnly par le serveur. */
export interface VerifyEmailResponse {
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

/** Un membre ACTIF du club (écran de gestion). `isSelf` : la ligne du gestionnaire courant. */
export interface ActiveMember {
  id: string;
  userId: string;
  email: string;
  firstName: string;
  lastName: string;
  role: string;
  isSelf: boolean;
}

/** Un membre DÉSACTIVÉ (réactivable) — même forme, sans geste sur soi. */
export interface DeactivatedMember {
  id: string;
  userId: string;
  email: string;
  firstName: string;
  lastName: string;
  role: string;
}

/**
 * `GET /api/memberships?includeDeactivated=1` : membres actifs + désactivés en une
 * lecture (un seul readState pour toute la section). `deactivated` peut manquer
 * (réponse sans le paramètre) — le traiter comme vide, jamais comme une erreur.
 */
export interface MembersResponse {
  members: ActiveMember[];
  deactivated?: DeactivatedMember[];
}

/**
 * SEC-16 : la réponse n'a PLUS DE CORPS — lexik retire le jeton du JSON dès qu'il
 * le pose en cookie (`remove_token_from_body_when_cookies_used`). Appeler `.json()`
 * ici jetterait sur un corps vide : le succès se lit sur l'absence d'erreur.
 */
export function login(body: { email: string; password: string }): Promise<unknown> {
  return api.post("login", { json: body });
}

/** SEC-16 : seul le serveur peut effacer un cookie httpOnly — sans cet appel, la
 *  session resterait valide jusqu'à son expiration malgré un « Se déconnecter ». */
export function logout(): Promise<unknown> {
  return api.post("logout");
}

export function register(body: RegisterPayload): Promise<RegisterResponse> {
  return api.post("register", { json: body }).json();
}

export function verifyEmail(token: string): Promise<VerifyEmailResponse> {
  return api.post("register/verify", { json: { token } }).json();
}

/** SEC-16 : la confirmation repose un cookie httpOnly frais (nouvelle identité) — pas de jeton dans le corps. */
export interface ConfirmEmailChangeResponse {
  status: "email_confirmed";
  email: string;
}

/**
 * P4-74 — consommer le lien de confirmation de changement d'e-mail (PUBLIC : le
 * token EST l'identité). La bascule a lieu ici ; le serveur repose un cookie
 * pour la nouvelle adresse (le compte reste connecté).
 */
export function confirmEmailChange(token: string): Promise<ConfirmEmailChangeResponse> {
  return api.post("me/email/confirm", { json: { token } }).json();
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

/** Membres actifs + désactivés du club (management). Une lecture, un readState. */
export function getMembers(): Promise<MembersResponse> {
  return api.get("memberships", { searchParams: { includeDeactivated: "1" } }).json();
}

/** PR C : le rôle est REQUIS à l'approbation — le serveur refuse (422) un corps sans `role`. */
export function approveMember(id: string, role: AssignableRole): Promise<unknown> {
  return api.post(`memberships/${id}/approve`, { json: { role } }).json();
}

export function changeMemberRole(id: string, role: AssignableRole): Promise<unknown> {
  return api.post(`memberships/${id}/role`, { json: { role } }).json();
}

export async function deactivateMember(id: string): Promise<void> {
  await api.post(`memberships/${id}/deactivate`);
}

export async function reactivateMember(id: string): Promise<void> {
  await api.post(`memberships/${id}/reactivate`);
}

export async function rejectMember(id: string): Promise<void> {
  await api.post(`memberships/${id}/reject`);
}

/** P3-4 PR C — la page publique d'approbation (le token EST l'identité, pas de JWT). */
export interface ClubApprovalInfo {
  clubName: string;
  ara: string;
  requesterName: string;
  expiresAt: string;
}

export function getClubApproval(token: string): Promise<ClubApprovalInfo> {
  return api.get(`club-approvals/${encodeURIComponent(token)}`).json();
}

export function decideClubApproval(token: string, decision: "approve" | "refuse"): Promise<{ status: string }> {
  return api.post(`club-approvals/${encodeURIComponent(token)}`, { json: { decision } }).json();
}
