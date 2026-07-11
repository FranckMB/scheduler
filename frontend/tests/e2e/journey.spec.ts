import { expect, test } from "@playwright/test";

import { registerAndVerify, uniqueAra } from "./support";

/**
 * THE end-to-end journey (audit P0.2, FRT-05): a fresh club walks the whole
 * wizard (team → venue + slot → coach → constraints → recap), launches a REAL
 * generation (CP-SAT solves the 1-team instance), sees the placed planning,
 * validates it, and lands on the unlocked cockpit. This is the promise of the
 * product exercised as a user.
 */
test("full journey: wizard → generation → validated planning → cockpit", async ({ page }) => {
  test.setTimeout(240_000); // includes a real solve (small instance, seconds)

  // --- Register a fresh club + verify by email → onboarding wizard.
  const ara = uniqueAra("E2EF");
  await registerAndVerify(page, { email: `journey-${ara}@e2e.fr`, ara, firstName: "Flo", lastName: "Journey", clubName: "E2E Journey Club" });
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
  // Lot A: coach cards are read-only by default (name as text, edit on demand).
  await expect(page.getByText("Coa Ch", { exact: true })).toBeVisible();
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

  // --- Validate from the REAL planning page (validating inside the embedded
  // wizard view flips it back to the launcher: VALIDATED is not COMPLETED).
  await page.goto("/planning");
  await expect(page.getByText("SM1").first()).toBeVisible({ timeout: 15_000 });
  await page.getByRole("button", { name: "Valider" }).click();
  // The confirm dialog (role=dialog "Valider le planning") always opens — wait
  // for it, confirm, then assert the toolbar flipped to the VALIDATED state
  // ("Rouvrir" replaces "Valider"); never a substring match on "Validé", which
  // the dialog's own description contains.
  const dialog = page.getByRole("dialog", { name: "Valider le planning" });
  await expect(dialog).toBeVisible();
  await dialog.getByRole("button", { name: "Valider", exact: true }).click();
  await expect(page.getByRole("button", { name: "Rouvrir" })).toBeVisible({ timeout: 15_000 });

  // --- The home now opens on the temporal cockpit (month calendar), not the
  // work-loop gate: the month navigation is the cockpit's stable marker.
  await page.goto("/");
  await expect(page.getByRole("button", { name: "Mois suivant" })).toBeVisible({ timeout: 15_000 });
});
