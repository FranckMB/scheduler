# P2-9 — Verrous, alertes et garde-fous : ce qui reste

> Reprise à froid. **Il ne reste que la PR A**, et elle est BLOQUÉE par un prérequis
> technique (§PR A) — pas par son message.
>
> | Volet | État |
> |---|---|
> | Volet 1 — le solveur ne se tait plus | ✅ livré (PR #317, 2026-07-28) |
> | **PR B** — erreur bloquante au récap | ✅ **livré (PR #332, 2026-07-31)** |
> | **PR C** — prévention au clic | ✅ **livré (PR #334, 2026-07-31)** |
> | **PR A** — volet capacité | ⬜ **bloquée** : il n'existe toujours pas de source de payload en LECTURE SEULE |
>
> **L'ordre a été inversé à l'exécution** (décision fondateur 2026-07-31) : B → C → A, et
> non A → B → C. Le document numérote A en premier parce que c'est le besoin d'origine,
> mais A est la seule qui exige d'abord un chantier technique. Faire B et C d'abord a livré
> la valeur sans toucher au builder — et C a pu réutiliser la règle écrite en B, ce qui
> était précisément l'intention.

---

## Le problème d'origine, en une ligne

Un verrou HARD est **pré-placé hors du solveur** : `model.py` ne crée jamais la variable
`x[équipe, gymnase, jour, heure]` pour lui. Toute contrainte agit en forçant cette
variable à 0 — sans variable, il n'y a rien à forcer. La contrainte n'est pas *battue*,
elle est **inatteignable**.

**Décision fondateur (ALIGN-07, réaffirmée) : le verrou reste SOUVERAIN.** C'est la
décision du gestionnaire. Ce qui devait changer, c'est le silence.

---

## PR A — Le volet capacité (prérequis bloquant)

### Le besoin

Dire **avant** la génération qu'il manque des créneaux, avec la formulation du fondateur :

> Vos équipes demandent **123** créneaux, vos gymnases n'en offrent que **118**.
> Au moins **5 séances** ne pourront pas être placées.

Le solveur le signale déjà **après** coup (`session_below_effective_min`), mais le
gestionnaire l'apprend alors au bout d'une génération, sur un planning déjà bancal.

### ⚠ Le préalable, sans lequel cette PR échouera une quatrième fois

**Il faut une source de payload en LECTURE SEULE.** Elle n'existe pas.

Trois tentatives ont échoué en revue, toujours sur la même faute — reproduire à la main
ce que `ScheduleConstraintBuilder` calcule :

| Tentative | Ce qui a été raté |
|---|---|
| Requêtes d'entités, périmètre saison + période | L'offre ignorait les gymnases désactivés et les jours fermés ; la demande ignorait `TeamPeriodOverride` |
| Idem, restreint à la saison | Le bridage `canSplit` (capacité forcée à 1 sur gymnase non divisible) ignoré ; un filtre `isActive` inventé que le builder n'applique pas |
| Appel à `buildForClubSeason` | **Perte de données** : `serializeTeam` → `TeamTagService::syncTeamTags` supprime puis recrée les `TeamTagAssignment` avec un `flush()` intermédiaire ; le contrôleur de validation ne flushe jamais → les tags de la dernière équipe disparaissaient en ouvrant le récap |

Les deux portes actuelles écrivent ou sont indisponibles :
- `buildForClubSeason` **écrit** (via `syncTeamTags`) et son cache Redis est clé sur le
  **club seul**, pas la saison ;
- `buildForOverlay` exige un `Schedule`, qui **n'existe pas avant la génération** — donc
  aucun calcul de période n'est possible par cette voie.

**Le préalable est LEVÉ** — lot **P2-9ter** livré le 2026-07-31 (PR #336). Le chemin de
lecture existe : `buildForPeriodPlan(clubId, seasonId, schedulePlanId, entry, solverSeed,
?scheduleId)`, dont `buildForOverlay` n'est plus qu'un adaptateur. Il **n'écrit rien** et
n'exige **aucun `Schedule`** : appelé sans `scheduleId`, il rend le payload exact d'une
période AVANT toute génération. **PR A peut donc partir** — en consommant ce chemin, jamais
en recalculant à la main.

Trois décisions y sont prises, et une découverte le rend plus petit qu'annoncé :

1. `syncTeamTags` est **supprimé** du builder, pas rendu optionnel — `TeamTagSyncListener`
   le fait déjà sur `postPersist`/`postUpdate` de `Team` au `postFlush`, et
   `determineTagNames` ne dérive que du `sportCategoryId` : tout changement de tag passe
   donc par un update de `Team`. L'appel dans `serializeTeam` est une re-synchro
   défensive **en lecture** — c'est elle qui faisait perdre les tags.
2. Le `seasonId` entre dans la clé de cache **dans le même lot**. ⚠ La même clé est
   reconstruite dans `CacheInvalidationListener.php:82` : la changer d'un seul côté casse
   l'invalidation **en silence**.
3. 💡 `buildForOverlay` n'utilise le `Schedule` que pour **5 scalaires**, dont 4 sont
   connus avant génération. Le 5ᵉ (`getId()`) ne sert qu'aux `ScheduleSlotTemplate`,
   absents avant génération — et c'est **sémantiquement juste** : les verrous durables
   sont les `Reservation`, portées par le `schedulePlanId`. La porte n'est donc pas
   structurellement close, elle demande une signature scalaire et un adaptateur.

Le lot a livré **le prérequis SEUL** (décision fondateur A1) : c'est la taille qui a tué les
trois tentatives. ⚑ Au passage il a corrigé un défaut que personne n'avait vu : `teams[].tags`
sortait **toujours vide** du payload (49/49 équipes), la relecture tombant sur la table que
`syncTeamTags` venait de vider.

### La règle, une fois la source disponible

- **Demande** = somme des `sessionsPerWeek` du payload. C'est ce que l'équipe *veut* ;
  retenir `minSessionsOverride` sous-estimait le besoin.
- **Offre** = somme des **capacités** des créneaux du payload (un créneau à capacité 2
  sert deux équipes), déjà bridées par le builder.
- Alerte si `offre < demande`. **`>=` et non `>`** : offre égale à demande ne doit pas
  alerter.
- Le message annonce **« au moins X »** : la condition est *nécessaire, pas suffisante*
  (les créneaux sont situés). Ne **jamais** affirmer l'inverse.

### À livrer avec

**Le câblage frontend des `warnings`** — défaut préexistant : le backend produit déjà des
avertissements (« contrainte visant un gymnase désactivé ») que **rien n'affiche**.
`ValidateResult` (`frontend/src/features/wizard/api.ts`) ne déclare pas `warnings`, et la
branche `recap` de `lib/useStepValidation.ts` renvoie `warnings: []` en dur. ⚠ Les lire
**hors** du `if (!valid)` : ils arrivent précisément avec `valid: true`.

~~**Un garde de forme sur le corps d'erreur**~~ — ✅ **parti avec la PR B**
(`isValidateResult`, `frontend/src/features/wizard/api.ts`) : `validateConstraints`
transtypait **tout** corps JSON en `ValidateResult`, si bien qu'un 403 (membre sans rôle
de gestion) arrivait avec `errors` à `undefined` et faisait **planter le récap** en écran
blanc. Plus rien à faire ici.

### NR attendus

Le calcul en isolation **ne suffit pas** : un test qui alimente lui-même la valeur qu'il
prétend vérifier ne garde rien. Il faut un test de **câblage** (le message ressort bien
de l'API) — et en `--group phase1`, sinon le gate bloquant ne l'exécute jamais.

---

## PR B — L'erreur bloquante sur impossibilité physique — ✅ LIVRÉ (#332, 2026-07-31)

> **Ce qui a été livré**, au-delà du cadrage ci-dessous : la règle vit dans
> `App\Service\CoachDoubleBookingDetector`, appelée par `ValidateConstraintsController`,
> qui expose une clé **`blockers`** (et non `errors`, indexé par `constraintId` — ici il
> n'y a pas de contrainte, ce sont des réservations). Le périmètre de réservations suit
> EXACTEMENT celui du builder : socle = `schedulePlanId IS NULL`, période = son plan,
> gymnases désactivés retirés. Mélanger les deux inventerait des conflits entre mondes
> disjoints. **Les trois règles tranchées** : intervalles réels `[début, début+durée[`,
> gymnases DIFFÉRENTS seulement (même gymnase = mutualisation voulue), coachs `MAIN`
> seulement. Le frontend a dû suivre (`useStepValidation` ne lisait que
> `valid`/`errors`/`conflicts` — une clé ignorée aurait bloqué côté serveur sans que
> l'écran dise rien), et le garde de forme réclamé plus bas est passé au même moment.
> NR : `Security/CoachDoubleBookingTest` (phase1, step CI ajouté dans le même commit).

### La décision fondateur

Un verrou peut créer une situation **physiquement impossible** : un coach dans deux
gymnases à la même heure. Ce n'est pas une préférence bafouée — le gestionnaire n'a pas
« choisi » que son coach se dédouble.

> « Dans le cas où cela arrive, il faut que l'on **empêche la GÉNÉRATION**. »

Message attendu, dans l'écran récap, avec le gabarit donné par le fondateur :

> **Maxime se trouve à deux créneaux en même temps** : SM1 à Armand de 17h et à Mateo
> le mardi à 17h. Veuillez corriger vos réservations.

### Pourquoi c'est calculable sans solveur

On a les verrous (équipe, gymnase, jour, heure) et les liens équipe↔coach. Croiser les
deux est une vérification de **données**. Le récap peut donc bloquer **avant** toute
génération.

### ⚠ Points de vigilance

- **Uniquement les coachs `MAIN`.** Le solveur ne traite qu'eux comme ressource
  exclusive : un `ASSISTANT` est optionnel et ne doit pas bloquer un placement
  (`add_coach_at_most_one`, `team_coach_map`). Bloquer sur un assistant refuserait des
  réservations légitimes.
- **Une erreur bloquante est un engagement fort.** Le jour où la détection se trompe, le
  gestionnaire est coincé sans recours. Le message doit être assez précis pour qu'il
  sache **quelle réservation retirer** — le gabarit ci-dessus l'est, le reprendre tel quel.
- Ne pas confondre avec les avertissements du volet 1 : ceux-là **n'invalident rien**
  (règle fondateur du #8), celui-ci **bloque**.

---

## PR C — La prévention au clic — ✅ LIVRÉ (#334, 2026-07-31)

> **Une découverte a changé le lot.** Le cadrage supposait qu'il suffisait d'interdire les
> créneaux incompatibles ; en lisant le code on a vu que **sélectionner une équipe créait la
> réservation IMMÉDIATEMENT** (`onChange` → `mutate`), sans bouton de validation. Rien ne
> pouvait donc s'interposer entre le choix et l'écriture — et une option désactivée dans un
> `<select>` n'étant pas cliquable, le message n'aurait jamais pu s'afficher. **Décision
> fondateur : la modale devient TRANSACTIONNELLE** — on compose ajouts et retraits, on
> valide ; les retraits suivent la même logique ; fermer sans valider abandonne.
>
> **La parité PHP/TS est tenue par des cas de test identiques** (`coachDoubleBooking.test.ts`
> ⇄ `CoachDoubleBookingTest`), la duplication étant inévitable : la modale doit répondre
> sans aller-retour réseau.
>
> ⚠️ **Ce que la bascule transactionnelle a coûté, à ne pas réapprendre** : la revue adverse
> a trouvé 10 défauts, dont 5 nés du choix lui-même — perte de données sur échec partiel
> (`submit()` sans try/catch), rejeu des opérations déjà passées (brouillon non purgé →
> verrous HARD dupliqués, `reservation` n'ayant aucune contrainte d'unicité), Échap actif
> pendant l'envoi. Plus un **fail-open** : `useWizardTeamCoaches` étant une `useQuery` nue,
> un repli `= []` pendant le chargement vidait la Map des coachs et éteignait la garde EN
> SILENCE. Leçon : rendre une écriture différée n'est pas un détail d'UX — c'est un
> changement de garanties, et il faut traiter l'échec partiel dès la conception.

### La décision fondateur

> « Niveau UX on peut guider le gestionnaire. On peut **INTERDIRE** les créneaux qui sont
> incompatibles […] le système met un message d'erreur : *Emerick coache déjà les U15F à
> 17h à Debarros*. Ça dissuade le gestionnaire qui comprend pourquoi. »

### Faisabilité — vérifiée

`SlotReservationModal.tsx` reçoit **déjà** toutes les réservations et toutes les équipes.
Il ne manque que le lien équipe→coach, et `listTeamCoaches()` existe dans
`features/wizard/api.ts` avec le rôle (`MAIN` | `ASSISTANT`). La détection est donc un
**calcul local** sur des données déjà chargées — aucun aller-retour serveur.

### ⚠ Même vigilance

N'interdire que sur les coachs **MAIN**, pour la même raison qu'en PR B.

### Pourquoi en dernier

Sans elle, la PR B rattrape déjà le cas au récap. La faire en dernier permet de
**réutiliser la règle de détection écrite en PR B** plutôt que d'en maintenir deux
versions — le motif de dérive qui a coûté trois rounds de revue au volet capacité.

---

## La leçon transverse, à ne pas réapprendre

Le volet 1 comme le volet capacité ont échoué en revue pour **la même raison** : une
règle recopiée à la main diverge de l'originale. Côté moteur, la détection réplique les
règles d'application ; côté backend, le calcul répliquait le payload.

**La parade appliquée** : extraire la règle en **une seule** fonction partagée
(`_is_enforced_window`, `_day_rules_union`) plutôt que d'en maintenir deux copies. Pour
les PR B et C, la même discipline vaut : **une seule** implémentation de « ce verrou
crée-t-il une impossibilité ? », consommée par le récap **et** par la modale.

Et pour les tests : vérifier qu'ils **exercent réellement** la ligne qu'ils prétendent
garder. Trois NR successifs ont été acceptés alors qu'ils étaient satisfaits par autre
chose que la règle visée — une branche antérieure, un libellé voisin, un simple décompte.
