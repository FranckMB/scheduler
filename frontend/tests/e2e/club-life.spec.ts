import { expect, test } from "./fixtures";
import { settleVeil } from "./support";

/**
 * **Un incident dans la vie d'un club — et l'overlay qui le couvre reste borné à SON plan.**
 *
 * P4-122, dans les mots du fondateur : « être capable de faire […] un incident qui est couvert
 * par le planning d'overlay ». C'est le témoin qui manquait — **celui qui aurait rougi sur le bug
 * du 2026-08-19** : le repli silencieux vers le plan de SAISON quand on génère une période. Le
 * gestionnaire croyait adapter sa période, et générait dans le socle.
 *
 * ⚑ **Pourquoi le club SEEDÉ, et pourquoi l'INCIDENT plutôt que la reprise** (arbitrages
 * fondateur, tous deux adossés à des mesures) :
 *
 *  - Il n'existe que **deux portes** vers un plan de période — le radar vacances et « Déclarer
 *    une indisponibilité » ; « Créer une période libre » est désactivé (`DayDialog.tsx`, « à
 *    venir »). Or le radar vacances exige une **zone de vacances**, qui vient de la fiche FFBB et
 *    est en LECTURE SEULE dans l'app : un club frais aux identifiants de test n'en a jamais.
 *  - Parmi ces deux portes, l'incident **crée sa propre période**, sur une fenêtre que ce spec
 *    choisit. Il ne dépend donc ni de la structure de vacances du seed, ni de ses plans
 *    existants — contrairement à la reprise, dont les segments libres varient avec le seed.
 *
 * Le socle et la génération « from scratch », eux, restent couverts par `journey.spec.ts`, qui
 * les fait sur un club neuf, de l'inscription jusqu'à la réouverture.
 *
 * ⚠ **La base e2e n'est JAMAIS réinitialisée** : ce spec est IDEMPOTENT. L'indisponibilité n'est
 * déclarée que si son motif n'est pas déjà à l'écran ; une période déjà dotée d'un plan offre
 * « Reprendre » là où une période vierge offre « Adapter » ; une version déjà générée n'est pas
 * régénérée. En CI le seed est vierge, en local il porte l'état du run précédent — et les deux
 * doivent rendre le même verdict. C'est la leçon de `matches.spec.ts`.
 */

const EMAIL = "mara.mb@bccl.fr";
const PASSWORD = "maraboubccl";

/** Le motif de l'indisponibilité créée ici — il rend la période RECONNAISSABLE d'un run à l'autre. */
const MOTIF = "Incident e2e";

/**
 * La fenêtre de l'incident : **du 1er au 4 septembre 2026**. Deux contraintes la déterminent, et
 * les deux ont été apprises en la plaçant mal.
 *
 *  1. **Hors de tout plan du seed** — vacances d'été jusqu'au 31/08, adaptation Matéo 07→27/09 :
 *     deux plans de période qui se chevauchent sont refusés en 409 `window_already_planned`
 *     (P2-38, `PeriodWindowUniquenessGuard`). Le créneau du 1er au 6 septembre est le seul libre
 *     à proximité.
 *  2. **PROCHE de la date courante du club.** Le cockpit est un calendrier du MOIS affiché et
 *     son panneau « À traiter » a un horizon court : un incident déclaré en octobre est bien
 *     enregistré, mais **invisible** depuis l'accueil — aucune carte, donc aucun geste possible.
 *     Mesuré : avec une fenêtre au 5 octobre, le cockpit n'offrait ni « Adapter » ni « Reprendre ».
 */
const INCIDENT_START = "2026-09-01";
const INCIDENT_END = "2026-09-04";

async function login(page: import("./fixtures").Page): Promise<void> {
  await page.goto("/login");
  await page.getByLabel("Email").fill(EMAIL);
  await page.getByLabel("Mot de passe", { exact: true }).fill(PASSWORD);
  await page.getByRole("button", { name: "Se connecter" }).click();
  await expect(page.getByRole("button", { name: "Saison de travail" })).toBeVisible({ timeout: 20_000 });
}

