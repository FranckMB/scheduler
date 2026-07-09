import { expect, test } from "@playwright/test";

import { expectNoContrastViolations, forceTheme, uniqueAra } from "./support";

/**
 * WCAG 2.2 AA colour-contrast (1.4.3) on the real rendered app — the axis jsdom
 * cannot see. Runs axe-core (color-contrast rule) inside Chromium on the public
 * screens in BOTH themes, and walks a fresh club through the data-entry wizard so
 * the dense screens (availability grid, constraints) are checked too.
 */
const MODES = ["dark", "light"] as const;

for (const mode of MODES) {
  test(`contrast — public screens (${mode})`, async ({ page }) => {
    await forceTheme(page, mode);

    await page.goto("/login");
    await expect(page.getByRole("button", { name: /se connecter/i })).toBeVisible();
    await expectNoContrastViolations(page, `login (${mode})`);

    await page.goto("/register");
    await expect(page.getByRole("button", { name: /créer le compte/i })).toBeVisible();
    await expectNoContrastViolations(page, `register (${mode})`);
  });
}

for (const mode of MODES) {
  test(`contrast — wizard data entry (${mode})`, async ({ page }) => {
    test.setTimeout(120_000);
    await forceTheme(page, mode);

    const ara = uniqueAra("A11Y");
    await page.goto("/register");
    await page.getByLabel("Prénom").fill("A11y");
    await page.getByLabel("Nom", { exact: true }).fill("Contrast");
    await page.getByLabel("Email", { exact: true }).fill(`a11y-${ara}@e2e.fr`);
    await page.getByLabel("Mot de passe", { exact: true }).fill("Password123!");
    await page.getByLabel(/code ara/i).fill(ara);
    await page.getByLabel(/nom du club/i).fill("A11y Club");
    await page.getByRole("button", { name: /créer le compte/i }).click();
    await expect(page.getByRole("heading", { name: /Étape 1\/6/ })).toBeVisible({ timeout: 15_000 });

    await expectNoContrastViolations(page, `wizard · équipes (${mode})`);

    // Add a team + advance to the gym availability grid (dense small text).
    await page.getByLabel("Nom de l'équipe").fill("SM1");
    await page.getByRole("button", { name: "Ajouter l'équipe" }).click();
    await page.getByRole("button", { name: "Suivant" }).click();
    await expect(page.getByRole("heading", { name: /Étape 2\/6/ })).toBeVisible();
    await expectNoContrastViolations(page, `wizard · gymnases (${mode})`);
  });
}
