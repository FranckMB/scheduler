import AxeBuilder from "@axe-core/playwright";
import { expect, type Page } from "@playwright/test";

/** Unique ARA per run — the dev DB is not rolled back between e2e runs. */
export function uniqueAra(prefix: string): string {
  const rand = Math.floor(Math.random() * 1_000_000).toString(36);
  return (prefix + Date.now().toString(36) + rand).toUpperCase().replace(/[^A-Z0-9]/g, "").slice(0, 20);
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
  await page.addInitScript((m) => {
    window.localStorage.setItem("cs-theme", JSON.stringify({ state: { mode: m, accent: null }, version: 1 }));
    const style = document.createElement("style");
    style.textContent = "*,*::before,*::after{transition:none!important;animation:none!important}";
    document.documentElement.appendChild(style);
  }, mode);
}
