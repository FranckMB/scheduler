# ADR-0004 — L'adaptation naît comme une COPIE du socle (transcription, pas un solve)

- **Status**: accepted — Date: 2026-08-19 (besoin fondateur, plan validé en session)
- Programme **P2-44** ; PR-1 (ce document + le service) livrée 2026-08-19 ; PR-2 (surfaçage écran)
  livrée 2026-08-19.

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
   backend⇄engine (`CONTRACT_VERSION` 2.12) n'est pas touché par ce lot.

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

## Programme (P2-44) — ce que PR-1+PR-2 ne couvrent pas

PR-1 a livré le service et la route seuls, sans écran. **PR-2 (livrée 2026-08-19)** a livré le
surfaçage front : le bouton « Partir du planning de saison » sur un plan de période vierge
(`GenerateStep`, refus 409 servi et affiché), le panneau « Séances à replacer » (`ToReplaceList`,
données servies par PR-1, présentation pure), la mise en évidence des vides (`emphasizeEmpty`,
`WeekGrid`) et la comparaison visuelle période↔saison en modale de consultation
(`SeasonComparisonModal`) — détail : `frontend/docs/frontend-spec.md` §6.7 bis. Suite, chacune sa
PR :

- **PR-3** — comblement : un solve **partiel**, borné aux séances « à replacer », plutôt qu'un
  choix binaire transcrire-ou-solver-tout.
- **PR-4** — le socle transcrit entre en `previousAssignments` du solve complet quand le
  gestionnaire choisit malgré tout de générer une période (voulue, pas accidentelle) — cohérence
  avec le mécanisme de stabilité déjà en place pour les régénérations de saison (`GenerateScheduleHandler::resolvePreviousAssignmentSlots`,
  contrat 2.11).
- **PR-5** — écarts nommés : après génération (transcrite ou solvée), l'adaptation raconte ce qui a
  changé vs le socle (« Matéo fermé : N séances déplacées, équipes réduites… ») — recoupe P2-43 (iv).

## Alternatives considérées

- **(a)** Contraindre le solve à imiter le socle — voir §Contexte, écartée : coût d'un solve pour
  un résultat calculable directement dans le cas nominal.
