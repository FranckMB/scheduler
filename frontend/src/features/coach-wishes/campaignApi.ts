import { api } from "@/shared/api/client";
import { collectionAll } from "@/shared/api/collection";

/**
 * Une campagne de collecte des doléances (feature #10, lot C2) : une par période de
 * vacances (ancrée à l'entrée MÈRE), modifiable (semaines/équipes/deadline). Le back
 * expose un token EN CLAIR par coach du périmètre courant ; le front construit le lien
 * `${origin}/doleances/${token}` que le gestionnaire copie dans WhatsApp (pas d'email en C2).
 */
export interface CampaignCoach {
  coachId: string;
  firstName: string;
  lastName: string;
  /** Préparé pour C3 (envoi email) — aucun effet en C2. */
  email: string | null;
  /** Secret 64 hex, sert à bâtir le lien personnel. */
  token: string;
  /** ISO 8601, ou null si le coach n'a pas encore répondu. */
  respondedAt: string | null;
  /** Dernier envoi du lien par email (C3) — null = jamais envoyé. */
  sentAt: string | null;
}

export interface CoachWishCampaign {
  id: string;
  calendarEntryId: string;
  /** Y-m-d — après cette date, les liens sont expirés (410). */
  deadline: string;
  /** Lundis Y-m-d des semaines retenues. */
  weeks: string[];
  teamIds: string[];
  /** Radar : coachs du périmètre courant / répondants / doléances à traiter. */
  totalCoachCount: number;
  respondedCoachCount: number;
  openWishCount: number;
  /** Dernière relance manuelle (C3) — le bouton se bloque le reste de la journée. */
  lastReminderAt: string | null;
  coaches: CampaignCoach[];
}

export interface CoachWishCampaignPayload {
  calendarEntryId: string;
  deadline: string;
  weeks: string[];
  teamIds: string[];
}

export const listCoachWishCampaigns = (): Promise<CoachWishCampaign[]> => collectionAll<CoachWishCampaign>("coach_wish_campaigns", {});

export const createCoachWishCampaign = (body: CoachWishCampaignPayload): Promise<CoachWishCampaign> => api.post("coach_wish_campaigns", { json: body }).json();

export const updateCoachWishCampaign = (id: string, body: CoachWishCampaignPayload): Promise<CoachWishCampaign> => api.put(`coach_wish_campaigns/${id}`, { json: body }).json();

/** Construit le lien public personnel d'un coach à partir de son token. */
export const doleancesLink = (token: string): string => `${window.location.origin}/doleances/${token}`;

/** Réponse des actions d'envoi (C3) : nombre d'emails partis + la campagne re-projetée. */
export interface CampaignActionResult {
  sent: number;
  campaign: CoachWishCampaign;
}

/** Envoie les liens par email — sans coachIds : tous les coachs à email pas encore servis. */
export const sendCampaignLinks = (id: string, coachIds?: string[]): Promise<CampaignActionResult> =>
  api.post(`coach_wish_campaigns/${id}/send-links`, { json: coachIds ? { coachIds } : {} }).json();

/** Relance les silencieux (1×/jour — 422 si déjà relancé aujourd'hui). */
export const remindCampaignSilent = (id: string): Promise<CampaignActionResult> => api.post(`coach_wish_campaigns/${id}/remind`, { json: {} }).json();
