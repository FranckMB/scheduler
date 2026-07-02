import { api } from "@/shared/api/client";

export interface AppearancePayload {
  accentColor?: string | null;
  accentPalette?: string[] | null;
}

export interface AppearanceResult {
  accentColor: string | null;
  accentPalette: string[] | null;
}

/** Partial update of the club identity (accent), scoped server-side to the JWT club. */
export const updateAppearance = (body: AppearancePayload): Promise<AppearanceResult> => api.patch("club/appearance", { json: body }).json();
