import { expect, test, type ConsoleMessage } from "@playwright/test";

import { registerAndVerify, uniqueAra } from "./support";

/**
 * A17 — security response headers on the SPA, plus a live check that the CSP
 * doesn't break the app (no `Refused to …` violations while walking the real
 * flows). The headers live in the frontend nginx build, NOT the Vite dev
 * server, so both tests skip when E2E_BASE_URL points at a dev server (:517x).
 */
const REQUIRED_HEADERS: Record<string, RegExp> = {
  "content-security-policy": /default-src 'self'/,
  "x-frame-options": /DENY/i,
  "x-content-type-options": /nosniff/i,
  "referrer-policy": /same-origin/i,
  "strict-transport-security": /max-age=\d+/,
};

const isDevServer = (baseURL: string | undefined): boolean => /:517\d(\/|$)/.test(baseURL ?? "");

const cspViolations = (messages: ConsoleMessage[]): string[] =>
  messages.filter((m) => /content security policy|refused to (load|connect|apply|execute|frame)/i.test(m.text())).map((m) => m.text());

test("SPA ships the A17 security headers", async ({ page, baseURL }) => {
  test.skip(isDevServer(baseURL), "security headers only exist on the nginx build");
  const response = await page.goto("/login");
  const headers = response?.headers() ?? {};
  for (const [name, pattern] of Object.entries(REQUIRED_HEADERS)) {
    expect(headers[name], `missing/!matching header ${name}`).toMatch(pattern);
  }
});

test("CSP does not break the app across login → register → wizard → club", async ({ page, baseURL }) => {
  test.skip(isDevServer(baseURL), "CSP is served by the nginx build, not the dev server");
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
  await page.getByRole("button", { name: "Ajouter l'équipe" }).click();
  await page.getByRole("button", { name: "Suivant" }).click();
  await expect(page.getByRole("heading", { name: /Étape 2\/6/ })).toBeVisible();

  // /club is reachable during onboarding — its accent pickers + logo preview
  // exercise more inline styles/images under the CSP.
  await page.goto("/club");
  await expect(page.getByRole("heading", { name: /club/i }).first()).toBeVisible({ timeout: 15_000 });

  expect(cspViolations(console_), `CSP violations:\n${cspViolations(console_).join("\n")}`).toEqual([]);
});
