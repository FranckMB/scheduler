import { expect, type Page, test } from "@playwright/test";

import { loginAsSuperAdmin, superAdminFromEnv } from "./support-admin";

/**
 * WCAG 1.4.10 (reflow) sur l'app RENDUE — l'axe que jsdom ne peut pas voir.
 *
 * Une modale plus haute que l'écran débordait **en haut ET en bas** (panneau centré,
 * `items-center`) sans rien à faire défiler : le seul recours était de dézoomer le
 * navigateur. Constaté sur PC sur la modale d'actions superadmin, retour fondateur
 * 2026-08-11, corrigé dans les deux composants partagés (`modal.tsx`, `confirm-dialog.tsx`).
 *
 * ⚠ **Ce spec ne double pas `modal-overflow.test.tsx`.** jsdom n'a aucun moteur de mise en
 * page — `boundingBox`, `scrollHeight` et `getBoundingClientRect` y valent 0 — donc le test
 * unitaire est réduit à épingler les CLASSES qui portent le contrat. Il tourne en 200 ms à
 * chaque `vitest` et défend les deux composants. Ce qui lui échappe, et qui vit ici :
 *  1. **un appelant qui défait le contrat** — `Modal` fusionne le `className` de l'appelant
 *     via `cn()` : un `max-h-none` redéborde sans toucher au composant ;
 *  2. **la mise en page interne d'un écran** — un enfant `absolute`, un `overflow-hidden`
 *     intermédiaire qui neutralise le défilement ;
 *  3. **le viewport réel**, seul endroit où `dvh` et le calcul flexbox s'observent.
 *
 * ⚑ **POURQUOI la modale superadmin, et pas une modale du wizard.** La première version de ce
 * fichier visait deux modales bon marché (éditeur de créneau, confirmation de suppression).
 * Elle a rendu un **FAUX VERT** : avec le défaut remis en place, les deux tests passaient.
 * Mesuré alors : ces modales font **192 px** et **190 px** — elles tiennent dans n'importe
 * quelle fenêtre, y compris **320×256**, la condition exacte de WCAG 1.4.10 (400 % de zoom).
 * Aucun viewport légitime ne pouvait les faire déborder : le scénario ne testait rien.
 *
 * Le défaut ne se manifeste que sur une modale LONGUE. La seule qui le soit ET qui ait
 * réellement cassé est celle-ci — le catalogue d'actions support, une douzaine d'entrées.
 * D'où le coût assumé de ce fichier : ouvrir le premier parcours e2e vers `/admin`.
 *
 * ⚠ **Le TÉMOIN est ce qui empêche le faux vert de revenir** : si rien ne déborde, le test
 * ÉCHOUE en le disant, au lieu de passer en silence.
 */

/** Assertion du fichier : la modale tient à l'écran, et ce qui dépasse est ATTEIGNABLE. */
async function expectDialogFitsAndScrolls(page: Page, label: string): Promise<void> {
  const dialog = page.getByRole("dialog");
  await expect(dialog, `${label} : la modale doit être ouverte`).toBeVisible();

  const viewport = page.viewportSize();
  expect(viewport, "viewport non défini").not.toBeNull();
  const box = await dialog.boundingBox();
  expect(box, `${label} : la modale n'a pas de boîte mesurable`).not.toBeNull();

  // Tolérance d'1 px : bordures et calcul sous-pixel du navigateur.
  expect(box!.y, `${label} : la modale déborde par le HAUT (y=${box!.y}) — contenu hors écran, inatteignable`).toBeGreaterThanOrEqual(-1);
  expect(
    box!.y + box!.height,
    `${label} : la modale déborde par le BAS (bas=${Math.round(box!.y + box!.height)}, viewport=${viewport!.height}) — c'est le défaut du 2026-08-11, celui qui oblige à dézoomer`,
  ).toBeLessThanOrEqual(viewport!.height + 1);

  // ⚠ On ne se contente pas de TROUVER un `overflow-y-auto` : on le fait défiler pour de
  // vrai. Un `overflow-y-auto` sans `min-h-0` est le piège flexbox exact du bug d'origine —
  // la classe est là, la zone ne défile jamais. La présence de la classe, c'est le test
  // unitaire qui la garde ; ici on vérifie l'EFFET.
  const verdict = await dialog.evaluate((panel: HTMLElement) => {
    const candidates = [panel, ...Array.from(panel.querySelectorAll<HTMLElement>("*"))];
    const overflowing = candidates.find((el) => el.scrollHeight > el.clientHeight + 1);
    if (!overflowing) {
      return { overflows: false, scrolled: 0 };
    }
    overflowing.scrollTop = overflowing.scrollHeight;
    return { overflows: true, scrolled: overflowing.scrollTop };
  });

  expect(
    verdict.overflows,
    `${label} : RIEN ne déborde. Deux lectures, toutes deux fautives — soit le panneau n'est PAS borné et a pris sa hauteur NATURELLE (le \`max-h\` a sauté), soit la modale est trop courte pour ce viewport et le scénario ne met rien à l'épreuve. C'est ce second cas qui a produit un faux vert avant que ce témoin existe.`,
  ).toBe(true);

  expect(
    verdict.scrolled,
    `${label} : du contenu dépasse mais RIEN ne défile — piège \`overflow-y-auto\` sans \`min-h-0\` (un enfant flex refuse de rétrécir sous son contenu)`,
  ).toBeGreaterThan(0);
}

