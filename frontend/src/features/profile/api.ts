import { api } from "@/shared/api/client";

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
 * RGPD portabilité : télécharge un export JSON (mes données / données du club).
 * Blob + ancre : la réponse porte un Content-Disposition attachment.
 */
export async function downloadExport(path: "me/export" | "club/export", filename: string): Promise<void> {
  const blob = await api.get(path).blob();
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = filename;
  a.rel = "noopener";
  document.body.appendChild(a);
  a.click();
  a.remove();
  // Révocation différée : un revoke synchrone peut annuler le téléchargement.
  setTimeout(() => URL.revokeObjectURL(url), 30_000);
}
