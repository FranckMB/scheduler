# Bridage plan Découverte (freemium) — besoin spécifié

> **Statut** : **besoin spécifié** (discovery close, décisions tranchées §5) — **pas un plan**.
> **Nature** : fixe le modèle de bridage du plan gratuit (Découverte / freemium), business-critique — pas de SaaS sans verrou de conversion.
> **Rattachement roadmap** : `roadmap.md` §6 (pricing & bridage). Concrétise le bridage Découverte + l'enforcement `generation_count`.
> **Réutilise l'existant** : `Club.planId` · `billing_cycle`/`plan_expires_at` · `generation_count_season` · le **verrou read-only serveur** des 4 chemins d'édition (patron de la version choisie, ADR-0002). **Zéro changement engine.**

---

## 1. Le but

Laisser un gestionnaire **tester l'app avec ses vraies données** — assez pour **voir le gain de temps monstre** du solveur — puis le convertir **sans le frustrer**. Le freemium doit montrer la **magie** (un vrai planning de club résolu), pas une maquette bridée.

## 2. Le modèle : gate sur le NOMBRE de générations, rien d'autre

- **Club complet, aucun cap d'entité.** Le client saisit **tout son club** (20+ équipes, ses gyms, ses contraintes) → le solveur résout un **vrai problème** (conflits réels) → il voit le wow. *(Un cap d'entité rendrait le solveur trivial → il aurait l'air nul. Écarté.)*
- **Gate = `POST /generate` plafonné** (≈ **4 générations**, provisoire, à ajuster sur cas réels §7).
- **Générer décompte, ajuster est GRATUIT et illimité.** La work-loop sépare déjà **générer** (solve, cher) de **ajuster** (édition manuelle : drag-drop, locks → `ManualEditController`, pas de solve). Le gestionnaire génère une fois, puis **peaufine à la main** jusqu'à un bon plan **sans cramer son quota**. C'est ce qui rend un cap aussi bas que 4 **viable** (sinon la 1ʳᵉ génération ratée le brûlerait avant le wow).
- **Compteur total, non rechargeable** (pas « par saison » — sinon il régénère chaque saison et ne convertit jamais). Remis à zéro **uniquement par le superadmin** (cas particuliers).
- **Pas de limite de temps.** Le cap générations est le seul gate — pas de couperet calendaire (qui se battrait contre le rythme lent d'un club amateur bénévole).
- **PDF export off** en freemium (feature de conversion).
- **À l'épuisement des générations → read-only total** : consultation de ce qui a été fait, **aucune action** (ni générer, ni éditer, ni exporter). Données **préservées et visibles** → « passe à la formule pour continuer ». Verrou de conversion, pas lockout.
- **Default freemium** : tout le monde démarre en Découverte ; le **choix d'offre se fait à la conversion**, pas à l'inscription (un nouveau ne peut pas juger ses besoins avant d'avoir testé).

## 3. Pourquoi ce modèle (alternatives écartées)

| Piste | Écartée car |
|---|---|
| **Cap d'entités** (10 équipes, 4 gyms…) | Petit périmètre → solveur sans conflit à résoudre → **paraît nul**, tue le wow. Et 10 équipes cannibaliseraient les petits clubs. Marché cible ≈ 20 équipes → cap d'entité inutile. |
| **Bombe temps** (1 mois puis blocage) | Se bat contre le rythme **saisonnier lent** du club amateur ; force la décision avant le besoin → churn. Le cap générations la remplace. |
| **Cap par saison** | Recharge à chaque saison → ne convertit jamais. Total non rechargeable à la place. |
| **Lockout total à la conversion** | Il ne voit plus ses données → zéro désir de revenir. Read-only à la place. |

## 4. Enforcement — petit, pas transversal

Le modèle génération **dissout** le 🔴 « enforcement transversal sur chaque feature » : **3 gardes seulement**.
1. **Compteur générations** : garde dans `GenerateScheduleController` — refus si freemium ET quota atteint (le champ `generation_count_season` existe ; freemium a besoin d'un compteur **total non rechargeable** → nouveau champ ou variante qui ne se remet jamais à zéro).
2. **Export off** : garde sur l'export PDF si `plan = Découverte`.
3. **Read-only à l'épuisement** : réutilise le **verrou serveur** déjà en place sur les 4 chemins d'édition (celui qui protège la version choisie d'un plan, ADR-0002) → étendu au cas « freemium épuisé ».

Pas de garde par-entité, pas d'état « déjà au-dessus de la limite », pas de souci d'import/copie-de-transition. **Bien plus simple que le bridage entités de la roadmap d'origine.**

## 5. Décisions tranchées

1. **Gate = nombre de générations**, pas de cap d'entité, pas de limite de temps.
2. **Générer décompte** (`POST /generate`) ; **ajuster = gratuit** (édition manuelle).
3. **≈4 générations** (provisoire, ajusté sur cas réels).
4. **Compteur total non rechargeable**, reset superadmin only.
5. **PDF export off** en freemium.
6. **Read-only total** à l'épuisement (données gardées), pas lockout.
7. **Default freemium** ; choix d'offre à la conversion.
8. **Transition de saison off** en freemium (feature payante multi-saison ; un essai est mono-saison).

## 6. Dépendances & hors scope

- **Reset superadmin** → dépend de la **console superadmin** (roadmap §5/§9, 🔴 pas faite). En attendant : commande CLI de reset.
- **Anti-abus « 1 freemium / club »** : se contourne en créant plusieurs clubs (clubs = pas chers à créer). Anti-abus réel (par identité) = **hors scope v1**, à surveiller.
- **Guidage des contraintes** : besoin **cœur produit** (roadmap §4 diagnostic cliquable, §10 UX wizard), **pas** une feature freemium — mais le freemium en **augmente l'enjeu** (une génération gâchée coûte cher). Chantier séparé.
- **Club démo** (roadmap §3, « fort levier de vente ») : **complémentaire** — la démo (gros club fictif pré-généré) donne le wow immédiat ; le freemium fait essayer avec ses données. Deux leviers distincts.
- Les 3 autres plans (Petit/Club/Grand) + prix en DB : hors scope de ce doc (ici = seulement le bridage du gratuit).

## 7. Question ouverte

- **Le nombre exact de générations** (4 provisoire) — à **caler sur cas de test réels** (assez pour itérer vers un bon plan sans recharge). Décision produit, pas technique ; ne bloque pas le cadrage.

## 8. Axes structurants (§7.1) & vérification

- **generation pipeline** : le compteur gate `POST /generate` → NR (freemium épuisé → 402/403 refusé ; ajustement manuel non décompté ; superadmin reset ré-ouvre).
- **planning lifecycle** : read-only à l'épuisement réutilise le verrou des 4 chemins d'édition → NR (les 4 chemins refusés en freemium épuisé).
- **Vérification** : smoke-solveur inchangé (le freemium génère un vrai plan tant qu'il a du quota).
