import { HTTPError, TimeoutError } from "ky";
import { describe, expect, it } from "vitest";

import { errorMessage } from "./errorMessage";

function httpError(status: number, body?: unknown): HTTPError {
  const response = new Response(body === undefined ? null : JSON.stringify(body), {
    status,
    headers: { "content-type": "application/json" },
  });
  const request = new Request("http://localhost/api/teams");
  return new HTTPError(response, request, {} as never);
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

  it("handles timeouts and network errors", async () => {
    expect(await errorMessage(new TimeoutError(new Request("http://x")))).toBe("La requête a expiré. Réessayez.");
    expect(await errorMessage(new Error("Failed to fetch"))).toBe("Problème de connexion. Vérifiez votre réseau.");
    expect(await errorMessage("weird")).toBe("Une erreur est survenue.");
  });
});
