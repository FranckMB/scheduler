import { expect, test, type ConsoleMessage } from "@playwright/test";

import { registerAndVerify, uniqueAra } from "./support";

/**
 * A17 — security response headers on the SPA, plus a live check that the CSP
 * doesn't break the app (no `Refused to …` violations while walking the real
 * flows). The headers live in the frontend nginx build, NOT the Vite dev
 * server, so both tests skip when E2E_BASE_URL points at a dev server (:517x).
 *
 * ⚠ D-04 (audit 2026-08-08) — ce skip a rendu le contrôle A17 INEXISTANT pendant des mois :
 * la CI lançait toute la suite avec `E2E_BASE_URL=http://localhost:5173`, donc les deux tests
 * skippaient à CHAQUE run. Retirer un en-tête de sécurité de la conf prod passait tous les
 * checks, et la posture cyber de l'audit annonçait « A17 protégé » sur la seule existence des
 * fichiers de conf — que rien ne vérifiait servis.
 *
 * Le skip reste légitime (les en-têtes n'existent pas sur le dev server), mais il ne peut plus
 * se produire SILENCIEUSEMENT là où le contrôle est attendu : `E2E_A17_REQUIRED=1` le
 * transforme en échec. C'est le job CI dédié qui pose la variable — un garde qui peut se
 * saborder sans bruit n'est pas un garde.
 */
const REQUIRED_HEADERS: Record<string, RegExp> = {
  "content-security-policy": /default-src 'self'/,
  "x-frame-options": /DENY/i,
  "x-content-type-options": /nosniff/i,
  "referrer-policy": /same-origin/i,
  "strict-transport-security": /max-age=\d+/,
};

const isDevServer = (baseURL: string | undefined): boolean => /:517\d(\/|$)/.test(baseURL ?? "");

/**
 * Sauter le contrôle est acceptable ; le sauter LÀ OÙ ON CROIT LE FAIRE ne l'est pas.
 * Quand `E2E_A17_REQUIRED=1`, viser un dev server est une erreur de câblage, pas un cas à
 * ignorer : on échoue en le disant plutôt que de rendre un vert qui ne prouve rien.
 */
const skipUnlessNginxBuild = (baseURL: string | undefined, why: string): void => {
  if (!isDevServer(baseURL)) return;
  if ("1" === process.env.E2E_A17_REQUIRED) {
    throw new Error(`A17 exigé mais E2E_BASE_URL vise le dev server (${baseURL ?? "?"}) — ces en-têtes ne vivent que sur l'image nginx (:8081).`);
  }
  test.skip(true, why);
};

const cspViolations = (messages: ConsoleMessage[]): string[] =>
  messages.filter((m) => /content security policy|refused to (load|connect|apply|execute|frame)/i.test(m.text())).map((m) => m.text());

test("SPA ships the A17 security headers", async ({ page, baseURL }) => {
  skipUnlessNginxBuild(baseURL, "security headers only exist on the nginx build");
  const response = await page.goto("/login");
  const headers = response?.headers() ?? {};
  for (const [name, pattern] of Object.entries(REQUIRED_HEADERS)) {
    expect(headers[name], `missing/!matching header ${name}`).toMatch(pattern);
  }
});

test("CSP does not break the app across login → register → wizard → club", async ({ page, baseURL }) => {
  skipUnlessNginxBuild(baseURL, "CSP is served by the nginx build, not the dev server");
  test.setTimeout(120_000);
  const console_: ConsoleMessage[] = [];
  page.on("console", (m) => console_.push(m));

  await page.goto("/login");
  await expect(page.getByRole("button", { name: /se connecter/i })).toBeVisible();

  const ara = uniqueAra("CSP");
  await registerAndVerify(page, { email: `csp-${ara}@e2e.fr`, ara, firstName: "Csp", lastName: "Test", clubName: "CSP Club" });

  // Reaching the wizard proves scripts ran, styles applied and /api XHR passed
  // the connect-src policy — the paths a too-strict CSP would break.
  await expect(page.getByRole("heading", { name: /Étape 1\/6/ })).toBeVisible({ timeout: 15_000 });
  await page.getByLabel("Nom de l'équipe").fill("SM1");
  // La catégorie n'a plus de défaut (revue #347) — cf. journey.spec.
  await page.getByLabel("Catégorie").selectOption({ label: "Senior" });
  await page.getByRole("button", { name: "Ajouter l'équipe" }).click();
  await page.getByRole("button", { name: "Suivant" }).click();
  await expect(page.getByRole("heading", { name: /Étape 2\/6/ })).toBeVisible();

  // /club is reachable during onboarding — its accent pickers + logo preview
  // exercise more inline styles/images under the CSP.
  await page.goto("/club");
  await expect(page.getByRole("heading", { name: /club/i }).first()).toBeVisible({ timeout: 15_000 });

  expect(cspViolations(console_), `CSP violations:\n${cspViolations(console_).join("\n")}`).toEqual([]);
});
