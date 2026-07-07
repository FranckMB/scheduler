import { expect, test } from "@playwright/test";

/** Seeded dev club (BasketballInit) — has teams, venues, a baseline plan and completed onboarding. */
const EMAIL = "mara.mb@bccl.fr";
const PASSWORD = "maraboubccl";

async function login(page: import("@playwright/test").Page): Promise<void> {
  await page.goto("/login");
  await page.getByLabel("Email").fill(EMAIL);
  await page.getByLabel("Mot de passe", { exact: true }).fill(PASSWORD);
  await page.getByRole("button", { name: "Se connecter" }).click();
  await expect(page.getByRole("button", { name: "Saison de travail" })).toBeVisible({ timeout: 15_000 });
}

/**
 * End-to-end of the placement loop (module matchs PR-3) under the real stack:
 * create a fixture manually → it lands in the "à placer" list → place it (venue +
 * kickoff) → it leaves the list. The conflict radar renders throughout.
 */
test("matches: create a fixture, place it, radar renders", async ({ page }) => {
  await login(page);

  // A unique opponent so the assertions target THIS run's fixture (dev DB is not reset).
  const opponent = `E2E-${Date.now().toString(36).toUpperCase()}`;

  await page.getByRole("link", { name: "Matchs" }).click();
  await expect(page.getByRole("heading", { name: "Matchs" })).toBeVisible();
  await expect(page.getByText("Radar de conflits")).toBeVisible();

  // Manual entry (before the FBI import).
  await page.getByRole("button", { name: /Nouveau match/i }).click();
  await expect(page.getByRole("heading", { name: "Nouveau match" })).toBeVisible();
  await page.getByLabel("Équipe").selectOption({ index: 0 });
  await page.getByLabel("Date").fill("2027-03-06"); // a Saturday
  await page.getByLabel("Adversaire").fill(opponent);
  await page.getByRole("button", { name: "Créer" }).click();

  // The new home fixture shows in the to-do list; open its placement panel.
  const todo = page.getByRole("button", { name: new RegExp(opponent) });
  await expect(todo).toBeVisible({ timeout: 15_000 });
  await todo.click();

  // Place it: pick a venue + kickoff. Whether "Placer" is enabled depends on the
  // team's league envelope (real seeded data) — assert BOTH real outcomes:
  //  - in-envelope (or unmapped) → placement succeeds → leaves the to-do list;
  //  - out-of-envelope → the HARD guard disables placement and warns.
  await page.getByLabel("Gymnase").selectOption({ index: 1 });
  await page.getByLabel("Heure de coup d'envoi").fill("15:00");
  const place = page.getByRole("button", { name: "Placer" });

  if (await place.isEnabled()) {
    await place.click();
    await expect(page.getByRole("button", { name: new RegExp(opponent) })).toHaveCount(0, { timeout: 15_000 });
  } else {
    await expect(page.getByText(/Hors fenêtre autorisée/)).toBeVisible();
  }
});
