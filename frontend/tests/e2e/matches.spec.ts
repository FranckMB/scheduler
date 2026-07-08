import { expect, test } from "@playwright/test";

/** Seeded dev club (BasketballInit) — full data, but INCOMPLETE onboarding
 * (cockpit state 1: no plan generated yet). Matches are locked until the main
 * plan is validated, so this spec onboards the club first (idempotent). */
const EMAIL = "mara.mb@bccl.fr";
const PASSWORD = "maraboubccl";

type Page = import("@playwright/test").Page;

async function login(page: Page): Promise<void> {
  await page.goto("/login");
  await page.getByLabel("Email").fill(EMAIL);
  await page.getByLabel("Mot de passe", { exact: true }).fill(PASSWORD);
  await page.getByRole("button", { name: "Se connecter" }).click();
  await expect(page.getByRole("button", { name: "Saison de travail" })).toBeVisible({ timeout: 15_000 });
}

/**
 * Bring the seeded club to cockpit state 3 (main plan validated) so matches are
 * unlocked. Idempotent across runs (the dev DB is not reset): if the Matchs nav
 * is already an enabled link, the socle is validated → nothing to do. Otherwise
 * drive the wizard's génération step: launch a generation if needed, then
 * validate the resulting plan.
 */
async function ensureValidated(page: Page): Promise<void> {
  await page.goto("/");
  // State 3 → the Matchs nav is a real link. State 1/2 → a disabled span / redirect.
  if (await page.getByRole("link", { name: "Matchs" }).isVisible({ timeout: 3_000 }).catch(() => false)) {
    return;
  }

  await page.goto("/wizard");
  await page.waitForLoadState("networkidle");
  // From Récap (guided landing when all data is present) the generation step is
  // reached via the footer CTA, not the (still-locked) left-nav entry.
  const cont = page.getByRole("button", { name: "Continuer vers la génération" });
  if (await cont.isVisible({ timeout: 5_000 }).catch(() => false)) {
    await cont.click();
  }
  const launch = page.getByRole("button", { name: "Lancer la génération" });
  if (await launch.isVisible({ timeout: 5_000 }).catch(() => false)) {
    await launch.click();
  }
  // COMPLETED → the "Valider" button appears; validate through the confirm dialog.
  const validate = page.getByRole("button", { name: "Valider" });
  await expect(validate).toBeVisible({ timeout: 180_000 });
  await validate.click();
  const dialog = page.getByRole("dialog", { name: "Valider le planning" });
  await expect(dialog).toBeVisible();
  await dialog.getByRole("button", { name: "Valider", exact: true }).click();
  await expect(page.getByRole("link", { name: "Matchs" })).toBeVisible({ timeout: 15_000 });
}

/**
 * End-to-end of the placement loop (module matchs PR-3) under the real stack:
 * create a fixture manually → it lands in the "à placer" list → place it (venue +
 * kickoff) → it leaves the list. The conflict radar renders throughout.
 */
test("matches: create a fixture, place it, radar renders", async ({ page }) => {
  test.setTimeout(240_000); // onboarding runs a real CP-SAT generation
  await login(page);
  await ensureValidated(page);

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
