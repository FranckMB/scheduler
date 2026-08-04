import { api } from "@/shared/api/client";
import { downloadBlob } from "@/shared/lib/download";

export interface AppearancePayload {
  accentColor?: string | null;
  accentColorDark?: string | null;
  accentPalette?: string[] | null;
}

export interface AppearanceResult {
  accentColor: string | null;
  accentColorDark: string | null;
  accentPalette: string[] | null;
}

/** Partial update of the club identity (accent), scoped server-side to the JWT club. */
export const updateAppearance = (body: AppearancePayload): Promise<AppearanceResult> => api.patch("club/appearance", { json: body }).json();

// La fiche club n'a plus AUCUN champ saisissable (décision fondateur 2026-08-04) :
// la FFBB fait autorité, le geste de correction est le ré-import ci-dessous.
// L'ancien PATCH /api/club/info a été supprimé avec ses champs.
export interface FfbbImportResult {
  populated: boolean;
  error?: string;
}

/** Re-import institutionnel depuis la FFBB (management) — écrase les champs dont la fédération fait autorité. */
export const ffbbImport = (): Promise<FfbbImportResult> => api.post("club/ffbb-import").json();

/** Upload the club logo (multipart); returns its public URL. */
export const uploadLogo = (file: File): Promise<{ logoUrl: string }> => {
  const form = new FormData();
  form.append("file", file);
  return api.post("club/logo", { body: form }).json();
};

export const deleteLogo = (): Promise<{ logoUrl: null }> => api.delete("club/logo").json();

export interface ResetClubResult {
  status: string;
  deleted: number;
}

/**
 * Wipe every piece of data entered for the current club/season (teams, venues,
 * coaches, constraints, schedules) to start over. The season is resolved
 * server-side (TenantFilterListener sets _season_id from the active season).
 */
export const resetClub = (): Promise<ResetClubResult> => api.delete("reset-season").json();

/**
 * RGPD portabilité : télécharge l'export JSON complet du workspace du club
 * (management). timeout désactivé : le backend construit tout le JSON avant de
 * répondre (le défaut ky de 10 s couperait les gros clubs).
 */
export async function downloadClubExport(): Promise<void> {
  const blob = await api.get("club/export", { timeout: false }).blob();
  downloadBlob(blob, "donnees-club.json");
}
