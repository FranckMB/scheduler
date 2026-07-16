import ky from "ky";

export interface AdminIdentity {
  id: string;
  email: string;
}

interface PasswordResponse {
  mfaRequired: true;
}

interface TotpResponse {
  authenticated: true;
  csrfToken: string;
}

export interface AdminSessionResponse extends AdminIdentity {
  csrfToken: string;
}

/** Session-cookie client for /api/admin. It deliberately never reads the club JWT store. */
export const adminApi = ky.create({
  prefix: "/api/admin",
  credentials: "same-origin",
});

export function startAdminPassword(body: { email: string; password: string }): Promise<PasswordResponse> {
  return adminApi.post("auth/password", { json: body }).json();
}

export function completeAdminTotp(code: string): Promise<TotpResponse> {
  return adminApi.post("auth/totp", { json: { code } }).json();
}

export function getAdminSession(): Promise<AdminSessionResponse> {
  return adminApi.get("auth/me").json();
}

export function logoutAdmin(csrfToken: string): Promise<void> {
  return adminApi.post("auth/logout", { headers: { "X-CSRF-Token": csrfToken } }).then(() => undefined);
}
