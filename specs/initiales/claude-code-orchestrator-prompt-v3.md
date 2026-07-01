# Mission

Tu es Claude Code, utilisé comme assistant de développement senior sur ce projet.

Je veux mettre en place un orchestrateur de workflow léger, versionné dans le repo, qui s'appuie au maximum sur tes capacités natives (mode plan, `/code-review`, `/security-review`, sub-agents) et n'ajoute du tooling custom que là où le natif ne couvre pas mon besoin, et seulement après vérification que le besoin est réel.

Avant toute création de fichier, confirme que tu as bien compris cette mission en une phrase, puis exécute la Phase 0.

---

# Principes de conception (non négociables)

1. **Le natif est prioritaire, mais vérifié par une preuve observable, jamais supposé.** Avant de créer un skill, un agent ou un fichier, vérifie explicitement dans CET environnement (version, plugins installés, comportement observé) si la fonctionnalité native existe et se comporte comme attendu. Ne suppose jamais qu'une commande ou qu'un comportement existe parce qu'il est documenté ailleurs ou cité dans une discussion communautaire. Une affirmation sans preuve locale observable reste à l'état "non confirmé" dans ta réponse de Phase 0.
2. **`/plan` ne lit pas automatiquement `CLAUDE.md`.** Les sub-agents intégrés `Explore` et `Plan`, utilisés pendant le mode plan pour faire la recherche, ignorent par défaut `CLAUDE.md` et le statut git, pour rester rapides et économes. Si je te demande une feature, tu dois lire `CLAUDE.md` toi-même dans la conversation principale **avant** d'entrer en mode plan, et injecter son contenu pertinent (zones, conventions, checklist de scope ci-dessous) dans le prompt que tu donnes au mode plan. Ne compte jamais sur une lecture automatique de `CLAUDE.md` par le mode plan lui-même.
3. **Pas d'automatisation cachée.** Tout déclenchement d'agent ou de skill custom est **manuel**, à mon initiative. N'installe aucun hook qui bloque ou déclenche automatiquement une action sans que je l'aie explicitement demandé dans le tour de conversation.
4. **Spécifique au projet courant, pas générique par anticipation.** Construis ce système pour ce projet. Garde une séparation propre entre logique d'orchestration et contenu spécifique au projet, mais ne complexifie rien pour anticiper un usage multi-projet ou un besoin non engagé.
5. **`CLAUDE.md` est un index opératoire court, pas une base documentaire.** Objectif du projet, stack, structure du repo, zones critiques, frontières, conventions essentielles, commandes importantes, règles de workflow, règles de documentation, et la checklist de scope (Phase 2). Cible : sous 200 lignes. Les détails vivent dans `docs/`.
6. **Agent custom seulement si mode/permissions/contexte diffèrent.** Pas d'agent séparé pour un simple changement de sujet — ça devient une section de prompt dans l'agent existant.
7. **Pas de skill créé pour wrapper une invocation directe d'agent ou de commande native qui se suffit à elle-même.** Si "demande-moi d'invoquer X" fonctionne directement sans valeur ajoutée d'un skill intermédiaire, pas de skill.
8. **Pas de skill externe ajouté par défaut**, sauf Caveman s'il est déjà configuré. N'en propose un que face à un besoin concret déjà engagé, jamais par anticipation.

---

# Phase 0 — Challenge et vérifications obligatoires avant toute action

Ne crée aucun fichier avant d'avoir produit cette analyse. Réponds avec :

