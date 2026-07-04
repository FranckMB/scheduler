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

    try {
      const body = (await error.response.clone().json()) as ApiErrorBody;
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
    } catch {
      // body was not JSON — fall through to the status-based message
    }

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
