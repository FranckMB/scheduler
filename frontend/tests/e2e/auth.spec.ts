import { expect, test } from "@playwright/test";

/** Unique ARA per run — the dev DB is not rolled back between e2e runs. */
function uniqueAra(prefix: string): string {
  const rand = Math.floor(Math.random() * 1_000_000).toString(36);
  return (prefix + Date.now().toString(36) + rand).toUpperCase().replace(/[^A-Z0-9]/g, "").slice(0, 20);
}

test("register a new club lands on the dashboard", async ({ page }) => {
  const ara = uniqueAra("E2EN");
  await page.goto("/register");
  await page.getByLabel("Prénom").fill("Jean");
  await page.getByLabel("Nom", { exact: true }).fill("Dupont");
  await page.getByLabel("Email", { exact: true }).fill(`new-${ara}@e2e.fr`);
  await page.getByLabel("Mot de passe", { exact: true }).fill("password123");
  await page.getByLabel(/code ara/i).fill(ara);
  await page.getByLabel(/nom du club/i).fill("E2E Club");
  await page.getByRole("button", { name: /créer le compte/i }).click();

  // A fresh club is routed to the onboarding wizard (AuthGuard) with the
  // season selector mounted in the header — the app shell is alive.
  await expect(page.getByRole("heading", { name: /Étape 1\/6/ })).toBeVisible({ timeout: 15_000 });
  await expect(page.getByRole("button", { name: "Saison de travail" })).toBeVisible();
});

test("joining an existing club shows the waiting-for-approval screen", async ({ page }) => {
  const ara = uniqueAra("E2EJ");

  // Owner creates the club (becomes active admin -> dashboard).
  await page.goto("/register");
  await page.getByLabel("Prénom").fill("Owner");
  await page.getByLabel("Nom", { exact: true }).fill("Admin");
  await page.getByLabel("Email", { exact: true }).fill(`owner-${ara}@e2e.fr`);
  await page.getByLabel("Mot de passe", { exact: true }).fill("password123");
  await page.getByLabel(/code ara/i).fill(ara);
  await page.getByLabel(/nom du club/i).fill("E2E Existing");
  await page.getByRole("button", { name: /créer le compte/i }).click();
  await expect(page.getByRole("heading", { name: /Étape 1\/6/ })).toBeVisible({ timeout: 15_000 });

  // A second person on the same ARA -> pending -> waiting screen.
  await page.goto("/register");
  await page.getByLabel("Prénom").fill("Joiner");
  await page.getByLabel("Nom", { exact: true }).fill("Two");
  await page.getByLabel("Email", { exact: true }).fill(`joiner-${ara}@e2e.fr`);
  await page.getByLabel("Mot de passe", { exact: true }).fill("password123");
  await page.getByLabel(/code ara/i).fill(ara);
  await page.getByRole("button", { name: /créer le compte/i }).click();

  await expect(page.getByText(/demande en attente/i)).toBeVisible();
});

test("login rejects invalid credentials without redirect loop", async ({ page }) => {
  await page.goto("/login");
  await page.getByLabel("Email", { exact: true }).fill("nobody@e2e.fr");
  await page.getByLabel("Mot de passe", { exact: true }).fill("wrongpassword");
  await page.getByRole("button", { name: /se connecter/i }).click();

  await expect(page.getByText(/erreur|invalid|identifiants|credentials/i)).toBeVisible();
  await expect(page).toHaveURL(/\/login$/);
});
