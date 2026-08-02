import { describe, expect, it } from "vitest";

import { CSP_PATH, sentryCspError } from "./sentryCspGuard";

const CSP_SANS_TIERS = `add_header Content-Security-Policy "default-src 'self'; connect-src 'self' blob:; img-src 'self' data: blob:" always;`;
const CSP_AVEC_SENTRY = `add_header Content-Security-Policy "default-src 'self'; connect-src 'self' blob: https://o42.ingest.de.sentry.io; img-src 'self' data: blob:" always;`;
const DSN = "https://abc123@o42.ingest.de.sentry.io/7";

/**
 * NR — P4-65. Ce garde existe parce que la panne qu'il attrape est SILENCIEUSE : poser le
 * DSN suffit à initialiser le SDK, donc tout paraît instrumenté, et la CSP jette chaque
 * envoi sans que rien ne le signale. Le trou se découvre le jour où on cherche une erreur
 * de production.
 */
describe("garde CSP ↔ Sentry", () => {
  it("se tait quand aucun DSN n'est posé — Sentry est inerte, il n'y a rien à garder", () => {
    expect(sentryCspError(undefined, CSP_SANS_TIERS)).toBeNull();
    expect(sentryCspError("", CSP_SANS_TIERS)).toBeNull();
    expect(sentryCspError("   ", CSP_SANS_TIERS)).toBeNull();
  });

  it("REFUSE un DSN dont l'hôte n'est pas autorisé — LE cas du lot", () => {
    const erreur = sentryCspError(DSN, CSP_SANS_TIERS);
    expect(erreur).toContain("o42.ingest.de.sentry.io");
    expect(erreur).toContain(CSP_PATH);
  });

  it("laisse passer quand l'hôte est autorisé", () => {
    expect(sentryCspError(DSN, CSP_AVEC_SENTRY)).toBeNull();
  });

  it("refuse aussi quand la CSP est illisible — on ne suppose pas que c'est bon", () => {
    // Ne pas pouvoir vérifier n'est pas une raison de laisser passer : poser un DSN est un
    // geste délibéré, il mérite une confirmation.
    expect(sentryCspError(DSN, null)).toContain("illisible");
  });

  it("nomme le champ fautif quand le DSN n'est pas une URL", () => {
    // Sinon `new URL()` remonterait un « Invalid URL » qui ne dit ni où ni pourquoi.
    expect(sentryCspError("pas-une-url", CSP_AVEC_SENTRY)).toContain("VITE_SENTRY_DSN");
  });

  it("ne se laisse pas berner par un hôte VOISIN", () => {
    // `includes` est volontairement large, mais il ne doit pas confondre deux organisations.
    const autreOrg = CSP_AVEC_SENTRY.replace("o42.", "o99.");
    expect(sentryCspError(DSN, autreOrg)).toContain("o42.ingest.de.sentry.io");
  });
});
