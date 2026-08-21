import { expect, test } from "@playwright/test";

import { registerAndVerify, uniqueAra } from "./support";

/**
 * Lot C PR-2 — le VOILE BLOQUANT mange le 2e clic d'un impatient. C'est l'axe que jsdom ne peut
 * PAS voir : il n'a ni moteur de mise en page ni hit-testing, donc « le clic retombe sur l'overlay
 * et pas sur le bouton » ne se prouve qu'en vrai navigateur.
 *
 * Scénario : créer une équipe (POST /api/teams) est RALENTI ; pendant la mutation, l'overlay du
 * voile (z-60, plein écran) s'interpose. Un second clic au même endroit retombe sur lui.
 *
 * ⚠ TÉMOIN (patron `modal-reachability.spec.ts`) : on assert que l'overlay S'EST bien interposé.
 * Sans lui, « un seul POST » ne prouverait rien — il pourrait venir d'un bouton désarmé plutôt que
 * du voile. Si rien ne se voile, OU si zéro POST part, le test ÉCHOUE en le disant.
 */
test("double-clic pendant une mutation ralentie → UN seul appel réseau (le voile mange le 2e)", async ({ page }) => {
  test.setTimeout(120_000);

  const ara = uniqueAra("VEIL");
  await registerAndVerify(page, { email: `veil-${ara}@e2e.fr`, ara, firstName: "Voile", lastName: "Bloquant", clubName: "Club Voile" });

  await page.goto("/wizard");
  await expect(page.getByRole("heading", { name: /Étape 1\/6/ })).toBeVisible({ timeout: 15_000 });

  // Ralentir la création d'équipe et compter les POST.
  let teamPosts = 0;
  await page.route("**/api/teams", async (route) => {
    if ("POST" === route.request().method()) {
      teamPosts += 1;
      await new Promise((r) => setTimeout(r, 900));
    }
    await route.continue();
  });

  await page.getByLabel("Nom de l'équipe").fill("SM1");
  await page.getByLabel("Catégorie").selectOption({ label: "Senior" });

  const addBtn = page.getByRole("button", { name: "Ajouter l'équipe" });
  // ⚠ Point de départ HONNÊTE : le bouton est `disabled={create.isPending || categoriesLoading}`
  // (TeamsStep). Sur une CI lente les catégories n'ont pas fini de charger ici — un `mouse.click`
  // BRUT tomberait dans le vide (bouton désactivé → aucun POST → aucun voile → témoin rouge, le vrai
  // défaut débusqué en CI). On attend donc qu'il soit ACTIVABLE avant de mesurer et de cliquer.
  // (Et le bouton étant `ml-auto`, sa position se stabilise seulement une fois le select Catégorie
  // rempli — mesurer avant fausserait les coordonnées du 2e clic.)
  await expect(addBtn, "le bouton d'ajout doit devenir activable (catégories chargées)").toBeEnabled();
  const box = await addBtn.boundingBox();
  expect(box, "le bouton d'ajout doit être mesurable").not.toBeNull();
  const cx = box!.x + box!.width / 2;
  const cy = box!.y + box!.height / 2;

  // 1er clic : un VRAI geste (`addBtn.click()` passe par l'actionnabilité) → mutation ralentie + voile.
  await addBtn.click();

  // TÉMOIN : le voile s'est interposé (overlay plein écran). Sans lui, la garantie ne prouve rien.
  await expect(page.getByTestId("action-veil"), "le voile bloquant doit s'interposer pendant la mutation").toBeVisible();

  // 2e clic d'impatient BRUT — JAMAIS un `locator.click()`, qui attendrait sagement la levée du voile
  // et ne prouverait plus rien. AU MÊME endroit, PENDANT la mutation : il retombe sur l'overlay, mangé.
  await page.mouse.click(cx, cy);

  // La mutation ralentie se termine → le voile tombe.
  await expect(page.getByTestId("action-veil")).toBeHidden({ timeout: 15_000 });

  // La garantie : UN seul POST /api/teams, jamais deux (0 ferait aussi rougir le témoin ci-dessus).
  expect(teamPosts, `le voile doit manger le 2e clic — ${teamPosts} POST /api/teams observés`).toBe(1);
});