const credentials = superAdminFromEnv();

test.describe("une modale longue reste atteignable", () => {
  // Le préflight vit dans la cible `make e2e` (côté hôte) : le conteneur e2e n'a pas de
  // socket Docker et ne peut pas semer le compte lui-même. Sans lui → SKIP explicite, jamais
  // un échec qui ressemblerait à une régression.
  test.skip(null === credentials, "préflight superadmin absent — lancez `make -C frontend e2e` (il sème le compte et exporte son secret TOTP).");

  test("catalogue d'actions support : tient à l'écran et défile, en 1440 et en 320 (WCAG 1.4.10)", async ({ page }) => {
    test.setTimeout(120_000);

    await loginAsSuperAdmin(page, credentials!);

    // La console est en 6 onglets ; les clubs vivent dans « Comptes clubs ». On y va par le
    // deep-link `?tab=` (SA, 2026-07-25) plutôt qu'en cliquant : moins de surface à casser.
    await page.goto("/admin?tab=clubs");

    // Un bouton « Actions » par ligne de club — n'importe lequel fait l'affaire, la modale
    // liste le MÊME catalogue fermé.
    const actions = page.getByRole("button", { name: "Actions" }).first();
    await expect(actions, "la console doit lister au moins un club").toBeVisible({ timeout: 20_000 });

    // ⚠ On ne PARIE plus sur une hauteur de fenêtre fixe — c'est ce qui a rendu ce test faux
    // quand A3 a raccourci la modale de 12 à 7 items : à 1440×900 une modale de 7 items ne
    // dépasse plus, le TÉMOIN refuse (à juste titre) un scénario qui ne teste rien, et le
    // test rougit sans qu'aucun bug de reflow existe. La propriété n'est pas « telle modale
    // dépasse à 900 px », c'est « le panneau s'adapte au viewport, quelle que soit sa
    // hauteur ». On MESURE donc la modale, puis on force une fenêtre plus basse qu'elle : le
    // débordement est garanti quel que soit le nombre d'items, aujourd'hui comme demain.
    for (const width of [
      1440, // bureau
      320, //  la largeur de reflow WCAG 1.4.10 (équivalent 400 % de zoom sur 1280×1024)
    ]) {
      // Ouvrir d'abord à hauteur ample pour mesurer la modale à sa taille naturelle.
      await page.setViewportSize({ width, height: 900 });
      await actions.click();
      const box = await page.getByRole("dialog").boundingBox();
      expect(box, "la modale doit être mesurable avant de rétrécir").not.toBeNull();

      // Fenêtre plus basse que la modale (plancher 200 px) : le débordement est forcé.
      const height = Math.max(200, Math.round(box!.height) - 80);
      await page.setViewportSize({ width, height });
      await expectDialogFitsAndScrolls(page, `Actions support · ${width}×${height}`);

      await page.getByRole("dialog").getByRole("button", { name: "Fermer", exact: true }).click();
      await expect(page.getByRole("dialog")).toBeHidden();
    }
  });
});