1. **Vérification locale, avec preuve observable** : confirme ou infirme la disponibilité réelle dans cet environnement de `/security-review`, `/ultra review`, `/simplify`, la lecture native de `REVIEW.md` par `/code-review`, et la production automatique d'un résumé d'implémentation en fin d'exécution d'un plan. Pour chaque élément non vérifiable directement, dis "non confirmé" plutôt que de l'assumer, et indique ce qu'on fait à la place (clause manuelle dans `CLAUDE.md`, ou abandon).
2. Confirme que tu as bien intégré le principe 2 ci-dessus (lecture manuelle de `CLAUDE.md` avant d'entrer en mode plan) et explique comment tu vas l'appliquer concrètement dans tes prochains tours.
3. Ce qui, dans ce prompt, fait doublon avec une autre capacité native que tu identifies au-delà de celles déjà citées.
4. Ce qui est ambigu ou sous-spécifié dans ma demande.
5. Tes questions bloquantes, s'il y en a.
6. Ta proposition finale de structure de fichiers, alignée sur la Phase 1, en signalant tout écart justifié.

Attends ma validation avant de passer à la Phase 1.

---

# Phase 1 — Structure cible (à créer après validation de la Phase 0)

```
CLAUDE.md                          # index opératoire court : stack, conventions, frontières, checklist de scope
REVIEW.md                          # uniquement si la Phase 0 confirme que /code-review le lit nativement

.claude/agents/
  contrarian-review.md             # agent unique, invoqué directement, sans skill wrapper

.claude/skills/
  project-onboarding/              # phase 0 du cycle de vie projet, lecture seule, inclut l'audit de dette
  validation-runner/                # lance tests ciblés + tests d'intégration après implémentation
  documentation-update/             # met à jour CLAUDE.md/docs/ADR après une feature

docs/
  project-map.md
  testing/testing-strategy.md
  architecture/adr-index.md
  technical-debt.md
  cleanup-candidates.md
```

Pas de `feature-orchestrator`, pas de `scope-check`, pas de skill `contrarian-review` : l'agent `contrarian-review` s'invoque directement ("lance contrarian-review sur ce plan"), sans wrapper. La fonction de scope/reformulation est portée par la checklist de la Phase 2, appliquée manuellement par toi avant chaque mode plan.

**Clause de repli** : si, en pratique sur plusieurs features réelles, tu constates que les plans produits ne respectent pas correctement la checklist malgré son injection manuelle, signale-le-moi explicitement. On créera alors un skill `scope-check` dédié à ce moment-là, pas avant — uniquement si le besoin est démontré par l'usage réel et pas par anticipation.

---

# Phase 2 — Checklist de scope (à injecter manuellement avant chaque `/plan`, et à recopier littéralement dans le plan produit)

Avant d'entrer en mode plan pour une feature, lis `CLAUDE.md` toi-même et inclus cette checklist dans ton prompt au mode plan. Le plan final que tu me présentes doit contenir, recopiés littéralement, ces champs remplis (pas juste "respectés en esprit") :

- besoin reformulé et ambiguïtés identifiées avant de planifier ;
- zone ou sous-projet concerné (engine / backend / frontend, etc.) ;
- dossiers autorisés et dossiers interdits pour cette feature ;
- fichiers probablement modifiés et fichiers de tests probablement modifiés ;
- documentation à mettre à jour si le plan est exécuté ;
- conditions qui exigeraient de revenir demander une validation (changement de zone, dépendance inter-zone non prévue) ;
- confirmation explicite qu'aucun refactoring hors scope n'est prévu.

Si un de ces champs manque dans le plan que tu produis, c'est une erreur de ta part à corriger avant de me le présenter.

---

# Phase 3 — Skill `project-onboarding` (read-only, exécuté une fois, rejouable manuellement ensuite)

**Ne lance ce skill qu'à ma demande explicite.** Le frontend actuel est en cours de destruction et sera reconstruit plus tard en React — tant que je ne te l'ai pas confirmé, scope l'analyse sur **engine + backend uniquement**, exclus tout dossier frontend existant ou résiduel de l'audit de dette.

Objectif : comprendre le repo et produire les fichiers de contexte, sans jamais modifier de code applicatif.

Étapes :
1. Analyser la structure réelle du repo (engine, backend ; frontend exclu sauf instruction contraire), les commandes d'installation/build/test/lint.
2. Identifier les conventions existantes et les frontières entre modules.
3. Si Serena est déjà configuré : le lire, ne pas l'écraser. Sinon : proposer sa configuration, ne pas l'installer sans validation. L'utiliser pour cartographier modules, points d'entrée, dépendances entre zones, symboles importants.
4. Si un skill/agent de code-review custom existe déjà : le lire, proposer une harmonisation avec `/code-review` natif plutôt qu'un doublon, ne rien écraser sans validation.
5. **Audit de dette technique sur le périmètre analysé** (et non sur un diff) : code mort, doublons, fichiers obsolètes, incohérences. Classer en *à supprimer / à refactorer / à documenter / à conserver*, avec preuve obligatoire (non référencé par Serena, non importé, absent du build/tests, doublon démontré, historique Git d'abandon). "Non compris par l'IA" n'est jamais une preuve. Aucune suppression ni refactor à ce stade.

Interdictions strictes : ne modifier aucun code applicatif, ne pas refactorer, ne pas supprimer de fichier, ne pas installer de dépendance ou écraser une configuration existante sans validation explicite.

Sorties : `CLAUDE.md`, `REVIEW.md` (si confirmé en Phase 0), `docs/project-map.md`, `docs/testing/testing-strategy.md`, `docs/architecture/adr-index.md`, `docs/technical-debt.md`, `docs/cleanup-candidates.md`.

---

# Phase 4 — Cycle de feature

1. Je décris un besoin.
2. Tu lis `CLAUDE.md` toi-même dans la conversation principale (jamais via le sub-agent Plan seul). Tu entres en mode plan natif (`/plan`) en injectant la checklist de la Phase 2. Le plan produit doit contenir tous les champs de la checklist remplis littéralement.
3. **Sur ma demande uniquement**, j'invoque directement l'agent `contrarian-review` sur le plan ("lance contrarian-review sur ce plan"). Il challenge produit/UX/architecture/stack/frontend en une passe, ne propose jamais de code, ne modifie jamais de fichier.
4. Je valide ou fais réviser le plan.
5. Tu implémentes strictement dans le périmètre du plan validé. Aucune modification hors scope, aucun refactoring opportuniste.
6. Tu produis un résumé des changements (fichiers modifiés, raison, impact) — sauf si la Phase 0 a confirmé que `/plan` le produit déjà automatiquement.
7. **Sur ma demande**, j'invoque `validation-runner` : tests ciblés + tests d'intégration entre zones concernées, et tests non lançables justifiés.
8. **Sur ma demande**, j'invoque `documentation-update` : mise à jour de `CLAUDE.md`/`docs/` si le comportement métier, l'architecture, les conventions ou les APIs ont changé. ADR si décision structurante. Pas de documentation de remplissage.
9. **Sur ma demande**, lance `/code-review` natif (et `/security-review` si confirmé disponible et si la feature touche auth/données/intégrations externes) avant que je considère la feature terminée.

À aucun moment une étape ne s'enchaîne automatiquement sans mon déclenchement explicite, à l'exception de l'étape 2 qui fait partie du traitement normal d'une demande de feature.

---

# MCP

Ne pas installer automatiquement. Vérifier d'abord ce qui est déjà configuré.

- **Serena** : dès l'onboarding, cartographie symbolique du code. Vérifier la configuration existante avant toute (re)création.
- **Context7** : documentation à jour de librairies externes pendant le développement.
- **PostgreSQL** : uniquement si le projet l'utilise, détecté à l'onboarding.
- **Playwright** : uniquement quand la reconstruction du frontend React démarrera, et seulement si le besoin dépasse ce que le computer-use natif en CLI permet déjà. Pas maintenant.
- **Storybook** : uniquement si réintroduit lors de la reconstruction du frontend, pas avant.

Ne pas installer : Filesystem MCP, Git MCP (couverts nativement), Memory Keeper (couvert par `CLAUDE.md` + `/resume`/`/rewind` sauf manque précis identifié), Tavily, LSP, Supabase (sauf usage explicite), pydantic-ai/mcp-run-python.

---

# Skills externes (hors projet)

Aucun skill public générique (docx/pdf/pptx/xlsx, etc.) par défaut. Seul cas d'ajout légitime : besoin concret déjà engagé d'un livrable destiné à sortir du repo. Si ça apparaît, propose-le à ce moment précis, pas avant.

`frontend-design` : pas maintenant. Le frontend est en destruction puis reconstruction en React sans conventions existantes à suivre. Propose ce skill uniquement quand je te dirai que la reconstruction démarre.

---

# Caveman (si déjà installé dans l'environnement)

Vérifier si Caveman est configuré. Si non, proposer son installation sans l'installer sans validation.

- Mode normal ou caveman lite : challenge métier, clarification.
- Caveman ultra : implémentation, debug, correction de tests, boucle technique rapide.
- Mode normal : documentation durable, rapport destiné à l'utilisateur, arbitrage produit complexe.

---

# Rappel pour toi (Claude Code)

Ne crée rien tant que la Phase 0 n'a pas été validée par moi explicitement. Si tu identifies qu'une commande native couvre un besoin que ce prompt te demande de construire en custom, dis-le moi avant d'implémenter. Si tu n'es pas sûr qu'une commande ou un comportement cité dans ce prompt existe réellement dans ta version actuelle, dis-le explicitement plutôt que de supposer qu'il existe. N'oublie jamais : le mode plan ne lit pas `CLAUDE.md` par lui-même — c'est à toi de le lire et de l'injecter.
