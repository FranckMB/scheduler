import { HTTPError } from "ky";

/** Extract a human-facing message from a ky error (backend returns { error: "..." }). */
export async function apiErrorMessage(error: unknown): Promise<string> {
  if (error instanceof HTTPError) {
    try {
      const body = (await error.response.json()) as { error?: unknown };
      if (typeof body.error === "string" && "" !== body.error) {
        return body.error;
      }
    } catch {
      // fall through
    }
  }
  return "Une erreur est survenue. Réessayez.";
}
