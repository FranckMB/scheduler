import { HTTPError, TimeoutError } from "ky";
import { describe, expect, it } from "vitest";

import { errorMessage } from "./errorMessage";

function httpError(status: number, body?: unknown): HTTPError {
  const response = new Response(body === undefined ? null : JSON.stringify(body), {
    status,
    headers: { "content-type": "application/json" },
  });
  const request = new Request("http://localhost/api/teams");
  const error = new HTTPError(response, request, {} as never);
  // ky 2.x consumes the response stream itself and exposes the parsed body as
  // error.data before any consumer runs — mirror that contract.
  (error as unknown as { data?: unknown }).data = body;
  return error;
}

describe("errorMessage", () => {
  it("prefers a server-provided message", async () => {
    expect(await errorMessage(httpError(422, { error: "Nom déjà pris" }))).toBe("Nom déjà pris");
    expect(await errorMessage(httpError(400, { message: "Requête invalide" }))).toBe("Requête invalide");
  });

  it("joins API Platform violations", async () => {
    const msg = await errorMessage(
      httpError(422, { violations: [{ message: "sessionsPerWeek doit être positif" }, { message: "matchDay hors bornes" }] }),
    );
    expect(msg).toBe("sessionsPerWeek doit être positif · matchDay hors bornes");
  });

  it("falls back to a French status sentence when no body message", async () => {
    expect(await errorMessage(httpError(403))).toBe("Accès refusé.");
    expect(await errorMessage(httpError(404))).toBe("Ressource introuvable.");
    expect(await errorMessage(httpError(409))).toBe("Conflit : l'action n'a pas pu être effectuée.");
    expect(await errorMessage(httpError(422))).toBe("Données invalides. Vérifiez votre saisie.");
    expect(await errorMessage(httpError(500))).toBe("Erreur serveur. Réessayez plus tard.");
  });

  // NR P4-5 / SEC-08 — un 5xx ne doit JAMAIS reprendre la chaîne du serveur :
  // elle n'est pas actionnable et peut porter des détails internes.
  it("n'expose jamais le message serveur d'une erreur 5xx", async () => {
    expect(await errorMessage(httpError(500, { detail: 'SQLSTATE[42501]: table "club_user"' }))).toBe("Erreur serveur. Réessayez plus tard.");
    expect(await errorMessage(httpError(502, { error: "upstream engine unreachable at engine:8000" }))).toBe("Erreur serveur. Réessayez plus tard.");
    expect(await errorMessage(httpError(503, { violations: [{ message: "connexion Doctrine perdue" }] }))).toBe("Erreur serveur. Réessayez plus tard.");
    // Contre-épreuve : côté 4xx, le message métier reste affiché tel quel.
    expect(await errorMessage(httpError(409, { error: "Une collecte existe déjà pour cette période." }))).toBe("Une collecte existe déjà pour cette période.");
  });

  it("handles timeouts and network errors", async () => {
    expect(await errorMessage(new TimeoutError(new Request("http://x")))).toBe("La requête a expiré. Réessayez.");
    expect(await errorMessage(new Error("Failed to fetch"))).toBe("Problème de connexion. Vérifiez votre réseau.");
    expect(await errorMessage("weird")).toBe("Une erreur est survenue.");
  });
});