/**
 * Le cockpit, une fois RÉELLEMENT prêt à recevoir un clic.
 *
 * ⚠ `goto("/")` rend la main dès le premier octet : le bandeau du haut arrive avant les cartes du
 * radar, et le voile d'action peut encore couvrir l'écran. Décider « Reprendre ou Adapter ? »
 * entre les deux lit un écran incomplet — le clic frappe un élément qui disparaît, et rien ne
 * navigue.
 */
async function openCockpit(page: import("./fixtures").Page): Promise<void> {
  await page.goto("/");
  await expect(page.getByRole("button", { name: /Tous les plannings/ })).toBeVisible({ timeout: 30_000 });
  await settleVeil(page);
}

/** Les versions offertes par l'écran EMBARQUÉ = la lignée du plan affiché. Vide = rien de généré. */
async function versionsOf(page: import("./fixtures").Page): Promise<string[]> {
  const selector = page.getByRole("combobox", { name: /version du planning/i });

  return (await selector.count()) === 0 ? [] : selector.locator("option").allTextContents();
}

/**
 * Le titre de la période, LU une fois résolu.
 *
 * ⚠ Le bandeau rend `Mode période — {title ?? "…"}` : lu trop tôt, il donne l'ELLIPSE comme
 * titre, et toutes les assertions suivantes portent alors sur une chaîne qui n'existe nulle part.
 */
async function readPeriodTitle(page: import("./fixtures").Page): Promise<string> {
  let title = "";
  await expect
    .poll(
      async () => {
        const text = (await page.getByText(/^Mode période — /).first().textContent()) ?? "";
        title = text.replace(/^Mode période — /, "").trim();

        return title;
      },
      { timeout: 30_000 },
    )
    .not.toBe("…");

  return title;
}

/** Le socle : écran de la version EN VIGUEUR, sans sélecteur (choisir une version est un geste embarqué). */
async function expectSocleIntact(page: import("./fixtures").Page, label: string): Promise<void> {
  await page.goto("/planning");
  await expect(page.getByText("Terminé").first(), `${label} : le socle doit rester terminé`).toBeVisible({ timeout: 30_000 });
  await expect(page.getByRole("combobox", { name: /version du planning/i }), `${label} : /planning est l'écran de la version en vigueur — aucun sélecteur`).toHaveCount(0);
}

