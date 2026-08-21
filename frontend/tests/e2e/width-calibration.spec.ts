import { expect, test } from "@playwright/test";

type Page = import("@playwright/test").Page;

/**
 * P4-107 (3ᵉ tranche) — **les largeurs, mesurées là où elles existent.**
 *
 * Les tests unitaires (`fiche-page.test.tsx`, `modal-size.test.tsx`) épinglent des CLASSES :
 * jsdom n'a aucun moteur de mise en page, `getBoundingClientRect` y vaut 0. Ils ne peuvent
 * donc pas voir le défaut le plus bête de ce lot — **un token `--container-fiche` absent
 * d'`index.css`** : `max-w-fiche` serait alors une classe qui n'engendre AUCUN CSS, la page
 * partirait pleine largeur, et tous les tests unitaires resteraient verts.
 *
 * D'où ce fichier, et sa taille : deux mesures, pas un inventaire. La calibration se fait sur
 * **1920×1080**, l'écran de référence du fondateur — celui sur lequel « la marge était plus
 * grande que l'utile ».
 *
 * ⚠ **Le témoin du paragraphe.** La borne de lisibilité ne se prouve que si le paragraphe est
 * mesuré ÉTROIT pendant que son cadre est mesuré LARGE : les deux ensemble, sinon on ne
 * distingue pas « la borne agit » de « il n'y avait pas la place ». Et le test échoue en le
 * disant s'il ne trouve aucun paragraphe à mesurer — un scénario vide qui passe en silence est
 * le faux vert que ce dépôt a déjà payé une fois (`modal-reachability.spec.ts`). Le détail de
 * ce que ce témoin a appris en rougissant est écrit à l'endroit de l'assertion.
 */

// Club de dev seedé (BasketballInit) — même porte que `matches.spec.ts`.
const EMAIL = "mara.mb@bccl.fr";
const PASSWORD = "maraboubccl";

/** 52rem : le token `--container-fiche`. La valeur est écrite ICI en dur, exprès — si elle
 *  change dans `index.css`, ce test doit rougir et obliger à venir dire que c'était voulu. */
const FICHE_WIDTH = 832;

async function login(page: Page): Promise<void> {
  await page.goto("/login");
  await page.getByLabel("Email").fill(EMAIL);
  await page.getByLabel("Mot de passe", { exact: true }).fill(PASSWORD);
  await page.getByRole("button", { name: "Se connecter" }).click();
  await expect(page.getByRole("button", { name: "Saison de travail" })).toBeVisible({ timeout: 15_000 });
}

test.describe("largeurs sur 1920×1080", () => {
  test.use({ viewport: { width: 1920, height: 1080 } });

  test("les pages fiche mesurent la largeur du cadre partagé, et leurs textes restent lisibles dedans", async ({ page }) => {
    test.setTimeout(90_000);
    await login(page);

    for (const path of ["/profile", "/club"]) {
      await page.goto(path);
      // Le cadre : le premier élément de la page principale qui porte la classe du token.
      const fiche = page.locator("main .max-w-fiche").first();
      await expect(fiche, `${path} : la page doit être cadrée par FichePage`).toBeVisible({ timeout: 20_000 });

      const box = await fiche.boundingBox();
      expect(box, `${path} : cadre non mesurable`).not.toBeNull();
      // Tolérance d'1 px (sous-pixel navigateur). Une largeur de ~1856 px signalerait un
      // token `--container-fiche` absent : la classe existe, le CSS non.
      expect(
        Math.round(box!.width),
        `${path} : le cadre mesure ${Math.round(box!.width)} px au lieu de ${FICHE_WIDTH}. Si c'est la largeur de la fenêtre, le token --container-fiche manque à index.css et \`max-w-fiche\` n'engendre aucun CSS.`,
      ).toBeGreaterThanOrEqual(FICHE_WIDTH - 1);
      expect(Math.round(box!.width), `${path} : le cadre dépasse la largeur fiche`).toBeLessThanOrEqual(FICHE_WIDTH + 1);
    }

    // La borne de lisibilité, sur la page qui porte le plus de texte d'aide (Club).
    //
    // ⚠ **Ce que ce témoin a appris, et pourquoi il a changé de forme.** Sa première version
    // exigeait un paragraphe de plus de 120 caractères, en croyant qu'un texte long était
    // nécessaire pour que la mesure prouve quelque chose. Elle a ROUGI : les textes d'aide de
    // /club plafonnent à ~113 caractères, et les accordéons fermés n'en rendent aucun. Or la
    // prémisse était fausse — un `<p>` est un bloc : sa largeur vaut min(conteneur, max-width),
    // **quelle que soit la longueur du texte**. Un texte court aurait donc mesuré 832 px sans la
    // borne, exactement comme un texte long.
    //
    // La propriété qui compte est donc celle-ci : **le paragraphe est BORNÉ pendant que son
    // cadre, lui, est large** — s'ils mesuraient pareil, la borne ne s'appliquerait pas. D'où
    // les deux assertions, plus le témoin « il faut au moins un paragraphe à mesurer ».
    const paragraph = await page.evaluate(() => {
      const candidate = Array.from(document.querySelectorAll<HTMLElement>("main .max-w-fiche p")).find(
        (p) => (p.textContent ?? "").trim().length > 40,
      );
      return candidate ? { width: candidate.getBoundingClientRect().width, text: (candidate.textContent ?? "").slice(0, 60) } : null;
    });

    expect(
      paragraph,
      "aucun paragraphe d'au moins 40 caractères sur /club : le scénario ne met RIEN à l'épreuve — sans texte à mesurer, la borne de lisibilité n'est pas testée",
    ).not.toBeNull();
    expect(
      Math.round(paragraph!.width),
      `le texte d'aide « ${paragraph!.text}… » occupe ${Math.round(paragraph!.width)} px alors que son cadre en fait ${FICHE_WIDTH} : la borne \`[&_p]:max-w-prose\` ne s'applique pas. Élargir la fiche sans borner ses paragraphes échange un défaut contre un autre.`,
    ).toBeLessThan(FICHE_WIDTH - 200);
    // Deuxième sens : une borne qui écraserait le texte serait un autre défaut.
    expect(Math.round(paragraph!.width), "le paragraphe est anormalement étroit — la borne ne doit pas écraser le texte").toBeGreaterThan(300);
  });
});
