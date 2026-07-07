import { expect, test } from "@playwright/test";

import { uniqueAra } from "./support";

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

/**
 * P2-PR1 — re-dating step: after "Préparer la saison suivante", the club events
 * of N are offered for re-dating (+364 days suggested); re-dating creates them
 * in the draft and the step does not reopen once the draft has events.
 */
test("transition offers to re-date N's events into the draft, once", async ({ page }) => {
  await registerClub(page);

  const selectorTrigger = page.getByRole("button", { name: "Saison de travail" });
  await expect(selectorTrigger).toBeVisible({ timeout: 15_000 });

  // Create a club event in N via the API (the cockpit calendar is gated on a
  // validated baseline — out of this test's scope). Token from the persisted
  // auth store; season = server-derived current (no header).
  const token = await page.evaluate(() => JSON.parse(localStorage.getItem("cs-auth") ?? "{}")?.state?.token as string | undefined);
  expect(token).toBeTruthy();
  const created = await page.request.post("/api/calendar_entries", {
    headers: { Authorization: `Bearer ${token}`, "Content-Type": "application/ld+json" },
    data: { kind: "event", title: "AG e2e", startDate: "2026-10-03", endDate: "2026-10-03", isDisruptive: true, status: "active" },
  });
  expect(created.status()).toBe(201);

  // Prepare N+1 → the re-dating dialog opens with the event, date shifted +364.
  await selectorTrigger.click();
  await page.getByRole("menuitem", { name: /Préparer la saison suivante/i }).click();
  await page.getByRole("button", { name: "Préparer", exact: true }).click();
  await expect(page.getByText(/structure copiée/i)).toBeVisible({ timeout: 15_000 });

  await expect(page.getByRole("heading", { name: "Reconduire les événements" })).toBeVisible({ timeout: 15_000 });
  await expect(page.getByText("AG e2e")).toBeVisible();
  await expect(page.getByLabel("Nouvelle date de début de AG e2e")).toHaveValue("2027-10-02"); // same weekday next year

  await page.getByRole("button", { name: /Reconduire 1 événement/ }).click();
  await expect(page.getByText(/reconduit/)).toBeVisible({ timeout: 15_000 });

  // Relaunch "Préparer" → 409 existing successor → the step must NOT reopen
  // (the draft now has an event).
  await selectorTrigger.click();
  await page.getByRole("menuitem", { name: /Préparer la saison suivante/i }).click();
  await page.getByRole("button", { name: "Préparer", exact: true }).click();
  await expect(page.getByText(/existe déjà/i)).toBeVisible({ timeout: 15_000 });
  await page.waitForTimeout(1500);
  await expect(page.getByRole("heading", { name: "Reconduire les événements" })).toHaveCount(0);
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
