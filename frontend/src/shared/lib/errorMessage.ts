import { HTTPError, TimeoutError } from "ky";

interface ApiErrorBody {
  error?: string;
  message?: string;
  detail?: string;
  violations?: { message?: string }[];
}

/**
 * Best-effort French, user-facing message from an unknown error (FRT-01/02).
 * Prefers a server-provided message, then falls back to a status-based sentence.
 * Async because reading the ky HTTPError response body is async.
 */
export async function errorMessage(error: unknown): Promise<string> {
  if (error instanceof TimeoutError) {
    return "La requête a expiré. Réessayez.";
  }

  if (error instanceof HTTPError) {
    const status = error.response.status;

    // ky 2.x consumes the error-response stream itself and exposes the parsed
    // body as `error.data` — re-reading the response would throw "body stream
    // already read". A non-JSON body leaves data as a string/undefined → the
    // object guard falls through to the status-based sentence.
    const data = (error as { data?: unknown }).data;
    if (null !== data && typeof data === "object") {
      const body = data as ApiErrorBody;
      const direct = body.error ?? body.message ?? body.detail;
      if (typeof direct === "string" && direct.trim() !== "") {
        return direct;
      }
      if (Array.isArray(body.violations) && body.violations.length > 0) {
        const joined = body.violations
          .map((v) => v.message)
          .filter((m): m is string => typeof m === "string" && m.trim() !== "")
          .join(" · ");
        if (joined !== "") {
          return joined;
        }
      }
    }

    if (status === 400) return "Requête invalide.";
    if (status === 403) return "Accès refusé.";
    if (status === 404) return "Ressource introuvable.";
    if (status === 409) return "Conflit : l'action n'a pas pu être effectuée.";
    if (status === 422) return "Données invalides. Vérifiez votre saisie.";
    if (status >= 500) return "Erreur serveur. Réessayez plus tard.";
    return `Une erreur est survenue (${status}).`;
  }

  if (error instanceof Error) {
    return "Problème de connexion. Vérifiez votre réseau.";
  }

  return "Une erreur est survenue.";
}
