# Reprise — le geste « modifier mon planning de saison » (P2-7)

> **But de ce document** : permettre de relancer une session de zéro sans rien re-cadrer.
> Tout ce qui suit est **décidé** (fondateur, 2026-07-16) — ce ne sont pas des pistes.
> Ce qui reste ouvert est marqué **À TRANCHER**.

## D'où on vient

- **Bascule ADR-0002** (PR #238, mergée) : le plan de type `SEASON` et la version qu'il
  **pointe** sont LE calendrier de la saison. « Validé » se dérive du pointeur, et de rien
  d'autre. Valider = pointer + **supprimer** les sœurs ; rouvrir = **dépointer**. Aucun
  pointage automatique. Le legacy (`baselineScheduleId`, `socleValidatedAt`,
  `planningName`, statuts `VALIDATED`/`ARCHIVED`, `SetBaselineController`) est mort.
- **PR-1 « périmètre engagé »** (branche `feat/engaged-team-guard`) : une équipe qui joue
  en compétition ne peut être ni **supprimée** ni changer de **`level`**. Voir
  [`module-matchs.md`](../courantes/module-matchs.md).

## La réalité du terrain (fondateur — à lire avant tout)

> « Le planning de saison est nécessaire à faire au plus tôt : la saison débute en
> septembre et les matchs avant octobre. Une fois que les matchs sont envoyés à la fédé,
> il n'est plus question de changer les équipes en compétition. **Ça n'arrive jamais**,
> ça remet tout le fonctionnement du club en question. Dans le workflow d'un club, le
> planning de la saison **ne change quasiment JAMAIS** — il s'ajuste dans de rares cas. »

**Conséquence de cadrage** : ce lot ne sert PAS à fluidifier un flux courant. Il sert à ce
que **le cas rare ne casse rien**. La confirmation forte n'est pas une friction à
minimiser — **c'est la fonctionnalité**.

**Le cas réel qui arrive** : le BCCL a un gymnase en construction. Quand il le récupère,
le planning de saison changera. Ce n'est pas hypothétique.

## Ce qu'il reste à faire (P2-7)

### 1. Une seule porte — fermer la création concurrente

**Défaut prouvé** (test HTTP réel, cette session) : `POST /api/schedules` rend **201**
alors que le plan pointe déjà une version. Le modèle du fondateur — « par définition,
quand une version est pointée, les autres sont supprimées » — **n'est pas garanti par le
code**.

Aujourd'hui : plan pointe V1 → `POST /api/schedules` (201) → `POST /V2/generate` (accepté,
V2 n'est pas pointée) → V1 pointée ET V2 COMPLETED coexistent → valider V2 bascule et
supprime V1. Les gardes existent sur `/generate` et `/regenerate` de la version **pointée**,
mais **pas sur la création**. Les portes sont fermées, le mur est ouvert.

**À faire** : `ScheduleStateProcessor::processPost` refuse de créer une version de saison
(`calendarEntryId === null`) tant que le plan SEASON en pointe une. Message : « rouvrez le
planning avant d'en préparer un autre ».

**Effet** : « la seule manière de modifier le plan est Rouvrir » devient vrai **par
construction**. La garde `overlays_exist` de `ValidateScheduleController` ne sert alors plus
qu'au cas « pointeur null avec des plans secondaires survivants ».

### 2. Confirmation forte

Rouvrir n'est pas un clic : **popup d'avertissement + validation explicite** (« je veux
modifier mon planning de saison ») pour que le gestionnaire assume les conséquences.

Aujourd'hui : le 409 `overlays_exist` + `confirmDeleteOverlays: true` ne parle que des
plannings secondaires.

### 3. Les matchs

| | |
|---|---|
| `PLACED` / `SUBMITTED` / `VALIDATED` | **survivent** — et **contraignent** : leur équipe est engagée (PR-1) |
| `UNPLACED` | **supprimés** → l'import FBI doit être refait |

Un match **à l'extérieur** engage : il naît `PLACED` (horaire imposé par l'adversaire).

### 4. Rien du passé, rien de ce qui est en cours

« Supprimer tout » ne vise que ce qui **n'a pas encore eu lieu**. Une période **en cours**
survit aussi (pivot = la date de **début**) : seules les périodes **entièrement futures**
sont supprimées.