// ⚠ **PARQUÉ (`fixme`) — il ne tourne pas encore, et voici EXACTEMENT où il s'arrête.**
//
// Le parcours mène l'incident jusqu'à l'ouverture de sa période, puis échoue au moment de
// désigner la BONNE carte du radar : `opener.last()` clique une carte dont la fenêtre est déjà
// planifiée, et le serveur refuse en 409 `window_already_planned`. Reproductible sur un seed
// FRAÎCHEMENT rechargé, donc déterministe — ce n'est pas un aléa de données.
//
// Le geste qui manque est de viser l'ouvreur **de la carte qui porte le motif de l'incident**,
// au lieu d'une position dans la liste. Il faut pour cela connaître le conteneur de carte du
// radar (`RadarPanel`), ce qui se lit dans le code plutôt que par sondage — c'est la première
// chose à faire en reprenant.
//
// ⚑ Il est commité MALGRÉ cela parce qu'il porte des faits MESURÉS qui ne sont écrits nulle part
// ailleurs, et que la prochaine session ne doit pas repayer : les deux seules portes vers un plan
// de période, l'horizon du cockpit qui rend un incident lointain invisible, la garde de
// chevauchement, l'ellipse du bandeau. `fixme` le laisse visible sans rougir la CI.
test.fixme("un incident déclaré ouvre un overlay borné à SON plan, sans toucher au socle", async ({ page }) => {
  test.setTimeout(420_000);

  await login(page);

  // --- 0 · Le socle est là, validé. (`journey.spec` prouve qu'on sait le CONSTRUIRE de zéro ;
  //         ici il est le décor dont on vérifie qu'il ne bouge pas.)
  await expectSocleIntact(page, "avant l'incident");

  // --- 1 · L'INCIDENT — un gymnase indisponible sur une fenêtre libre.
  await openCockpit(page);
  if ((await page.getByText(MOTIF).count()) === 0) {
    await page.getByRole("button", { name: "Déclarer" }).first().click();
    const declaration = page.getByRole("dialog");
    await expect(declaration).toBeVisible({ timeout: 15_000 });
    await declaration.getByLabel("Début de l'indisponibilité").fill(INCIDENT_START);
    await declaration.getByLabel("Fin de l'indisponibilité").fill(INCIDENT_END);
    await declaration.getByLabel("Motif de l'indisponibilité").fill(MOTIF);
    await declaration.getByRole("button", { name: "Déclarer" }).click();
    await expect(declaration).toBeHidden({ timeout: 30_000 });
  }

  // --- 2 · La période née de l'incident s'ouvre par la même porte que toute période.
  await openCockpit(page);
  const opener = page.getByRole("button", { name: /^(Reprendre|Adapter)$/ });
  await expect(opener.first(), "l'incident déclaré doit offrir une période à adapter").toBeVisible({ timeout: 30_000 });
  // La DERNIÈRE carte : le radar range les périodes par date, et la nôtre (début septembre)
  // vient après les vacances d'été que porte le seed.
  await opener.last().click();
  const weeks = page.getByRole("dialog");
  if ((await weeks.count()) > 0 && (await weeks.isVisible())) {
    // Une période multi-semaines demande QUELLES semaines ajuster ; la nôtre tient en une.
    await page.getByRole("button", { name: /^(Créer (le|les) |Adapter toute la période)/ }).first().click();
  }
  await expect(page).toHaveURL(/\/wizard/, { timeout: 30_000 });

  const periodTitle = await readPeriodTitle(page);

  // --- 3 · Générer l'overlay, s'il ne l'est pas déjà (idempotence).
  await page.getByRole("button", { name: "Génération", exact: false }).first().click();
  await expect(page.getByRole("heading", { name: /Étape 6\/6/ })).toBeVisible({ timeout: 30_000 });
  if ((await versionsOf(page)).length === 0) {
    await page.getByRole("button", { name: "Générer le planning de période" }).click();
    // Mesuré à 4 s sur ce club ; la marge couvre un runner chargé, pas une attente à l'aveugle.
    await expect(page.getByRole("combobox", { name: /version du planning/i }), `${periodTitle} : la génération n'a produit aucune version`).toBeVisible({ timeout: 180_000 });
  }

  // --- 4 · LES TÉMOINS DU BORNAGE — le cœur de ce parcours.
  //
  // Le bug du 2026-08-19 faisait retomber l'écran de période sur le plan de SAISON en silence.
  // `PlanningToolbar` porte d'ailleurs le commentaire « bug fondateur 2026-08-19 » à l'endroit
  // où il borne la liste des versions.

  // T1 — l'écran NOMME son plan. Un wizard resté en mode saison n'affiche pas ce bandeau.
  await expect(page.getByText(`Mode période — ${periodTitle}`), "l'écran doit se dire en mode période sur SA période").toBeVisible();

  // T2 — le sélecteur ne montre QUE la lignée de ce plan. Le socle du club seedé est VALIDÉ :
  // s'il fuitait ici, son libellé « en vigueur » apparaîtrait dans la liste.
  const versions = await versionsOf(page);
  expect(versions.length, "au moins une version d'overlay attendue").toBeGreaterThan(0);
  for (const version of versions) {
    expect(version, `« ${version} » n'appartient pas à la lignée de cette période — le socle a fui dans le sélecteur`).not.toMatch(/en vigueur/i);
    expect(version, `« ${version} » ne ressemble pas à une version de période`).toMatch(/^V\d+/);
  }

  // T3 — le socle n'a pas bougé pendant qu'on adaptait.
  await expectSocleIntact(page, "après l'overlay");

  // T4 — les deux plannings coexistent, chacun avec son état.
  await openCockpit(page);
  await page.getByRole("button", { name: /Tous les plannings/ }).first().click();
  const plannings = page.getByRole("dialog");
  await expect(plannings).toBeVisible({ timeout: 15_000 });
  await expect(plannings, "le socle doit rester listé et VALIDÉ").toContainText("Validé");
  await expect(plannings, "l'overlay de l'incident doit être listé à côté du socle").toContainText(periodTitle);
});
