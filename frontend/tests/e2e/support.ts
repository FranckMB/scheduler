import AxeBuilder from "@axe-core/playwright";
import { expect, type Page } from "@playwright/test";

import { THEME_STORAGE_KEY } from "../../src/shared/stores/themeStore";

/** Unique ARA per run — the dev DB is not rolled back between e2e runs. */
export function uniqueAra(prefix: string): string {
  const rand = Math.floor(Math.random() * 1_000_000).toString(36);
  return (prefix + Date.now().toString(36) + rand).toUpperCase().replace(/[^A-Z0-9]/g, "").slice(0, 20);
}

const MAILPIT_URL = process.env.MAILPIT_WEB_URL ?? "http://127.0.0.1:8025";

interface RegisterOpts {
  email: string;
  ara: string;
  firstName?: string;
  lastName?: string;
  password?: string;
  /** Provide for a NEW club; omit when joining an existing ARA. */
  clubName?: string;
}

/** Fill and submit the register form, then assert the "check your email" state. */
export async function submitRegister(page: Page, opts: RegisterOpts): Promise<void> {
  await page.goto("/register");
  await page.getByLabel("Prénom").fill(opts.firstName ?? "Jean");
  await page.getByLabel("Nom", { exact: true }).fill(opts.lastName ?? "Dupont");
  await page.getByLabel("Email", { exact: true }).fill(opts.email);
  await page.getByLabel("Mot de passe", { exact: true }).fill(opts.password ?? "Password123!");
  await page.getByLabel(/code ara/i).fill(opts.ara);
  if (undefined !== opts.clubName) {
    await page.getByLabel(/nom du club/i).fill(opts.clubName);
  }
  await page.getByRole("button", { name: /créer le compte/i }).click();
  await expect(page.getByText(/email de confirmation/i)).toBeVisible({ timeout: 15_000 });
}

/**
 * Registration no longer authenticates (A3): the JWT is issued only via the emailed
 * verification link. Pull that email out of Mailpit, extract the raw token, and visit
 * /verify-email/:token on the e2e origin (the email's absolute FRONTEND_BASE_URL may
 * differ from the e2e base URL, so only the token is reused).
 */
async function fetchVerificationToken(page: Page, email: string): Promise<string> {
  let token = "";
  await expect
    .poll(
      async () => {
        const search = await page.request.get(`${MAILPIT_URL}/api/v1/search`, { params: { query: `to:${email}` } });
        if (!search.ok()) return "";
        const first = (await search.json()).messages?.[0];
        if (undefined === first) return "";
        const detail = await page.request.get(`${MAILPIT_URL}/api/v1/message/${first.ID}`);
        const body: string = (await detail.json()).Text ?? "";
        token = body.match(/verify-email\/([a-f0-9]{64})/)?.[1] ?? "";
        return token;
      },
      { timeout: 15_000 },
    )
    .not.toBe("");
  return token;
}

/** Register a fresh account and follow its verification link — lands in the app. */
export async function registerAndVerify(page: Page, opts: RegisterOpts): Promise<void> {
  await submitRegister(page, opts);
  const token = await fetchVerificationToken(page, opts.email);
  await page.goto(`/verify-email/${token}`);
}

/**
 * WCAG 2.2 AA colour-contrast gate (1.4.3) — real browser only. jsdom (vitest) has
 * no layout engine and cannot compute contrast; this runs axe-core inside Playwright
 * Chromium against the live app. Scoped to the `color-contrast` rule so a regression
 * is a precise, actionable failure (structural WCAG is the jsx-a11y + vitest-axe job).
 * `label` names the screen (+ theme) in the failure output.
 */
export async function expectNoContrastViolations(page: Page, label: string): Promise<void> {
  const results = await new AxeBuilder({ page }).withRules(["color-contrast"]).analyze();
  const offenders = results.violations.flatMap((v) =>
    v.nodes.map((n) => `  ${n.target.join(" ")} — ${(n.failureSummary ?? "").split("\n").join(" ")}\n    HTML: ${n.html}`),
  );
  expect(offenders, `${label}: colour-contrast (WCAG 1.4.3) violations:\n${offenders.join("\n")}`).toEqual([]);
}

/**
 * Persist the theme mode before the app boots (zustand-persist key `cs-theme`)
 * AND kill transitions/animations, so axe samples settled colours — a
 * `transition-colors` mid-flight briefly reads intermediate, sub-AA values.
 */
export async function forceTheme(page: Page, mode: "dark" | "light"): Promise<void> {
  await page.addInitScript(
    ({ key, m }) => {
      window.localStorage.setItem(key, JSON.stringify({ state: { mode: m, accent: null }, version: 1 }));
      const style = document.createElement("style");
      style.textContent = "*,*::before,*::after{transition:none!important;animation:none!important}";
      document.documentElement.appendChild(style);
    },
    { key: THEME_STORAGE_KEY, m: mode },
  );
}
