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
 * Confirmation forte : l'email exact du compte doit être re-saisi.
 */
export const deleteAccount = (email: string): Promise<DeleteAccountResult> => api.delete("me", { json: { email } }).json();
