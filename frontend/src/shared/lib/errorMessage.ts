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
    // The ky client's beforeError hook already read the body and stashed the
    // server's message (readable there, not post-hoc — see client.ts).
    const attached = (error as { serverMessage?: string }).serverMessage;
    if (typeof attached === "string" && attached.trim() !== "") {
      return attached;
    }

    const status = error.response.status;

    try {
      // ky 2.x consumes the error-response stream itself and exposes the parsed
      // body as `error.data` — re-reading the response here throws "body stream
      // already read". Read the stashed parse instead (fallback when the client
      // hook did not derive a serverMessage from it).
      const body = ((error as { data?: unknown }).data ?? {}) as ApiErrorBody;
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