**Défaut à emporter** : `CalendarEntryRepository::findWithOverlayByClubSeason` n'a **aucun
filtre de date**. Rouvrir en mars détruit aujourd'hui l'overlay des vacances de Toussaint,
une période passée. Comportement hérité, jamais conforme à la règle.

## Décisions déjà prises — ne pas les re-poser

| Question | Décision |
|---|---|
| Rouvrir supprime-t-il les matchs ? | **Oui**, avec confirmation — mais **seulement les `UNPLACED`** (les engagés survivent, cf. PR-1) |
| Ce lot dans la PR de la bascule ? | **Non** — lot dédié : comportement nouveau, sa propre confirmation, ses tests |
| Périmètre engagé = axe structurant §7.1 ? | **Oui** — tests en `--group phase1` + ligne CI |
| Le niveau (`Team.level`) | **Figé sans exception** dès l'engagement, y compris `null → REGIONAL`. Il se saisit AVANT de générer (tag NIVEAU → contraintes → photo de structure). Le laisser bouger ferait diverger la photo et la base. Seule tolérance : un PUT qui ré-écho le **même** niveau |
| Le rang / tier | **Libre** — perception interne du club, ça bouge |
| `isActive` | **Libre** — « la désactivation concerne les overlays et les plannings de vacances » |

## À TRANCHER en début de session

- **(a) et (b) suivent-ils les mêmes règles ?** Deux gestes déplacent le calendrier de
  base : **rouvrir** la version pointée, et **valider une autre version**. Le fondateur
  considère (b) comme inexistant — « quand une version est pointée, les autres sont
  supprimées ». C'est vrai **du modèle**, faux **du code** tant que le §1 ci-dessus n'est
  pas fait. Une fois la création concurrente fermée, (b) devient inatteignable et la
  question disparaît. **À confirmer.**
- **Import FFBB qui change un niveau** : « si je veux changer le niveau par import FFBB
  pour les matchs, je gérerai ce cas à ce moment-là ». Non traité, volontairement.

## Les autres lots ? Dans la roadmap, pas ici

Ce document ne porte QUE le cadrage de P2-7 — ce que la roadmap ne peut pas tenir : le
verbatim du fondateur, le pourquoi métier, les décisions déjà prises.

**Tout le reste vit dans [`roadmap.md`](roadmap.md)**, le suivi unique (CLAUDE.md §8). Le
recopier ici en ferait un second endroit qui dit la même chose — précisément le motif qui a
coûté 40 défauts sur la bascule.

Les dettes croisées pendant cette session y sont des lignes à part entière :

| | |
|---|---|
| **P2-8** | Le front re-dérive 3 règles de refus du serveur (chaque miroir dérive) |
| **P3-10** | Libellés de versions : le front ignore `Schedule.versionNumber`, stable côté serveur |
| **P3-11** | Radar : aucun état de chargement |
| **P3-12** | Doc ops : tunnel Cloudflare pour les démos |
| **DOC-1** | Divergence doc↔code : le module matchs est-il découplé du socle ? **À trancher** |

Et les lots structurants : **P2-6** (pattern « Plan », dont le **lot C** — réglages de
période & génération pilotés par plan, inv. 5, cf.
[`adr-0002-pattern-plan.md`](../../docs/architecture/adr-0002-pattern-plan.md)), **P2-7a**
(le périmètre engagé, livré).

## Méthode — ce que cette session a coûté

La bascule ADR-0002 a demandé **4 rounds de revue et 40 défauts confirmés**. **Un seul
motif** : une garde, un compteur ou un sélecteur resté sur l'ancienne vérité. Deux endroits
qui répondent à la même question finissent **toujours** par répondre autre chose.

À garder pour la suite :

1. **Un test qui ne peut pas échouer ne prouve rien.** Désarmer la garde et vérifier que le
   test tombe. Trois défauts de cette session ont survécu à des tests verts.
2. **Ne jamais écrire « vérifié » sur un balayage incomplet.** Le contournement de
   `wipeStructure` a été raté exactement comme ça.
3. **Ne pas assouplir une règle du fondateur sans demander.** Une entorse prise seul a
   fabriqué un bug, présenté ensuite comme une découverte.
4. **`cache:clear` avant `api:openapi:export`.** Un export sur cache périmé a produit un
   snapshot faux, committé après vérification du diff.
5. **Committer avant tout `git checkout`.** Trois tests non committés ont été perdus ainsi.
