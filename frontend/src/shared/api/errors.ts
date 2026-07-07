import { HTTPError } from "ky";

/** Extract a human-facing message from a ky error (backend returns { error: "..." }). */
export async function apiErrorMessage(error: unknown): Promise<string> {
  if (error instanceof HTTPError) {
    // Backend uses { error } (our controllers) or { message } (LexikJWT /
    // Symfony). ky 2.x parses the error body into error.data — re-reading the
    // response throws "body stream already read".
    const data = (error as { data?: unknown }).data;
    if (null !== data && typeof data === "object") {
      const body = data as { error?: unknown; message?: unknown };
      for (const candidate of [body.error, body.message]) {
        if (typeof candidate === "string" && "" !== candidate) {
          return candidate;
        }
      }
    }
  }
  return "Une erreur est survenue. Réessayez.";
}
