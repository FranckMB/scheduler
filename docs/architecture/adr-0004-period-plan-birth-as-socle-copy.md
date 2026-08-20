# ADR-0004 — L'adaptation naît comme une COPIE du socle (transcription, pas un solve)

- **Status**: accepted — Date: 2026-08-19 (besoin fondateur, plan validé en session)
- Programme **P2-44** ; PR-1 (ce document + le service) livrée 2026-08-19 ; PR-2 (surfaçage écran)
  livrée 2026-08-19 ; PR-3 (le comblement) livrée 2026-08-20 ; PR-4 (la transcription devient le
  DÉFAUT sur une fermeture) livrée 2026-08-20 — remplace la description initiale de PR-4, voir
  §Programme.

## Contexte

Un plan de PÉRIODE (overlay d'ajustement ou reprise de vacances — [`types-de-planning.md`](../../specs/courantes/types-de-planning.md))
naissait jusqu'ici sans V1 : le gestionnaire déclenchait un **solve CP-SAT complet** sur la
sélection de période (équipes/gymnases/contraintes filtrés — [ADR-0002](adr-0002-pattern-plan.md))
pour obtenir sa première version.

C'est disproportionné au besoin réel : la très grande majorité des adaptations de période sont
de la **routine** — un gymnase fermé trois semaines, une équipe qui réduit son volume — pas une
réorganisation. Un parent ne réorganise pas sa semaine de dépose/récupération pour une
indisponibilité de trois semaines ; il attend le planning **identique**, moins ce qui ne passe
plus. Un solve complet, lui, **redistribue tout** : la pénalité de stabilité (troisième palier
lexicographique de l'objectif, [ADR-0001](adr-0001-single-pass-solve.md) amendé 2026-08-17) ne
fait que **départager des ex æquo** une fois l'espace de solutions déjà exploré — elle n'empêche
pas le solveur de reproposer une séance à un autre horaire strictement équivalent à ses yeux, mais
pas aux yeux d'une famille qui a organisé sa semaine autour de l'ancien.

### Alternative instruite et écartée

**(a) Contraindre le solve à imiter le socle** (pousser la pénalité de stabilité en HARD, ou
injecter le socle comme candidat unique par équipe) a été considérée puis écartée seule : elle
garde le coût d'un solve complet (budget adaptatif, non-déterminisme documenté à la marge) pour un
résultat qui, dans le cas nominal, est *déjà* connu d'avance — la sélection de période est un
filtre déterministe sur des placements déjà valides. Contraindre un solveur à retrouver une
réponse qu'on peut calculer directement est un détour, pas une garantie de plus.

## Décision

### Le patron : transcription, pas génération

La V1 d'un plan de PÉRIODE est une **transcription sans solveur** de la version **POINTÉE** du
plan SEASON (le socle en vigueur — `SocleGuard::assertSeasonPlanChosen`), filtrée par la
**sélection de période EXISTANTE** (`PeriodConstraintSelector`/`PlanVenueClosures` — le même
filtre que le gate pré-solve et le payload, jamais un second calcul) :

- équipe désactivée pour la période → ses séances ne sont **pas copiées** (elle ne joue pas, rien
  à replacer) ;
- gymnase désactivé, ou séance sur un couple (gymnase, jour) effectivement fermé → non copiée,
  répertoriée **« à replacer »** avec sa raison (`venue_disabled` / `venue_closed`) ;
- équipe réduite (`sessionsPerWeek` d'override < séances du socle) → retrait **déterministe** des
  **dernières séances de la semaine** (tri jour puis heure décroissants) — répertoriées « à
  replacer » (`team_reduced`).

C'est l'**industrialisation, côté produit**, d'un patron qui existait déjà comme geste manuel de
seed (`BcclSeeder::pointPeriodPlanAtReprise`) : version `COMPLETED` sans passer par l'engine,
numérotée par la primitive standard (`SchedulePlanProvisioner::linkSchedule`), snapshot posé par
`ScheduleConstraintBuilder::buildForPeriodPlan`, les trois marqueurs de péremption remis à `false`
— **le seed n'est pas refactoré pour appeler ce service** ; le service copie son patron.
Implémentation : `App\Service\PeriodPlanTranscriber` (`backend/src/Service/PeriodPlanTranscriber.php`),
route `POST /api/schedule_plans/{id}/transcribe-from-socle`
(`App\Controller\TranscribePeriodPlanController`).

### Décisions tranchées (fondateur, session de cadrage)

1. **Verrous HARD révocables, pas immuables.** Chaque séance copiée est verrouillée `HARD` en
   base — « des verrous qu'on décide de bouger volontairement après ». L'**origine** du verrou est
   VRAIE, jamais devinée (`LockOriginProvenanceTest`) : `RESERVATION` si une `Reservation` du plan
   coïncide avec le placement (même patron que l'import), sinon `MANUAL` (le geste « transcrire »
   est management-déclenché et révocable).
2. **Pas de pointage automatique.** La V1 transcrite reste `COMPLETED` non pointée — le
   gestionnaire valide via la route de validation existante, comme pour une version issue d'un
   solve.
3. **Réduction déterministe, jamais un choix implicite du serveur.** « Les dernières de la
   semaine » (tri jour puis heure décroissants) est la seule règle — le gestionnaire échange
   ensuite par `move` s'il veut un autre arbitrage.
4. **« À replacer » est SERVI par le backend, pas redérivé par le front.** La réponse de la route
   porte la liste complète (équipe/jour/heure/gymnase/raison) — `PeriodTranscriptionResult`. Le
   front consomme cette donnée en PR-2, il ne recalcule rien.
5. **Provenance sans migration.** `Schedule.solverVersion` porte un marqueur produit dédié
   (`PeriodPlanTranscriber::TRANSCRIPTION_MARKER = 'socle-transcription'`), distinct du marqueur du
   seed — aucune colonne neuve, un champ existant réutilisé pour dire « cette version n'est pas née
   d'un solve ».
6. **Zéro appel moteur, contrat inchangé.** Le service n'émet aucun payload engine ; le contrat
   backend⇄engine (`CONTRACT_VERSION` — valeur courante : `engine/CONTRACT_VERSION`) n'est pas
   touché par ce lot.

### Q6 — vérifié, pas de traitement de dérive supplémentaire

Affirmation fondateur, confrontée au code : « revalider une AUTRE version de saison détruit les
plans de période non commencés » est **vraie** — `ValidateScheduleController::__invoke`
(l. 155-180) calcule `OverlayManager::periodPlansInvalidatedBySeasonChange` puis appelle
`deletePeriodPlanForEntry(force: true)` sur le **plan entier** de chaque période non commencée
(grille copiée comprise), pas seulement ses versions. Une copie transcrite ne peut donc jamais
survivre à un socle qui a changé sous elle : le plan qui la porte est détruit d'abord. Aucun
mécanisme de détection de dérive socle↔copie n'est nécessaire pour ce lot.

## Conséquences

- Une période dont le besoin réel est « la routine, moins ce qui ferme » obtient sa V1
  **instantanément**, sans budget solveur, sans variance de sortie CP-SAT.
- Le geste reste **optionnel** : rien n'empêche un solve complet sur un plan de période vierge (la
  route existante `generate` continue de fonctionner) — la transcription est une V1 alternative,
  pas un remplacement du solve pour les cas qui en ont réellement besoin.
- **NR bloquant** : `Security/PeriodCopyBirthTest` (`backend/tests/Security/PeriodCopyBirthTest.php`)
  — step de `blocking-tests` (`.github/workflows/ci.yml`), ligne CLAUDE.md §4 — falsifie les deux
  sens (séance copiée+verrouillée ; jour fermé/gymnase désactivé/équipe réduite « à replacer » avec
  leur raison ; réduction déterministe ; plan déjà versionné refusé 409 ; route sous les gardes
  rôle+tenant). Axes structurants touchés (CLAUDE.md §7.1) : *generation pipeline* et *planning
  lifecycle*.

## Programme (P2-44) — ce que PR-1 à PR-4 ne couvrent pas

PR-1 a livré le service et la route seuls, sans écran. **PR-2 (livrée 2026-08-19)** a livré le
surfaçage front : le bouton « Partir du planning de saison » sur un plan de période vierge
(`GenerateStep`, refus 409 servi et affiché), le panneau « Séances à replacer » (`ToReplaceList`,
données servies par PR-1, présentation pure), la mise en évidence des vides (`emphasizeEmpty`,
`WeekGrid`) et la comparaison visuelle période↔saison en modale de consultation
(`SeasonComparisonModal`) — détail : `frontend/docs/frontend-spec.md` §6.7 bis.

**PR-3 (livrée 2026-08-20) — le comblement.** `POST /api/schedules/{id}/fill`
(`App\Controller\FillPeriodPlanController`) : un solve **partiel** d'une version de plan de
PÉRIODE, borné aux séances « à replacer », plutôt que le choix binaire transcrire-ou-solver-tout.
Crée une V+1 (savepoint) et dispatche le rail de génération async **existant** avec un mode fill
(`GenerateScheduleMessage::fillSourceScheduleId`) : `ScheduleConstraintBuilder::withPinnedAssignments`
greffe, **dans le payload du solve seul** (jamais persisté en base), les placements de la version
source en épingles `lockLevel: HARD` — un HARD n'a pas de variable côté moteur (`objective.py`), le
solveur ne peut donc placer QUE les trous (équipes sous leur `sessionsPerWeek`) ; le handler saute
`withPreviousAssignments` en mode fill (le terme de stabilité serait un no-op sur du déjà-épinglé).
Verrou de génération, Mercure, import : gratuits, réutilisés tels quels. **Zéro changement
moteur, contrat backend⇄engine intact.** Quotas : `ClubQuotaSubscriber` couvre désormais **4 routes de
solve** (`generate`/`regenerate`/`regenerate-from`/`fill`). Écran : bouton « Combler
automatiquement » (`PlanningPage`, visible dès qu'une dérive porte des séances « à replacer »).
NR sémantique (groupe `contract`, job `engine-semantics`) :
`CrossStack/FillPreservesCopiesAndFillsGapsTest` — falsifie que les placements copiés restent
INTACTS et que les orphelines sont placées, avec un vrai solveur. Détail :
`backend/docs/backend-inventory.md` §3, `frontend/docs/frontend-spec.md` §6.7 bis.

**PR-4 (livrée 2026-08-20) — la transcription devient le DÉFAUT sur une fermeture, remplace la
description initiale de PR-4.** Le besoin cadré n'était pas « injecter le socle transcrit dans
`previousAssignments` d'un solve complet volontaire » (ce mécanisme est en réalité déjà couvert
par le repli générique de `GenerateScheduleHandler::resolvePreviousAssignmentSlots` — une V1
transcrite `COMPLETED` est une version du plan comme une autre, elle EST déjà éligible à la
dernière-COMPLETED du plan) mais un besoin d'écran, dans les mots du fondateur : « voir ce que ça
lui coûte d'avoir un plan sans le gymnase, et il va l'adapter en conservant au maximum les
créneaux tels quels ». Décisions tranchées (fondateur, session de cadrage 2026-08-20) :

1. **Défaut = CLOSURE seulement.** À l'arrivée sur l'étape Génération d'un plan de période de type
   FERMETURE (`CalendarEntryPeriodType::CLOSURE`) **sans aucune version**, la transcription se
   déclenche automatiquement — le planning de saison amputé des contraintes de la période est déjà
   à l'écran, prêt au déplacement manuel, sans que le gestionnaire ait à cliquer le bouton manuel
   de PR-2. Les trois issues restent atteignables : déplacer à la main, combler (PR-3), tout
   remanier (solve complet).
2. **HOLIDAY exclu, à l'octet près.** Une reprise de vacances garde le comportement PR-2/PR-3
   inchangé (le bouton manuel « Partir du planning de saison », rien d'automatique). Deux raisons :
   décision de sens (« les vacances sont TOTALEMENT différentes d'un incident de saison, c'est un
   planning TOUT nouveau, régénérer de zéro y est accepté, je ne veux pas de copie du socle ici »)
   et raison **technique** établie au cadrage : une reprise dont la grille est réécrite (créneaux
   déplacés en journée) verrait les séances du soir du socle copiées en verrous HARD **hors
   grille** — `OrphanPinGuard::firstOrphanMessage` (appelé par `GenerateScheduleController` ET
   `FillPeriodPlanController`) refuserait alors **422 « Régénérer » ET « Combler »** ; l'auto-
   transcription y enfermerait le gestionnaire au lieu de l'aider.
3. **Mutation FRONT, jamais un GET qui écrit.** Le déclencheur vit dans `GenerateStep.tsx` (effet
   à l'arrivée sur l'étape, ref one-shot par plan — StrictMode/remontage ne rejouent pas), bornée
   au rôle de gestion (miroir d'affichage, le serveur reste seul juge). Le 409 « plan déjà
   versionné » d'un double appel (StrictMode, remontage, second onglet) est traité comme
   **bénin** : le serveur relit sa garde sous verrou, le front réconcilie la liste des versions
   sans bandeau rouge.
4. **Zéro retrait.** Le bouton manuel de PR-2 n'est ni supprimé ni relibellé — sa condition
   d'affichage existante (`0 === periodPlanVersions.length`) le fait simplement disparaître de
   lui-même dès qu'une V1 existe (transcrite ou non) ; il reste le seul geste sur une reprise de
   vacances.

**Zéro changement `backend/src/`, zéro ligne moteur, `CONTRACT_VERSION` inchangé.** NR : le
mécanisme générique « une V1 transcrite est une COMPLETED comme une autre, donc éligible au
repli `previousAssignments` » n'était pas explicitement gardé — `CrossStack/PreviousAssignmentsPayloadParityTest`
(fichier déjà step de `blocking-tests`) gagne le cas dédié : un solve complet dont la dernière
COMPLETED du plan de période est une V1 transcrite émet SES placements en `previousAssignments`,
falsifié dans les deux sens (muter une copie rougit ; ni la séance « à replacer » filtrée à la
transcription ni un créneau du socle ne fuient).

Reste :

- **PR-5** — écarts nommés : après génération (transcrite ou solvée), l'adaptation raconte ce qui a
  changé vs le socle (« Matéo fermé : N séances déplacées, équipes réduites… ») — recoupe P2-43 (iv).

## Alternatives considérées

- **(a)** Contraindre le solve à imiter le socle — voir §Contexte, écartée : coût d'un solve pour
  un résultat calculable directement dans le cas nominal.
