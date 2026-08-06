import { chromium } from "@playwright/test";
const TOKEN = process.env.PROOF_TOKEN;
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
await page.addInitScript((t) => {
  window.localStorage.setItem("cs-auth", JSON.stringify({ state: { token: t }, version: 0 }));
}, TOKEN);
await page.goto("http://frontend-dev:5173/wizard");
await page.waitForTimeout(2500);
const compris = page.getByRole("button", { name: "Compris" });
if (await compris.count()) await compris.click();
await page.getByRole("button", { name: /Génération/ }).first().click();
await page.waitForTimeout(2000);
await page.screenshot({ path: "/app/frontend/test-results/failed-step.png" });
const regen = page.getByRole("button", { name: /Régénérer|Lancer la génération/i }).first();
if (await regen.count()) { await regen.click(); await page.waitForTimeout(600); }
const confirm = page.getByRole("dialog").getByRole("button", { name: /Régénérer|Lancer|Confirmer/i });
if (await confirm.count()) await confirm.first().click();
await page.waitForSelector("text=/n'a pas abouti/", { timeout: 300000 });
await page.waitForTimeout(2500);
await page.screenshot({ path: "/app/frontend/test-results/failed-explained.png", fullPage: true });
console.log("PROOF OK");
await browser.close();
