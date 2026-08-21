import { expect, test } from "./fixtures";

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

  // --- Step 1 · team (2 sessions/week by default ; la CATÉGORIE se choisit).
  // Plus de catégorie par défaut depuis la revue #347 : `categories[0]` valait « Vétéran »
  // pour tous les clubs depuis que le catalogue est ordonné, et un club de jeunes y
  // classait toute sa saison. Le choix persiste d'un ajout au suivant.
  await page.getByLabel("Nom de l'équipe").fill("SM1");
  await page.getByLabel("Catégorie").selectOption({ label: "Senior" });
  await page.getByRole("button", { name: "Ajouter l'équipe" }).click();
  await expect(page.locator('input[value="SM1"]')).toBeVisible();
  await page.getByRole("button", { name: "Suivant" }).click();

  // --- Step 2 · venue + two weekly slots (2 sessions to place).
  await expect(page.getByRole("heading", { name: /Étape 2\/6/ })).toBeVisible();
  await page.getByLabel("Nom du gymnase").fill("Gymnase E2E");
  await page.getByRole("button", { name: "Ajouter un gymnase" }).click();
  // Created venue is auto-selected in the venue picker; the grid is open.
  await expect(page.getByLabel("Gymnase", { exact: true })).toHaveValue(/./);
  // P4-37 : la barre « À poser » dit enfin ce qu'on en fait — rien ne l'indiquait. Elle
  // ne vit qu'une fois un gymnase sélectionné, d'où sa place ICI et pas avant l'ajout.
  await expect(page.getByText(/cliquez la grille pour ajouter un créneau/i)).toBeVisible();
  // P4-37 (revue #349) — le mode SAISON pose et édite des créneaux par deux appels à
  // `slotPlacementError` qu'AUCUN test ne couvrait : il n'existe pas de VenuesStep.test.tsx
  // et le harnais y coûterait plus qu'il ne rapporte. On garde donc le geste ici, où le
  // parcours passe déjà. Le refus doit être VISIBLE et la grille rester intacte.
  await page.getByLabel("Durée à poser").selectOption("150");
  await page.getByRole("button", { name: "Lun 22:45", exact: true }).click();
  await expect(page.getByText(/finirait après minuit/i)).toBeVisible();
  await page.getByLabel("Durée à poser").selectOption("90");

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

  // --- Valider INSIDE the embedded wizard (generation step) — Valider is the
  // workspace's EXIT (2026-08-20, symétrie stricte Valider ↔ Rouvrir). The confirm
  // dialog (role=dialog "Valider le planning") always opens; confirm inside it.
  await page.getByRole("button", { name: "Valider" }).click();
  const dialog = page.getByRole("dialog", { name: "Valider le planning" });
  await expect(dialog).toBeVisible();
  await dialog.getByRole("button", { name: "Valider", exact: true }).click();

  // --- Success LANDS on /planning, the screen of the version IN FORCE. The URL
  // assertion is the falsifiable one: drop the navigation and the test goes red.
  // « Rouvrir visible » alone would be a FALSE GREEN — it shows in both screens.
  await expect(page).toHaveURL(/\/planning/, { timeout: 15_000 });

  // --- Consultation contract on /planning: the just-validated planning is displayed
  // (SM1 still on the grid), the status badge is visible, AND the version selector is
  // ABSENT — that absence is what proves we left the embedded workspace for /planning
  // (the selector is a work gesture, embedded-only), not merely that a button flipped.
  await expect(page.getByText("SM1").first()).toBeVisible();
  await expect(page.getByText("Terminé").first()).toBeVisible();
  await expect(page.getByRole("combobox", { name: /version du planning/i })).toHaveCount(0);
  const reopen = page.getByRole("button", { name: "Rouvrir" });
  await expect(reopen).toBeVisible();

  // --- Rouvrir closes the cycle: back to the wizard's generation step, the planning
  // editable again (« Valider » is offered once more, the selector is back). Thus
  // valider → consulter → rouvrir is proved end to end.
  await reopen.click();
  await expect(page).toHaveURL(/\/wizard/, { timeout: 15_000 });
  await expect(page.getByRole("heading", { name: /Étape 6\/6/ })).toBeVisible();
  await expect(page.getByText("SM1").first()).toBeVisible({ timeout: 15_000 });
  await expect(page.getByRole("button", { name: "Valider" })).toBeVisible({ timeout: 15_000 });

  // --- The home now opens on the temporal cockpit (month calendar), not the
  // work-loop gate: the month navigation is the cockpit's stable marker.
  await page.goto("/");
  await expect(page.getByRole("button", { name: "Mois suivant" })).toBeVisible({ timeout: 15_000 });
});
