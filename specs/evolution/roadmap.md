# Roadmap (41) — ce qui reste à faire

> **Ce fichier ne tient QUE l'ouvert.** Bugs, évolutions, dettes techniques : tout ce qu'on trace pour ne pas
> l'oublier un jour. Rien de livré n'y figure — un item livré **quitte** ce fichier et laisse sa trace dans
> [`../courantes/etat-des-lieux.md`](../courantes/etat-des-lieux.md), avec le comportement documenté dans la
> spec courante qui le reçoit.
>
> **Le (N) du titre = le nombre de lignes ouvertes du backlog** (entrées `| Pn-x |` / `SEC-n` / `DOC-n`),
> à titre indicatif (décision fondateur 2026-08-04 — exception assumée à la règle « pas de décompte volatil »).
> **Chaque MOVE l'entretient** : supprimer une ligne → décrémenter, en ajouter une → incrémenter.
> Vérification d'une commande quand un doute : `grep -cE '^\| (P[0-9]+|SEC|DOC)-' specs/evolution/roadmap.md`.
>
> ⚠ **Deux séries coexistent dans ce fichier, ne jamais les confondre.** Le **backlog** (`Pn-x`/`SEC-n`/`DOC-n`,
> compté par le `(N)` ci-dessus) et les **findings d'audit** (`AUD-*`, section dédiée en bas, compteur propre —
> `grep -cE '^\| AUD-' specs/evolution/roadmap.md`). Les deux numérotations ont grandi séparément : le `SEC-13`
> du backlog (rituel ZAP) n'a rien à voir avec le `SEC-13` d'audit (validation du `config`, livrée). Le préfixe
> `AUD-` existe pour rendre l'amalgame impossible.
>
> **Corollaire à ne pas contourner** : si vous cherchez « est-ce que X est fait ? », ce fichier ne répond pas —
> [`etat-des-lieux.md`](../courantes/etat-des-lieux.md) répond. Et si un sujet a été **tranché contre** une option
> évidente (« abandonné », « assumé »), c'est le §2 de l'état des lieux qui l'a, pas ici : ne le re-posez pas.
>
> **Index unique de l'ouvert.** Toute évolution, tout gap, toute idée future y laisse une ligne. Un fichier de
> détail à côté n'existe que pour un besoin trop gros pour une ligne, et doit être **référencé d'ici**.
>
> **Ids stables, jamais réutilisés** — un trou dans la numérotation = un item livré, jamais un oubli.
> **Impact** : 🔴 bloque la vente / l'intégrité · 🟠 fort levier · 🟡 valeur ciblée · ⚪ polish/dette.
> **Effort** : XS/S ≤ 1 PR · M 2-3 PR · L lot phasé · XL recherche + gros lot.
> Cap de commercialisation : **mi-2027**.
>
> **Fichiers de détail actifs** : [`gestion-matchs-ffbb.md`](gestion-matchs-ffbb.md) (paliers B/C du module matchs — le palier A est livré, P1-4 soldé) ·
> [`api-ffbb-app-reconnaissance.md`](api-ffbb-app-reconnaissance.md) (**ce que l'API FFBB rend vraiment** — mesuré, alimente P1-4 et P2-18)
> + son annexe [`api-ffbb-app-traces.md`](api-ffbb-app-traces.md) (**les sorties brutes**, club `ARA0069036`)
> + le besoin qui en découle [`ffbb-appariement-source-de-verite.md`](ffbb-appariement-source-de-verite.md) (**« on accompagne, on ne décide pas »** — P2-18, P1-4, P4-35) ·
> [`bridage-freemium-decouverte.md`](bridage-freemium-decouverte.md) (P1-3) ·
> [`etude-tailles-clubs-ffbb.md`](etude-tailles-clubs-ffbb.md) (**tailles des clubs mesurées sur l'API FFBB** — nourrit le point de cadrage P1-3 et la grille par taille) ·
> [`enregistrement-ffbb.md`](enregistrement-ffbb.md) (P3-4) · [`compte-demo.md`](compte-demo.md) (P2-4) ·
> [`console-superadmin.md`](console-superadmin.md) (P4-54) ·
> [`infrastructure-hebergement.md`](infrastructure-hebergement.md) (étude) ·
> [`reprise-perimetre-engage.md`](reprise-perimetre-engage.md) (mémoire produit du planning de saison) ·
> [`duplications-de-verite.md`](duplications-de-verite.md) (**inventaire du motif « une vérité, deux endroits »** —
> 44 cas datés du 2026-08-08, triés par « la divergence est-elle silencieuse ? », avec la doctrine de correction.
> **État : 39 livrés · 4 réfutés · 1 ouvert** — compte vérifiable par
> `grep -c '^| \*\*D-[0-9]*\*\* ⬜'` (un `grep -c '⬜'` nu compte aussi la légende et deux lignes
> de prose). Le dernier, **D-11, est dormant** : `match_day` est NULL sur les 69 équipes et
> aucun écran ne l'expose — à traiter le jour où le champ sera exposé, pas avant).
>
> **Réf historiques** : `FF#n` / `BG G#n` = identifiants des anciens `features-futures.md` / `backend-gaps.md`,
> absorbés le 2026-07-05. `v3 §x` / `contraintes-v2` = specs figées de `specs/initiales/`.

---

## Top 3 — plus forte plus-value (jugement IA, re-vérifié à chaque mise à jour)

> **Remplace l'« ordre d'attaque conseillé »** (décision fondateur 2026-08-09 : UNE seule liste de priorité,
> pas deux vérités). Règle d'entretien : **chaque PR qui touche ce fichier re-vérifie le top 3** — daté,
> chaque item justifié en une ligne. La demande terrain pèse lourd dans le jugement (règle fondateur
> 2026-07-31 : le terrain passe avant les chantiers auto-assignés), mais la liste est un jugement de
> plus-value, pas une file FIFO.

**Au 2026-08-09 :**
1. **P1-3 — bridage freemium** : aucune monétisation possible sans lui. **Cadrage tranché le 2026-08-09** (cap 12 équipes app, générations illimitées, Stripe différé — spec à jour) : la ligne est **prête à implémenter**, garde-fous wow-12 + question bêta en tête de chantier.
2. **P1-1 — rôles + gestion des membres** : débloque le self-service coach, P4-8, la salle de convivialité — structurant pour toute la surface multi-utilisateur.
3. **P2-8 — supprimer les miroirs front des règles serveur** : le motif a produit 40 défauts en 4 rounds sur la bascule ADR-0002, et chaque feature nouvelle en rajoute tant qu'il vit.

*(P2-17 — mutualisation lisible, issu du terrain — reste la première demande d'usage ouverte : il talonne ce top 3 et son volet « affichage fusionné » est le seul gratuit des trois.)*

---

## P1 — Enablers à fort levier

| # | Sujet | Impact | Effort | Débloque | Note |
|---|-------|:---:|:---:|---|---|
| P1-1 | **Rôles non-admin + modèle de permissions** | 🟠 | L | self-service coach · comptes coach · salle convivialité · P4-8 | `ClubUser.role` est hardcodé `admin` au register ; aucune différenciation de droits. `isManagementRole` = amorce, voters à câbler partout. Inclut l'écran **gestion des membres** (inviter / changer rôle / désactiver — le membership existe, pas l'UI) |
| P1-3 | **Bridage freemium Découverte** | 🟠 | M | monétisation | **Cadrage TRANCHÉ le 2026-08-09** (point dédié fondateur, nourri par l'[étude tailles de clubs](etude-tailles-clubs-ffbb.md) + passe `business-challenger`) → spec à jour : [`bridage-freemium-decouverte.md`](bridage-freemium-decouverte.md). **Gate = cap de 12 équipes app** (valeur de config), générations ILLIMITÉES (les quotas P4-45 restent l'anti-abus coût, hors business) ; PDF off ; transition de saison off ; petits clubs ≤ 12 gratuits à vie (assumé — WTP ≈ 0, carburant bouche-à-oreille) ; read-only réservé au payant expiré au-dessus du cap (verrou ADR-0002 réutilisé). L'ancien modèle « ~4 générations totales » (2026-08-04) est **renversé** — raisons dans la spec §3. **PSP = Stripe, intégration DIFFÉRÉE** : P1-3 se livre avec virement + marquage superadmin (`paidSeasonYear`, gate P1-5 livré) ; Polar écarté (cible 100 % FR + franchise TVA → MoR sans objet, double commission, facture US). ⚠ **Garde-fous** (spec §7) : test wow-12 ✅ **FAIT le 2026-08-09, concluant** (12 équipes tronquées de BCCL : infaisabilité réelle détectée en 3 ms, puis 17/17 séances, 0 violation, enchaînements coach — cap 12 confirmé) ; **reste la question aux bêta** (Rillieux en premier). Reste aussi : le **seed des 4 offres** `SubscriptionPlan` et la logique `billing_cycle`/`plan_expires_at` ⚑ **Grille tarifaire (fondateur 2026-08-04)** : abonnement **PAR SAISON**, prix fixe mai-septembre, dégressif ensuite ; **montants à valider par Van Westendorp sur les clubs bêta** (`business/pricing.md`) avant toute annonce. Axes NR : cap équipes (création + import), et le NR inversé « freemium ≤ 12 génère sans limite business » |

---

## P2 — Différenciateurs & correctifs terrain

| # | Sujet | Impact | Effort | Note |
|---|-------|:---:|:---:|---|
| P2-17 | **Mutualisation lisible : « SM1/SM2 » sur un créneau partagé** | 🟡 | M | Besoin fondateur 2026-07-31, sur les périodes de vacances. La **mécanique** est livrée (réservation de 2 équipes sur un créneau à capacité 2, zéro engine — état des lieux §1.2), mais rien ne la **donne à lire** : le gestionnaire veut voir **« SM1/SM2 »** comme une seule ligne de planning, avec **le coach des SM1** considéré comme le coach de la séance. Et il veut pouvoir **proposer la mutualisation depuis les doléances** (« ces deux équipes peuvent s'entraîner ensemble cette semaine-là »). Trois volets distincts : affichage fusionné (grille + exports), règle de coach porteur (⚠ elle a un effet **solveur** : aujourd'hui les deux coachs sont contraints, pas un seul — l'affirmer sans le câbler serait un placebo, axe *constraint semantics* → NR), et le geste dans la modale doléances. **Cadrer les trois séparément** : seul le premier est gratuit |
| P2-6 | **Pattern « Plan » — lot C** (réglages & génération par plan) | 🟠 | M | Dernier lot de la reconstruction ADR-0002. Lots A et B livrés (état des lieux §1.3) → [ADR-0002](../../docs/architecture/adr-0002-pattern-plan.md) |
| P2-2 | **Boucle d'ajustement — « corriger sur place »** | 🟠 | M | « Naviguer » est livré (#180) : les diagnostics surlignent les créneaux vides du gymnase concerné et la vue bascule dessus. **Reste** : glisser une équipe dans un créneau vide pour réparer. **⭐ Même primitive que la grille de réservation pré-génération** (grille + créneaux vides + clic = affecter) : une seule brique, deux moments (avant génération = LOCK HARD, après = réparer). À spécifier ensemble le jour venu. Jumeau : rendre les **diagnostics actionnables** (actions + liens entités dans le jsonb) plutôt que du texte |
| P2-8 | **Le front re-dérive des règles de refus du serveur** | 🟠 | M | `PlanningToolbar` recalcule côté client `isLastFinishedSeasonVersion`, `hasInFlightSibling` et le décompte des sœurs supprimées à la validation. **Chaque miroir est un risque de dérive** — c'est ce motif exact qui a produit 40 défauts en 4 rounds sur la bascule ADR-0002. Les supprimer suppose que le serveur expose ce qui est **permis** (capacités par ressource, patron de `Schedule.isChosen` / `Team.isEngaged`) : chantier de conception, pas correctif |
| P2-3 | **Versions — « Travailler sur cette version » + savepoint auto (D4)** | 🟡 | M | Moitié manquante de la décision 5 ; D1→D3quater livrés (état des lieux) |

---

## P3 — Complétude modules

| # | Sujet | Impact | Effort | Note |
|---|-------|:---:|:---:|---|
| P3-2 | **Overlays — période `custom` générante** | 🟡 | M | `periodType=custom` n'est pas générant : créer un overlay dessus rend 422 = impasse UX. Mitigation livrée (bouton désactivé + tooltip, gardé par test). Reste à activer quand `custom` devient générant |
| P3-3 | **Modèle « templates → occurrences »** (v3 §3.5, §8.1 · FF#8) | 🟡 | L | Débloque la cascade calendrier de saison → plans secondaires, et le grain fin (exceptions, annulations, déplacements, remplacements coach/salle). ⚠ **La matérialisation glissante J+14 de la vision d'origine est abandonnée** au profit d'une projection + occurrences éparses (deltas) : `schedule_slot_occurrences` ne stocke, si elle existe, **que les overrides**. Le grain fin est déjà partiellement couvert par `ManualEditController` (`/manual-edit/one-time` + `temporaryLock`) — n'ajouter la table **que si** un besoin réel le justifie |
| P3-16 | **Suppression sûre (salle / équipe / coach)** | 🟡 | M | Ex-ligne §9. Entités **saison-scoped**, partagées par tous les plannings — il n'y a pas de « suppression pour un seul plan ». Décisions actées : **hard delete**, précédé d'une popup listant l'impact en cascade (contraintes liées, liens coach, **plannings secondaires supprimés**). Recoupe DOC-2 (les matchs déjà déclarés perdent leur salle) — les traiter ensemble. Axe *planning lifecycle* → NR |
| P3-17 | **`COACH_FORBIDDEN_TIME_RANGE`** (plage horaire interdite, au-delà du jour entier) | 🟡 | M | `COACH_AVAILABILITY` ne gère aujourd'hui que les **jours** (`unavailableDays`) — l'engine sait déjà lire une fenêtre horaire (`_coach_window_minutes`), c'est la **config + l'UI** qui manquent. Même grille que le questionnaire coach si celui-ci retient jours × plages |
| P3-5 | **Versions — diff / comparaison** (`ScheduleDiffService` — v3 §6.2 · FF#11) | 🟡 | L | Hors périmètre D assumé ; les snapshots existent déjà (`snapshot_data`) |
| P3-7 | **Import d'équipes Excel — UI wizard** | ⚪ | S | Le backend existe (`POST /clubs/{id}/import-teams`) ; l'écran manque, et l'étape Équipes se tape aujourd'hui **une ligne à la fois** (49 équipes chez BCCL). ⚠ **Lire P4-35 AVANT de câbler l'écran** : le volet technique est réparé (2026-08-04 — identité par nom, tout-ou-rien) mais le design décidé reste une **correspondance explicite** faite par le gestionnaire, persistée pour le ré-import — ça change la maquette, pas seulement le backend. ⚑ **GARDÉ malgré P2-21** (décision fondateur 2026-08-04) : la date de souscription est asynchrone des poules — l'API peut rendre beaucoup ou AUCUNE équipe selon le moment ; l'Excel pallie. « L'API est une aide, pas la source de vérité » |
| P3-19 | **Miroir d'algèbre du récap — la sortie définitive reste un calcul côté MOTEUR** | ⚪ | M | **Le DANGER de la ligne est neutralisé (2026-08-07)** : l'algèbre est extraite en `PayloadCapacityMirror` (source unique des trois lectures : offre, saturation, orphelines) et **gardée par `CapacityMirrorParityTest`** (cross-stack, groupe `contract`, moteur réel — tourne en CI dans `unit-tests`/`blocking-tests` qui démarrent l'engine) : falsifié DANS LES DEUX SENS — dédup retirée côté PHP → rouge ; rabot `FACILITY_CAPACITY` désactivé côté moteur → rouge. Une dérive d'algèbre ne peut plus être silencieuse. **Reste** (dégradé de 🟠 à ⚪) : déplacer le calcul côté moteur (endpoint capacité pré-solve) pour supprimer la double maintenance — à cadrer avec la question fail-open/fail-closed des bloqueurs si le moteur ne répond pas |
| DOC-2 | **Un match déposé à la fédération peut perdre sa salle sans avertissement** | 🟠 | S | **Arbitré 2026-07-31 : avertir avant, laisser passer.** Le périmètre engagé protège les ÉQUIPES ; rien ne protège la **salle d'un match**. Supprimer un gymnase (`EntityCascadeDeleter::purgeChildrenOfVenue:104`) ou charger une version antérieure (`StructureRestorer:173`) **dépointe** `Fixture.venueId` de n'importe quel match, y compris `SUBMITTED`/`VALIDATED` — déjà déclaré à la ligue à CETTE salle. Le match redevient visiblement « à placer », donc récupérable — c'est pourquoi l'un vaut un NULL et l'autre un 409. **Ne PAS refuser le geste** (un gymnase qui ferme, ça arrive). À livrer : la confirmation annonce « N match(s) déjà déclaré(s) perdront leur salle et devront être re-soumis à la fédération ». À traiter avec P3-16. Axe *périmètre engagé* → NR |

---

## P4 — Dette & polish (avant GA, par lots opportunistes)

### Retour terrain du 2026-07-31 (wizard, overlay, général)

| # | Sujet | Impact | Effort | Note |
|---|-------|:---:|:---:|---|
| P4-62 | **L'export ignore le filtre de ressources de l'écran** | ⚪ | S | **Constaté au moment de P4-43 (2026-08-02), non couplé délibérément.** `ResourceFilter` filtre la grille **côté client** ; `ExportMenu` (`frontend/src/features/planning/ExportMenu.tsx:16`) porte son **propre** périmètre gymnase (`scope`, `""` = tous) et le PDF est rendu **serveur**, qui ne connaît aucun filtre client. Les deux contrôles vivent désormais sur le même écran et se ressemblent : un gestionnaire qui filtre sur deux gymnases puis exporte obtient tout. ⚠ **Ce n'est pas un bug à corriger mécaniquement** — coupler les deux est un CHOIX produit (le filtre est-il une loupe de lecture, ou la définition de ce qu'on emporte ?). Trancher avant de coder ; à défaut, au minimum l'annoncer à l'export. Croise la règle §7.2 pt 2 : « jamais masquer ce qu'un export contient » |

### Dette technique et polish antérieurs

| # | Sujet | Impact | Effort | Note |
|---|-------|:---:|:---:|---|
| P4-35 | **Import Excel d'équipes — la correspondance EXPLICITE persistée** | ⚪ | M | ⚑ **Volet technique LIVRÉ le 2026-08-04** (identité par nom, import tout-ou-rien, sortOrder des catégories créées — voir état des lieux) ; **reste le design décidé** : le gestionnaire APPARIE (jamais une clé naturelle devinée), la correspondance est **persistée** pour le ré-import — c'est l'écran P3-7 qui la porte, à livrer avec lui |
| P4-73 | **Verrou optimiste — l'adoption côté écrans** | ⚪ | M | Le mécanisme est livré côté serveur (P4-25, 2026-08-09) : un `If-Match: <version>` sur PUT/PATCH rend un **409** au lieu d'écraser. ⚠ **Aucun écran ne l'envoie encore**, donc la protection est disponible et INACTIVE — dit franchement plutôt que compté comme corrigé. Adoption écran par écran : le type doit porter `version` (déjà exposé par les ressources), la mutation doit la transmettre, et le 409 doit se traduire en invitation à recharger. Commencer par **`CoachWish`**, le cas où le défaut a été constaté. Les PUT sont dispersés par feature (`api.put(...)`), il n'y a pas de point unique à câbler |
| P4-26 | **Deux coachs d'une même équipe partagent la doléance de la semaine** | ⚪ | M | `CoachWish` est clé sur `(calendarEntryId, teamId, weekStart)` **sans dimension coach** : une équipe à MAIN + ASSISTANT n'a qu'**une** doléance par semaine, et le second à saisir écrase le premier. **Atténué** (le pré-remplissage montre la valeur courante, et « un souhait, jamais une contrainte »). Décision fondateur explicite (« un souhait par équipe × semaine ») → laissé tel quel en V0. Même famille que P4-25. **Relue le 2026-08-01 au moment de P3-14 : inchangée** — borner le coach aux MAIN de l'équipe ne change pas la clé de la doléance, et le fondateur maintient « un souhait par équipe × semaine » |
| P4-8 | **`resolveClubId` choisit arbitrairement pour un gestionnaire multi-club** | ⚪ | M | Le front n'envoie pas `X-Club-Id`, donc `TenantFilterListener::resolveClubId` retombe sur `findOneBy(['userId','isActive'])` **sans ORDER BY** : pour un humain gérant deux clubs, « quel club est courant ? » n'a pas de réponse définie. **Fix = choix produit** (sélecteur de club explicite, ou club par défaut sur l'adhésion) → rattaché à **P1-1** |
| P4-27 | **Presenter de campagne exécuté deux fois par écriture** | ⚪ | S/M | `CoachWishCampaignStateProcessor` : `parent::process*` projette, le résultat est jeté, puis le presenter re-tourne après `tokenSync->sync()` (indispensable : les tokens n'existent pas au 1er passage). Le déplacer dans `afterPersist` a été **essayé et abandonné** — ce hook est réservé aux effets de bord facultatifs et post-commit, or créer les tokens est obligatoire. Le vrai fix demande une porte « écrire sans projeter » au processor de base : modification du socle partagé, pas un quick win |
| P4-28 | **`GET /api/admin/health` : sondes séquentielles + scan Redis intégral** | ⚪ | M | `AdminHealthService` lance ~6 sondes HTTP/TCP + 3 dépendances externes **une à une** (une dépendance lente stalle toute la réponse, poll 30 s) ; `AdminMessengerFailedController::fromRedisStream` **bufferise tout le stream** pour paginer. Fix : sondes concurrentes (HttpClient multiplexé), pagination Redis native |
| P4-10 | **Désactiver « Régénérer » si rien n'a changé** | ⚪ | M | Demande une détection de changement fiable |
| P4-12 | **`*PeriodOverride` — parité miroir à durcir (les 2 jumeaux)** | ⚪ | S | (a) `createEntityFromInput` = check-then-insert non atomique → POST concurrents → 500 au lieu de 422 ; (b) `#[ApiFilter(SearchFilter)]` inerte (le provider custom lit les params à la main — ne sert que le snapshot OpenAPI) ; (c) le provider ré-implémente `provideCollection` au lieu du hook `applyRequestFilters` ; (e) la règle de défaut reprise (CLUB/COACH/TEAM gardées, FACILITY droppée) est **dupliquée** PHP `activePermanentForReprise` + TS `defaultKept` sans source partagée — une édition unilatérale ferait diverger checklist et payload. Toucher `TeamPeriodOverride` **et** `ConstraintPeriodOverride` ensemble |
| P4-18 | **DA « ça sent le basket »** | ⚪ | S/M | L'app est visuellement neutre alors qu'elle s'adresse à des clubs de basket : fond de bandeau évoquant un parquet, illustrations d'états vides, iconographie. **Justification = mono-sport assumé, PAS préparation multi-sport.** Argument de démo et de vente. **Garde-fou de coût** : regrouper les assets en **un seul thème** — pour qu'un changement de parti pris reste une PR et non une chasse au trésor. Sobriété : un fond + les états vides suffisent |
| DOC-3 | **`TeamTagScopeTest` se déclare « NR BLOQUANT » sans être un step du gate — décision à prendre** | ⚪ | XS | Découvert à l'audit du 2026-08-08 (DOC-26). Le test garde un axe structurant (P4-42, **portée des tranches d'âge** : un `targetTag` s'éclate en N contraintes par équipe dans le payload, donc élargir un tag impose en silence une règle à des équipes jamais visées) et son propre docblock s'intitule « NR BLOQUANT » — mais **aucun step du job `blocking-tests` ne le lance**. ⚠ **Mesurer l'écart avant de le corriger** : il tourne dans `unit-tests`, qui **fait partie des contextes requis de `main`** — donc il **bloque bien le merge**. Ce qu'il ne fait pas : bloquer `build-docker` (`needs: [blocking-tests, engine-tests]` — l'image se construit sur une base rouge, même si elle ne se merge pas), et rendre son verdict tôt. C'est un écart de **rigueur et de délai**, pas un trou de sécurité — d'où l'impact ⚪. Choix : (a) l'ajouter comme step, précédent établi par `ScheduleConstraintBuilderOverlayTest` (P2-9ter, dont le commentaire CI documente le piège) ; ou (b) assumer, et retirer « BLOQUANT » du docblock pour que le discours colle. ⚑ **Le motif, lui, est FERMÉ depuis le 2026-08-08** : `BlockingTestsListMatchesCiTest` compare la liste §4 aux steps réels dans les deux sens, donc l'écart ne peut plus se rouvrir en silence — annoncer ce test comme bloquant sans ajouter son step fait désormais rougir la CI, et l'inverse aussi. Ce qui reste ici est **le choix**, plus le risque de l'oublier |
| SEC-13 | **Rituel pré-prod ZAP + Nuclei** | 🟡 | S | Dès qu'une préprod/prod existe : ZAP **baseline** avant chaque release + scan **actif** une fois avant la vraie mise en prod (préprod uniquement, compte de test) ; Nuclei **mensuel sur l'hôte exposé** (configs nginx/TLS, empreintes CVE). Procédure prête → [`docs/security/scanners.md`](../../docs/security/scanners.md) §Rituel — il ne reste qu'à la dérouler le jour venu. Né du cadrage outillage SEC A19 (2026-08-05) |
| P4-47 | **Doc OpenAPI — les 15 routes restantes** | ⚪ | S | 18 routes documentées le 2026-08-09 (valider/rouvrir/régénérer un planning, réordonner les équipes, adhésions, logo, mot de passe…). **Restent 15**, déclarées comme dette dans `EveryCustomRouteIsDocumentedTest::KNOWN_UNDOCUMENTED` — un **cliquet** : la liste ne peut que décroître, et une route ajoutée sans entrée factory fait rougir. 10 sont sur la console superadmin (contrat propre dans `superadmin-auth.md`, non consommée par le frontend club → valeur moindre, pas nulle) ; 5 sont des pages publiques à token et des utilitaires FFBB |
| P4-50 | **`team_tags` — deux dettes qui se cumulent** | ⚪ | S | (a) `TeamTagService::syncTeamTags` **efface tous** les tags (custom inclus) et ne re-dérive que les tags **système** à chaque édition d'équipe → les tags **custom** sont **éphémères** et ne survivent pas à une édition. C'est ce qui bloque leur report entre saisons (dernier reliquat de la transition P2). (b) Depuis P2-9ter, les tags ne sont plus resynchronisés à la génération, seulement au write-path par `TeamTagSyncListener` — **qui n'écoute que `Team`**. Éditer une **`SportCategory`** laisse donc les tags des équipes **périmés**. Sans effet en V0 (les fixtures repartent de zéro) ; le correctif est d'étendre le listener. ⚑ **Cette ligne se disait « prérequis de P4-42 » — elle ne l'était pas** : P4-42 a été livré le 2026-08-02 sans rattrapage, sur décision fondateur (« les clubs neufs sont corrects »), et la création des tags système étant idempotente, aucune migration n'a été nécessaire. **Mais la dette est désormais VISIBLE** : un club saisi avant ce jour garde des équipes U5/U7 taguées EMB jusqu'à leur prochaine édition, sans qu'aucun écran ne le dise. C'est ce que (b) coûte, et ça se reproduira au prochain changement de catalogue de tags |
| P4-54 | **Console super-admin — SA4 v2 puis SA5** | ⚪ | M | SA0→SA4 v1 livrés + monitoring + alerting (état des lieux §1.8). Suite au signal → [`console-superadmin.md`](console-superadmin.md). ⚠ Suspension de club et approbation fallback **délibérément différées** au premier cas réel |
| P4-55 | **Contraintes implicites du solveur — visibilité en lecture seule** | 🟡 | M | Besoin fondateur 2026-07-20. Le solveur applique des règles structurelles **jamais saisies** (`add_room_at_most_one`, `add_coach_at_most_one`, `add_coach_player_non_overlap`, `add_coach_rest_day_constraints`, `add_one_session_per_day_constraints`…) en plus des règles implicites documentées. Aucune n'est visible côté UI — le gestionnaire ne sait pas ce qui s'applique « gratuitement ». Demande : un panneau **lecture seule**, non éditable. **Non cadré** : où l'afficher, et surtout **quelles règles méritent d'être montrées** — certaines sont des détails d'implémentation sans valeur pour un non-technicien (`add_age_ascending_constraints`), d'autres directement utiles (non-chevauchement coach, repos après match). Trier avant tout cadrage. Jumeau de P2-16 |
| P4-57 | **Validation temps réel — compléter la liste de warnings** | ⚪ | S | Le wizard valide par étape ; les warnings de réservation (créneau surchargé vs capacité, quota séances/semaine, 2 séances le même jour) et le warning « équipe compétitive classée D » sont livrés, non bloquants — le solveur reste l'autorité. La liste se complète au fil de l'eau. Recoupe le rail d'affichage de P2-9 PR A |
| P2-22 | **Un créneau qui disparaît pour cause de gymnase fermé ne le DIT pas** | 🟠 | M | **Besoin fondateur 2026-08-08 : « au lieu de cacher les créneaux, afficher dans la grille GYMNASE INDISPO du 1/5 au 10/5 ».** Une fermeture datée ne produit aucune contrainte moteur : le builder RETIRE les créneaux du gymnase les jours fermés (`ScheduleConstraintBuilder.php:318`, dérivés par `VenueClosureDays` = incident ∩ fenêtre du plan). Efficace, mais **muet** : le gestionnaire qui n'a pas saisi l'incident voit des créneaux manquants sans savoir pourquoi — « seul je le sais, à 4 on ne comprend pas ». ⚑ **Partiellement traité, et c'est le piège** : l'écran Structure annonce déjà « INTERDIT cette période » (`PeriodStructure.tsx:425` et `:441`, exigé par la revue #8) — mais (a) **sans le motif ni les dates**, alors qu'elles existent (`VenueClosureDays::closedDatesByVenue`, que le radar consomme déjà) ; (b) en **tout-ou-rien sur le gymnase**, alors que la fermeture porte sur des JOURS précis — un gymnase fermé 3 jours sur 7 reste utilisable les 4 autres, et ses créneaux du vendredi disparaissent sans un mot ; (c) **`ReservationGrid` ne dit rien du tout** — elle boucle sur les créneaux, donc un créneau retiré n'a aucune case où s'afficher (même impasse structurelle que les réservations orphelines de P4-44). **À trancher avant de coder** : bande de remplacement à la place du créneau, ou créneau barré + libellé ? Trois écrans concernés (structure de période, réserver, récap). Aucune API à inventer, la donnée est côté serveur |
| P4-69 | **Forfait général : un 3ᵉ état du périmètre engagé** | ⚪ | M | **Ouvert §8.1 de [`ffbb-appariement-source-de-verite.md`](ffbb-appariement-source-de-verite.md) — « réel, pas prioritaire » (fondateur).** Une équipe en forfait général a des matchs (donc `EngagedTeamGuard` la verrouille) mais n'a potentiellement plus besoin de ses créneaux, réallouables. Forfait ≠ désengagement dans notre modèle : il faudra un 3ᵉ état. Axe périmètre engagé (§7.1) → NR obligatoire le jour où c'est traité |
| P4-59 | **Dialogue post-modification — l'endpoint existe, l'UI ne l'appelle jamais** (v3 §11.4 · FF#4) | 🟡 | M | `ManualEditService::applyPermanentConstraint` existe et `POST /api/schedule-slots/{id}/manual-edit/constraint` répond « Permanent constraint created. » — mais **aucun appelant frontend** (`features/planning/api.ts` n'appelle que `/manual-edit/lock` et `/one-time`) : l'endpoint est **mort côté produit**. Reste le **dialogue** lui-même (« convertir ce déplacement en contrainte permanente ? ») et le `source_occurrence_id` (qui, lui, dépend de P3-3) |

---

## P5 — Avant PROD : la checklist de mise en production

> **Pourquoi cette section (fondateur, 2026-08-09)** : les gestes « à faire le jour où on ouvre » s'accumulaient
> en notes éparses — cette liste les tient au même endroit pour y voir clair le jour venu. Elle mélange des
> **gestes d'exploitation** (pas de code) et des **lignes de code** à livrer avant l'ouverture publique.
> S'y ajoutent deux rituels déjà tracés ailleurs : **SEC-13** (ZAP baseline + Nuclei, ligne P4 ci-dessus)
> et le **dump pré-migration** ([`deploy.md`](../../docs/ops/deploy.md)).

| # | Sujet | Impact | Effort | Note |
|---|-------|:---:|:---:|---|
| P5-1 | **Activer Sentry — le compte n'existe pas** | 🟠 | XS | Geste ops, zéro code : créer l'org Sentry, poser les 3 DSN (backend/engine/frontend — câblage livré P0-4, DSN-vide-inactif) **et l'hôte d'ingestion dans `docker/frontend/csp.conf`** — le garde P4-65 (`sentryCspGuard.ts`) fait échouer le build tant que l'hôte manque, précisément pour que ce geste ne s'oublie pas à moitié |
| P5-2 | **Hook off-site des backups (résidu INF-02)** | 🟠 | XS | Action d'exploitation, pas de code : brancher la copie hors-site des dumps `pg_dump` le jour du déploiement → [`backup-restore.md`](../../docs/ops/backup-restore.md) |
| P5-3 | **Anti-abus à l'ouverture publique : Turnstile + anti-énumération** | 🟡 | S/M | Le socle existe (rate limiting multi-axes SEC-11, quotas de solve par club P4-45, email verification) — restent les deux trous qui n'ont de sens qu'exposé au public : **Cloudflare Turnstile sur register** (adaptatif de préférence), et **audit anti-énumération** sur register/reset-password (réponses et délais identiques compte existant/inexistant — jamais vérifié). Axe auth §7.1 → NR |
| P5-4 | **Mesure de charge multi-club — jamais faite** | 🟡 | M | La stack tient un club (BCCL, 49 équipes) ; personne n'a mesuré N clubs simultanés (générations concurrentes × workers, RAM OR-Tools, Redis, Postgres). Pas de refonte préventive : **une mesure**, qui dit si l'archi actuelle tient l'objectif 5 clubs bêta et où elle casse. Recoupe [`infrastructure-hebergement.md`](infrastructure-hebergement.md) |
| P5-5 | **Page de vente publique (+ FAQ statique)** | 🟠 | M | Rien n'existe. Landing produit hors app : ce que fait l'outil, pour qui, captures, appel à contact — prérequis de toute prospection (un mail sans page où atterrir ne convertit pas). La FAQ statique y vit. À cadrer : hébergement (statique séparé vs route publique du front) et contenu. Le compte démo vendeur (livré) et le mode démo self-service (parking) s'y raccrocheront |
| P5-6 | **Canal signalement + support/repro — à spécifier** | 🟡 | M | Besoin fondateur 2026-08-09 : un endroit où un gestionnaire signale un bug, une contrainte manquante, une idée — et de quoi **reproduire** ce qu'un utilisateur a rencontré. Base saine voulue d'emblée (pas un `mailto:` jetable), sans sur-ingénierie tickets. **Cadrage dédié à faire** : rien n'est tranché (in-app vs externe, lien avec Sentry, quelles données de contexte joindre) |

---

## Findings d'audit ouverts (registre `/audit`) — 1

> **À quoi sert cette section.** Le skill `/audit` tient un **registre à IDs stables** : un finding garde son
> identifiant d'une édition à l'autre, ce qui rend la comparaison inter-éditions possible (« ce défaut est-il
> corrigé, ouvert, ou aggravé depuis trois mois ? »). Les éditions vivent dans [`../audit/`](../audit/) ;
> **cette section est le miroir ACTIONNABLE de leur reste-à-faire** — l'audit constate, la roadmap engage.
>
> ⚠ **Espace de noms distinct du backlog.** Un `SEC-13` d'audit n'est PAS le `SEC-13` du backlog ci-dessus
> (rituel ZAP) — les deux séries ont grandi séparément. D'où le préfixe **`AUD-`** ici : il rend la collision
> impossible à commettre. Le compteur du titre de ce fichier ne les compte pas (son `grep` cible `Pn`/`SEC-n`/`DOC-n`) ;
> **cette section porte son propre compteur**, à entretenir à la même règle : un finding corrigé quitte la
> section et laisse sa trace dans [`../courantes/etat-des-lieux.md`](../courantes/etat-des-lieux.md).
>
> **Gravité = celle du registre d'audit** (barème figé du skill), pas l'impact business du backlog — un
> finding « Moyenne » peut être ⚪ pour le produit. « Depuis » = première édition où le finding apparaît :
> c'est la colonne qui rend la **récidive** visible.

| ID | Sujet | Gravité | Zone | Depuis | Note |
|---|-------|:---:|:---:|:---:|---|
| AUD-FRT-20 | Tests d'écrans qui mockent les hooks porteurs | Faible | frontend | 2026-08-08 | 17× `auth/queries`, 15× `./queries`, 9× cockpit — contre 15× `./api`. Le patron prescrit par §7.2 pt 5 (mocker le module API **voisin**, monter le vrai hook sur un `QueryClient`) coexiste avec du hook-mocking qui ne garde que le câblage. Pas rouge (967 verts) : le filet est simplement inégal selon les features |

> **Non repris ici, volontairement** : `UXC-12` (console superadmin hors design system — persona fondateur, pas
> gestionnaire) et `UXC-10` résidu (empty states inline — ~14 sites, cosmétique). Le résidu `INF-02` (hook
> off-site des backups) a rejoint la checklist Avant PROD (**P5-2**) le 2026-08-09.
> Ils restent lisibles dans l'édition d'audit, qui est la mémoire longue.

---

## Vision — non priorisé (V2 et au-delà)

> Gardé pour ne pas ré-inventer, mais hors cap mi-2027. Une ligne remonte au backlog quand un club le demande.

| Sujet | Effort | Note |
|---|:---:|---|
| **Matrice de temps de trajet + passerelles d'équipes** (U15→U18 — v3 §3.4, §4.1 · FF#5) | 🔴 | Tables absentes, contraintes engine en stub (`travel_feasibility`, `required_bridge`). **Comment obtenir la matrice** : le gymnase porte déjà une adresse → géocodage → distances. Deux paliers : (a) **vol d'oiseau** (haversine, zéro dépendance, suffit pour « ces deux gymnases sont-ils enchaînables ? ») puis (b) temps de trajet réel (API d'itinéraires, dépendance + quota). **(a) d'abord.** Géocodage : l'**API Adresse (BAN)** est gratuite, publique et 🇫🇷 (cohérent RGPD), à confiner comme `FfbbApiClient`. Stocker lat/long **sur le gymnase** (géocoder à la saisie, pas au solve) : le payload reste des nombres et l'engine ne parle à personne |
| **Reverse-engineering des contraintes** (dériver des PREFERRED du planning existant) | XL | Fort attrait, aucun cadrage. **Décisions déjà actées** : suggestions **PREFERRED uniquement** (des HARD dérivées figeraient le plan et neutraliseraient le solveur), **agrégation obligatoire** (« 4/4 séances mardi → 1 PREFERRED mardi » + score de confiance), analyse = **service backend pur, engine intouché**. Réutilise le rail `pendingConstraintSuggestion` déjà câblé |
| **Régénération partielle guidée** (`PartialRegenService` — v3 §6.2, §14.2 · FF#1) | 🔴 | Partiellement couvert par les overlays (une période génère un plan borné sans toucher au socle) ; reste la regen **ciblée du plan de base** hors période. À requalifier quand un besoin réel se présente |
| **Déterminisme exact du plan sur gros clubs** | 🟡 | Les 8 workers rendent l'assignation non déterministe (la **valeur** d'objectif reste stable). `interleave_search` seul ne suffit pas → refonte du budget timeout. À ne faire **que si** un club demande la repro exacte — aujourd'hui jugé inutile (le gestionnaire ajuste). Alternative : un mode « repro exacte » optionnel (1 worker + budget élargi) |
| **Connecteurs API municipales** (`venues.source`, `external_ref` — v3 §1.4 · FF#20) | 🔴 | V2 |
| **App mobile** (React Native/Expo — v3 §1.4 · FF#14) | 🔴 | **À ne pas faire avant d'avoir des clients payants** — web responsive + PDF suffisent |
| **Notifications coach** (push + lien de consultation sans login — v3 §1.4 · FF#17) | 🟡 | Le rail tokenisé sans login existe déjà pour les doléances |
| **Stats & analytics club** (taux de remplissage, heures-coach/semaine — v3 §1.4 · FF#16) | 🟡 | Peu coûteux une fois les occurrences là (P3-3) ; demande d'AG |
| **Dashboard multi-clubs** (au-delà de la console superadmin — v3 §14.3 · FF#15) | 🔴 | V2 |
| **Multi-sport** (handball, gym, volley — v3 §1.4 · FF#18) | 🔴 | **Attendre une vraie demande.** Conséquence assumée : P4-18 (DA basket) part du mono-sport et ne le prépare pas |

---

## Parking — idées gardées, non cadrées

- **Plannings de FIN DE SAISON — un 4ᵉ type de plan ?** (besoin fondateur 2026-08-07, à mûrir). En fin de saison trois choses se cumulent : les **détections** (internes, parfois sur les créneaux d'entraînement), les **playoffs** — où l'on se concentre sur l'effectif encore en compétition —, et surtout le **changement d'équipe** : les U15 deuxième année s'entraînent déjà avec leur future U18M1 ou M2. Le planning devient très spécial : même structure, mais certains créneaux disparaissent et les équipes ne contiennent plus les mêmes joueurs. Piste : un plan qui se dérive de qui CHANGE d'équipe et de qui est ENCORE en compétition, en gardant la structure ou en retirant des créneaux — ce serait un **4ᵉ type de plan** à côté de SEASON/CLOSURE/HOLIDAY. **À spécifier en profondeur avant tout cadrage** : ni le modèle de données (appartenance d'un joueur à deux équipes ?) ni le geste UI ne sont tranchés.
- **Mode démo self-service** (ex-volet C de P2-4, jamais demandé par le terrain) : bouton public « Essayer avec des données d'exemple », sandbox jetable par visiteur (TTL/purge), à cadrer avec le bridage P1-3. Le volet VENDEUR est livré et couvre l'usage réel (état des lieux 2026-08-07). Rouvrir si la landing/le volume de prospects le justifie.
- **Réservation de salle de convivialité** (self-service coach : réserver une soirée dans une salle non sportive → notif gestionnaire). La résa est **triviale** (salle = `Venue`, pas de solveur, juste un check de conflit). ⚠ **Le vrai coût n'est pas la résa** : « le coach réserve lui-même » exige des **comptes coach + un modèle de rôles** (P1-1). **Question stratégique** : veut-on que l'appli devienne le *hub du club* (self-service coach) ou reste l'*outil de planning* (piloté gestionnaire) ? Déclencheur de réouverture : P1-1 livré, ou demande d'un club pilote — la feature devient alors quasi gratuite.
- **Reset du club : la route s'appelle encore `DELETE /api/reset-season`** alors que le geste rendu est « reset club ». Reliquat cosmétique, sans conséquence fonctionnelle.

---

## Dette — keeps délibérés

> Règle d'origine conservée : **un item de dette n'existe qu'avec une preuve** (`fichier:ligne`). Les dettes
> **actionnables** vivent comme lignes P4-x ci-dessus, dans le même pipeline que tout le reste. Cette section ne
> garde que les décisions « **ne pas corriger** » qu'il faut pouvoir retrouver.

| Item | Décision | Preuve / raison |
|------|----------|-----------------|
| **Texte à 9-10 px dans les GRILLES (ex-P4-72, A11Y-06)** | 🟩 **keep délibéré** | 15 occurrences : `WeekGrid` ×4, `VenueAvailabilityGrid` ×3, `MonthCalendar` ×2, `WeekendGrid` ×2, `TypicalWeekendGrid` ×2, `ReservationGrid` ×2. Le reste (22 occ. hors grille) est passé à 12 px le 2026-08-08. Ici la **densité EST la fonction** : agrandir impose des lignes plus hautes, donc du défilement dans un écran conçu pour tenir en un coup d'œil. ⚠ **Aucun plancher WCAG n'existe** (1.4.4 exige le zoom 200 %, pas une taille mini) — c'est une barre qu'on se donne, et deux des cas sont eux-mêmes des correctifs a11y (le « F » de férié d'A11Y-08, `MonthCalendar:112` ; un bloc `aria-hidden` décoratif, `VenueAvailabilityGrid:126`). Le POURQUOI complet : [`etat-des-lieux.md`](../courantes/etat-des-lieux.md) §2 |
| **Fichiers > 400 l. (ex-P4-3, UXS-03/BCK-04)** | 🟩 **keep délibéré** | Front `PeriodStructure.tsx:1058` · `TeamsStep:857` · `RadarPanel:853` ; back `BcclSeeder:1158` · `SchedulePlanProvisioner:983` · `ScheduleConstraintBuilder:980`. **On découpe un fichier le jour où on le modifie pour une raison fonctionnelle, jamais pour la taille seule** — un lot de découpage à comportement constant est le terrain le plus fertile en régressions (§7.2). Exception nommée : `ScheduleConstraintBuilder`, dont la prochaine modification fonctionnelle emporte l'extraction. Le POURQUOI complet et les conditions de réouverture : [`etat-des-lieux.md`](../courantes/etat-des-lieux.md) §2 |
| **Filet `offGridSlots` inatteignable par l'API** | 🟩 **keep délibéré** | `VenueTrainingSlotInput:18` valide `Range(min: 1, max: 7)` : depuis que la grille rend les sept jours (P4-37), aucun appel API ne peut produire le jour aberrant que le filet rattrape (`PeriodStructure.tsx:503`). Gardé quand même — il défend contre ce qui s'écrit HORS API (fixtures, import, SQL direct), et c'est exactement le cas rencontré en #8. Une ligne, un test |
| **Ambre codé en dur dans `MonthCalendar.tsx`** (pas le token `--warning`) | 🟩 **keep délibéré** | `--warning` clair = 2.9:1 sur fond (A11Y-06) → l'utiliser sur le label vacances **échouerait WCAG AA** ; `amber-700` passe. Migrer = régression a11y contre un résidu de cohérence |
| **Publish Mercure dupliqué** | 🟩 defer (ligne P4-7) | 2 handlers, payloads distincts — extraire au 3ᵉ publisher |
| **`buildForOverlay` gardé comme adaptateur de `buildForPeriodPlan`** | 🟩 keep | Le court-circuit `scheduleId === null ⇒ []` est une économie d'intention, pas une branche de comportement (`scheduleId` est NOT NULL sur l'entité) — aucun test ne peut le faire tomber, ne pas en écrire un qui prétendrait le contraire |
