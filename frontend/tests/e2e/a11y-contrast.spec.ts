import { expect, test } from "@playwright/test";

import { expectNoContrastViolations, forceTheme, registerAndVerify, uniqueAra } from "./support";

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

    // Register = 2 écrans (sport puis champs) — les deux sont publics, on vérifie les deux.
    await page.goto("/register");
    await expect(page.getByRole("button", { name: /continuer/i })).toBeVisible();
    await expectNoContrastViolations(page, `register · sport (${mode})`);
    await page.getByRole("button", { name: /continuer/i }).click();
    await expect(page.getByRole("button", { name: /créer le compte/i })).toBeVisible();
    await expectNoContrastViolations(page, `register · champs (${mode})`);
  });
}

for (const mode of MODES) {
  test(`contrast — wizard data entry (${mode})`, async ({ page }) => {
    test.setTimeout(120_000);
    await forceTheme(page, mode);

    const ara = uniqueAra("A11Y");
    await registerAndVerify(page, { email: `a11y-${ara}@e2e.fr`, ara, firstName: "A11y", lastName: "Contrast", clubName: "A11y Club" });
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

/**
 * A11Y-06: the semantic status tokens (`--warning`, `--success`) are used as
 * normal-size text (`text-sm`/`text-xs` in DiagnosticsPanel, ConflictRadar,
 * RecapStep…), where axe on the public screens never renders them. axe only
 * checks text that is actually painted, so a token that fails in a state we don't
 * navigate to would slip through. Measure the token pairs DIRECTLY, in both
 * themes, against BOTH surfaces a status label can sit on (background + card):
 * WCAG 1.4.3 requires 4.5:1 for normal text. Light `--warning`/`--success` were
 * ~3:1 and were darkened to clear it — this locks that in.
 */
for (const mode of MODES) {
  test(`contrast — semantic status tokens as normal text (${mode})`, async ({ page }) => {
    await forceTheme(page, mode);
    await page.goto("/login");

    const ratios = await page.evaluate(() => {
      const cv = document.createElement("canvas");
      cv.width = cv.height = 1;
      const ctx = cv.getContext("2d")!;
      const probe = document.createElement("div");
      document.body.appendChild(probe);
      const toRgb = (color: string): [number, number, number] => {
        ctx.clearRect(0, 0, 1, 1);
        ctx.fillStyle = color;
        ctx.fillRect(0, 0, 1, 1);
        const d = ctx.getImageData(0, 0, 1, 1).data;
        return [d[0], d[1], d[2]];
      };
      const of = (cls: string, prop: "color" | "backgroundColor"): [number, number, number] => {
        probe.className = cls;
        return toRgb(getComputedStyle(probe)[prop]);
      };
      const lum = ([r, g, b]: [number, number, number]): number => {
        const c = [r, g, b].map((v) => {
          const x = v / 255;
          return x <= 0.03928 ? x / 12.92 : ((x + 0.055) / 1.055) ** 2.4;
        });
        return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
      };
      const ratio = (a: [number, number, number], b: [number, number, number]): number => {
        const [l1, l2] = [lum(a), lum(b)].sort((x, y) => y - x);
        return (l1 + 0.05) / (l2 + 0.05);
      };
      const bg = of("bg-background", "backgroundColor");
      const card = of("bg-card", "backgroundColor");
      const out: Record<string, number> = {};
      for (const token of ["text-warning", "text-success"]) {
        const fg = of(token, "color");
        out[`${token} on background`] = ratio(fg, bg);
        out[`${token} on card`] = ratio(fg, card);
      }
      probe.remove();
      return out;
    });

    for (const [pair, ratio] of Object.entries(ratios)) {
      expect(ratio, `${pair} (${mode}) = ${ratio.toFixed(2)}:1, needs ≥ 4.5 for normal text`).toBeGreaterThanOrEqual(4.5);
    }
  });
}

/**
 * Keyboard reachability + visible focus on the public forms (WCAG 2.1.1 / 2.4.7):
 * tabbing from the top reaches the email + password fields and the NAMED submit
 * control, and each focused control gains a FOCUS-INDUCED ring — an outline, or a
 * box-shadow that DIFFERS from the control's resting shadow. Comparing against the
 * resting style is deliberate: an input carries a permanent `shadow-sm`, so a
 * "boxShadow !== none" check would pass even if the real focus ring were removed.
 */
test("keyboard — login form is reachable with a focus-induced ring", async ({ page }) => {
  await page.goto("/login");
  await expect(page.getByRole("button", { name: /se connecter/i })).toBeVisible();

  // Snapshot each focusable's RESTING outline/shadow (nothing focused yet), keyed
  // by a data-idx we stamp on it, so the walk can prove the ring appeared on focus.
  const resting: { idx: number; outlineW: number; shadow: string }[] = await page.evaluate(() => {
    const els = Array.from(document.querySelectorAll<HTMLElement>("input, button, a[href], select, textarea, [tabindex]"));
    return els.map((el, i) => {
      el.dataset.kbIdx = String(i);
      const s = getComputedStyle(el);
      return { idx: i, outlineW: parseFloat(s.outlineWidth) || 0, shadow: s.boxShadow };
    });
  });

  const reached: { key: string; name: string }[] = [];
  for (let i = 0; i < 12; i++) {
    await page.keyboard.press("Tab");
    const info = await page.evaluate(() => {
      const el = document.activeElement as HTMLElement | null;
      if (!el || el === document.body) return null;
      const s = getComputedStyle(el);
      return {
        idx: el.dataset.kbIdx ?? null,
        tag: el.tagName.toLowerCase(),
        type: (el as HTMLInputElement).type ?? "",
        name: el.getAttribute("aria-label") ?? el.textContent?.trim() ?? "",
        outlineShown: s.outlineStyle !== "none" && (parseFloat(s.outlineWidth) || 0) > 0,
        shadow: s.boxShadow,
      };
    });
    if (!info) continue;
    const rest = info.idx === null ? undefined : resting.find((r) => String(r.idx) === info.idx);
    const shadowChanged = rest ? info.shadow !== rest.shadow : info.shadow !== "none";
    expect(info.outlineShown || shadowChanged, `focused ${info.tag} "${info.name}" gained no focus-induced ring (outline/shadow unchanged from resting)`).toBe(true);
    reached.push({ key: `${info.tag}:${info.type}`, name: info.name });
  }

  expect(reached.some((r) => r.key === "input:email" || r.key === "input:text")).toBe(true);
  expect(reached.some((r) => r.key === "input:password")).toBe(true);
  // The primary action specifically — not merely "some button" — must be reachable.
  expect(reached.some((r) => r.key.startsWith("button") && /se connecter/i.test(r.name)), "submit button 'Se connecter' was never reached by Tab").toBe(true);
});
