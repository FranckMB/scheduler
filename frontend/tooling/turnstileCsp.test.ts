import { readFileSync } from "node:fs";
import { resolve } from "node:path";

import { describe, expect, it } from "vitest";

/**
 * P5-3b — garde statique : Cloudflare Turnstile n'émet que si la CSP l'autorise
 * DANS LES DEUX directives dont il dépend. Le widget charge un script
 * (`script-src`) puis se rend dans une iframe (`frame-src`), toutes deux servies
 * par challenges.cloudflare.com. Oublier l'une des deux casse le widget SANS bruit
 * — exactement le genre de panne que ce garde transforme en rouge nommé.
 *
 * Lit le VRAI `docker/frontend/csp.conf` (celui copié dans l'image de build), pas
 * une chaîne de test : retirer l'hôte d'une directive doit faire rougir ici.
 */
const TURNSTILE_HOST = "challenges.cloudflare.com";
// Depuis frontend/ (cwd), le fichier vit un cran au-dessus — même repère que
// tests/config/security-headers.config.test.ts (l'image tooling copie
// docker/frontend/ dans /app/docker/frontend/).
const CSP_PATH = "docker/frontend/csp.conf";

const cspContent = readFileSync(resolve(process.cwd(), "..", CSP_PATH), "utf8");
const cspValue = /Content-Security-Policy\s+"([^"]+)"/.exec(cspContent)?.[1] ?? "";
const directive = (name: string): string =>
  new RegExp(`(?:^|;)\\s*${name}\\s+([^;]*)`).exec(cspValue)?.[1]?.trim() ?? "";

describe("garde CSP ↔ Turnstile", () => {
  it("autorise l'hôte Turnstile dans script-src (le loader du widget)", () => {
    expect(directive("script-src"), `script-src doit autoriser ${TURNSTILE_HOST} dans ${CSP_PATH} (sinon le loader Turnstile est bloqué)`).toContain(TURNSTILE_HOST);
  });

  it("autorise l'hôte Turnstile dans frame-src (l'iframe du challenge)", () => {
    expect(directive("frame-src"), `frame-src doit autoriser ${TURNSTILE_HOST} dans ${CSP_PATH} (sinon l'iframe du challenge est bloquée)`).toContain(TURNSTILE_HOST);
  });
});
