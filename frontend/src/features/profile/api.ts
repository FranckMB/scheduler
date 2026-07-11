import { api } from "@/shared/api/client";
import { downloadBlob } from "@/shared/lib/download";

export interface UpdateProfilePayload {
  firstName?: string;
  lastName?: string;
  email?: string;
}

export interface UpdateProfileResult {
  id: string;
  email: string;
  firstName: string;
  lastName: string;
}

/** Update the connected user's own profile (PATCH /api/me). */
export const updateProfile = (body: UpdateProfilePayload): Promise<UpdateProfileResult> => api.patch("me", { json: body }).json();

export interface ChangePasswordPayload {
  currentPassword: string;
  newPassword: string;
}

/** Change the connected user's password (current password required). */
export const changePassword = (body: ChangePasswordPayload): Promise<{ status: string }> => api.post("me/password", { json: body }).json();

export interface DeleteAccountResult {
  message: string;
  clubPurgeScheduled: boolean;
  gracePeriodDays: number;
}

/**
 * RGPD erasure (DELETE /api/me): anonymise immédiatement le compte connecté.
 * Confirmation = ré-authentification : le mot de passe courant est exigé
 * (un JWT volé ne suffit pas à détruire le compte).
 */
export const deleteAccount = (password: string): Promise<DeleteAccountResult> => api.delete("me", { json: { password } }).json();

/**
 * RGPD portabilité : télécharge l'export JSON de MES données de compte.
 * timeout désactivé : le backend construit tout le JSON avant de répondre
 * (le défaut ky de 10 s couperait les gros exports).
 */
export async function downloadMyDataExport(): Promise<void> {
  const blob = await api.get("me/export", { timeout: false }).blob();
  downloadBlob(blob, "mes-donnees.json");
}
