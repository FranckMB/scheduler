import { expect, test } from "@playwright/test";

/** Unique ARA per run — the dev DB is not rolled back between e2e runs. */
function uniqueAra(prefix: string): string {
  const rand = Math.floor(Math.random() * 1_000_000).toString(36);
  return (prefix + Date.now().toString(36) + rand).toUpperCase().replace(/[^A-Z0-9]/g, "").slice(0, 20);
}

async function registerClub(page: import("@playwright/test").Page): Promise<void> {
  const ara = uniqueAra("E2ES");
  await page.goto("/register");
  await page.getByLabel("Prénom").fill("Sonia");
  await page.getByLabel("Nom", { exact: true }).fill("Saison");
  await page.getByLabel("Email", { exact: true }).fill(`season-${ara}@e2e.fr`);
  await page.getByLabel("Mot de passe", { exact: true }).fill("password123");
  await page.getByLabel(/code ara/i).fill(ara);
  await page.getByLabel(/nom du club/i).fill("E2E Saison Club");
  await page.getByRole("button", { name: /créer le compte/i }).click();
}

test("just subscribed: one season, no next-season draft yet, work-loop gate", async ({ page }) => {
  await registerClub(page);

  // A brand-new club lands in the work-loop (no validated socle) and the
  // season selector shows its single current season.
  const selectorTrigger = page.getByRole("button", { name: "Saison de travail" });
  await expect(selectorTrigger).toBeVisible({ timeout: 15_000 });

  await selectorTrigger.click();
  // Exactly one season, marked "en cours", and no draft yet.
  await expect(page.getByRole("menuitem", { name: /· en cours/i })).toHaveCount(1);
  await expect(page.getByRole("menuitem", { name: /· brouillon/i })).toHaveCount(0);
  // The next-season action is available from day one.
  await expect(page.getByRole("menuitem", { name: /Préparer la saison suivante/i })).toBeVisible();
});

/**
 * End-to-end of the season-transition workflow (transition-de-saison P1):
 * a fresh club has one season; "Préparer la saison suivante" copies it into a
 * draft N+1 and the selector then lists both, working on the new draft.
 */
test("prepare next season: the selector lists both and switches to the draft", async ({ page }) => {
  await registerClub(page);

  // The header season selector is present with the single (current) season.
  const selectorTrigger = page.getByRole("button", { name: "Saison de travail" });
  await expect(selectorTrigger).toBeVisible({ timeout: 15_000 });

  // Trigger the transition from the selector menu.
  await selectorTrigger.click();
  await page.getByRole("menuitem", { name: /Préparer la saison suivante/i }).click();

  // Confirmation dialog (structural action).
  await expect(page.getByText("Préparer la saison suivante ?")).toBeVisible();
  await page.getByRole("button", { name: "Préparer", exact: true }).click();

  // Success toast, then the selector lists the current season AND the new draft.
  await expect(page.getByText(/structure copiée/i)).toBeVisible({ timeout: 15_000 });

  await selectorTrigger.click();
  await expect(page.getByRole("menuitem", { name: /· en cours/i })).toBeVisible();
  await expect(page.getByRole("menuitem", { name: /· brouillon/i })).toBeVisible();
});

test("preparing twice reuses the existing next season (no duplicate)", async ({ page }) => {
  await registerClub(page);

  const selectorTrigger = page.getByRole("button", { name: "Saison de travail" });
  await expect(selectorTrigger).toBeVisible({ timeout: 15_000 });

  const prepare = async () => {
    await selectorTrigger.click();
    await page.getByRole("menuitem", { name: /Préparer la saison suivante/i }).click();
    await page.getByRole("button", { name: "Préparer", exact: true }).click();
  };

  await prepare();
  await expect(page.getByText(/structure copiée/i)).toBeVisible({ timeout: 15_000 });
  // Wait for the switch to settle (query cache cleared + me refetched).
  await expect(selectorTrigger).toContainText(/\d{4}/);

  // Second attempt → backend 409 with the existing successor → the UI reuses it,
  // never creating a second draft. The invariant that matters: still ONE draft.
  await prepare();
  await page.waitForTimeout(1500);

  await selectorTrigger.click();
  await expect(page.getByRole("menuitem", { name: /· brouillon/i })).toHaveCount(1);
});
