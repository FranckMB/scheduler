import { HTTPError } from "ky";

/** Extract a human-facing message from a ky error (backend returns { error: "..." }). */
export async function apiErrorMessage(error: unknown): Promise<string> {
  if (error instanceof HTTPError) {
    try {
      // Backend uses { error } (our controllers) or { message } (LexikJWT / Symfony).
      const body = (await error.response.json()) as { error?: unknown; message?: unknown };
      for (const candidate of [body.error, body.message]) {
        if (typeof candidate === "string" && "" !== candidate) {
          return candidate;
        }
      }
    } catch {
      // fall through
    }
  }
  return "Une erreur est survenue. Réessayez.";
}
