import { expect, test } from "@playwright/test";

/** Unique ARA per run — the dev DB is not rolled back between e2e runs. */
function uniqueAra(prefix: string): string {
  const rand = Math.floor(Math.random() * 1_000_000).toString(36);
  return (prefix + Date.now().toString(36) + rand).toUpperCase().replace(/[^A-Z0-9]/g, "").slice(0, 20);
}

/**
 * THE end-to-end journey (audit P0.2, FRT-05): a fresh club walks the whole
 * wizard (team → venue + slot → coach → constraints → recap), launches a REAL
 * generation (CP-SAT solves the 1-team instance), sees the placed planning,
 * validates it, and lands on the unlocked cockpit. This is the promise of the
 * product exercised as a user.
 */
test("full journey: wizard → generation → validated planning → cockpit", async ({ page }) => {
  test.setTimeout(240_000); // includes a real solve (small instance, seconds)

  // --- Register a fresh club → onboarding wizard.
  const ara = uniqueAra("E2EF");
  await page.goto("/register");
  await page.getByLabel("Prénom").fill("Flo");
  await page.getByLabel("Nom", { exact: true }).fill("Journey");
  await page.getByLabel("Email", { exact: true }).fill(`journey-${ara}@e2e.fr`);
  await page.getByLabel("Mot de passe", { exact: true }).fill("password123");
  await page.getByLabel(/code ara/i).fill(ara);
  await page.getByLabel(/nom du club/i).fill("E2E Journey Club");
  await page.getByRole("button", { name: /créer le compte/i }).click();
  await expect(page.getByRole("heading", { name: /Étape 1\/6/ })).toBeVisible({ timeout: 15_000 });

  // --- Step 1 · team (defaults: first category, 2 sessions/week).
  await page.getByLabel("Nom de l'équipe").fill("SM1");
  await page.getByRole("button", { name: "Ajouter l'équipe" }).click();
  await expect(page.locator('input[value="SM1"]')).toBeVisible();
  await page.getByRole("button", { name: "Suivant" }).click();

  // --- Step 2 · venue + two weekly slots (2 sessions to place).
  await expect(page.getByRole("heading", { name: /Étape 2\/6/ })).toBeVisible();
  await page.getByLabel("Nom du gymnase").fill("Gymnase E2E");
  await page.getByRole("button", { name: "Ajouter un gymnase" }).click();
  // Created venue is auto-selected in the venue picker; the grid is open.
  await expect(page.getByLabel("Gymnase", { exact: true })).toHaveValue(/./);
  // Add two weekly slots (2 sessions to place) on the availability grid.
  await page.getByRole("button", { name: "Lun 18:00", exact: true }).click();
  await page.getByRole("button", { name: "Mer 18:00", exact: true }).click();
  await page.getByRole("button", { name: "Suivant" }).click();

  // --- Step 3 · coach.
  await expect(page.getByRole("heading", { name: /Étape 3\/6/ })).toBeVisible();
  await page.getByLabel("Prénom").fill("Coa");
  await page.getByLabel("Nom", { exact: true }).fill("Ch");
  await page.getByRole("button", { name: "Ajouter le coach" }).click();
  await expect(page.locator('input[value="Coa"]')).toBeVisible();
  await page.getByRole("button", { name: "Suivant" }).click();

  // --- Step 4 · constraints (none — skip).
  await expect(page.getByRole("heading", { name: /Étape 4\/6/ })).toBeVisible();
  await page.getByRole("button", { name: "Suivant" }).click();

  // --- Step 5 · recap → generation.
  await expect(page.getByRole("heading", { name: /Étape 5\/6/ })).toBeVisible();
  await page.getByRole("button", { name: "Continuer vers la génération" }).click();

  // --- Step 6 · launch a REAL generation and wait for the placed planning.
  await expect(page.getByRole("heading", { name: /Étape 6\/6/ })).toBeVisible();
  await page.getByRole("button", { name: "Lancer la génération" }).click();
  // The embedded planning replaces the launcher once a schedule is COMPLETED.
  await expect(page.getByText("SM1").first()).toBeVisible({ timeout: 180_000 });

  // --- Validate the completed planning (→ VALIDATED baseline, unlocks the cockpit).
  await page.getByRole("button", { name: "Valider" }).click();
  const confirm = page.getByRole("button", { name: /^Valider$/ }).last();
  if (await confirm.isVisible().catch(() => false)) {
    await confirm.click(); // confirm dialog, if any
  }
  await expect(page.getByText("Validé")).toBeVisible({ timeout: 15_000 });

  // --- The home now opens on the temporal cockpit (month calendar), not the
  // work-loop gate: the month navigation is the cockpit's stable marker.
  await page.goto("/");
  await expect(page.getByRole("button", { name: "Mois suivant" })).toBeVisible({ timeout: 15_000 });
});
