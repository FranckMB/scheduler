<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Schedule;
use App\Entity\SchedulePlan;
use App\Entity\Season;
use App\Enum\SchedulePlanType;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

/**
 * ADR-0002 Lot A — the SINGLE point that creates SchedulePlan rows and links a
 * Schedule to its plan/version. Reused at every season- and schedule-creation
 * site so the new model stays in sync additively (nothing legacy is touched).
 *
 * A plan is born FROM A CALENDAR EVENT (ADR-0002, lot C — décision fondateur
 * 2026-07-17). There are exactly two birth sites, and no other:
 *
 * - ensureSeasonPlan(): the SEASON plan exists as soon as the season does (an
 *   empty "espace de travail" with no version yet).
 * - provisionPeriodPlan(): le plan CLOSURE/HOLIDAY naît au geste — "ajuster une période
 *   de vacances / un souci du calendrier", c'est-à-dire la création de la CalendarEntry.
 *   Appelé depuis CalendarEntryStateProcessor (seul site de src/ qui crée une entrée) et
 *   ATOMIQUE avec elle. Les périodes se configurent AVANT toute génération : le plan doit
 *   donc précéder les réglages qui s'y accrochent (inv. 5) — le créer à la première
 *   version, comme le lot A, était simplement trop tard. Naissance seule : l'identité
 *   d'une période qui porte un plan est gelée (422), il n'y a donc rien à synchroniser.
 * - linkSchedule(): stamps schedulePlanId + versionNumber on a freshly-created
 *   schedule. It only ever LOOKS UP a period plan (findPeriodPlanId), never
 *   creates one: a second creation site would let a missing gesture-time plan pass
 *   unnoticed. Idempotent-safe: a schedule already linked keeps its version.
 *
 * ⚠️ Corollaire : le self-heal de choose() ne peut PLUS réparer un plan de période
 * manquant (il passe par linkSchedule, qui ne fait que chercher). C'est pourquoi la
 * naissance est atomique avec l'entrée — une période sans plan ne doit pas exister.
 *
 * Existence/version lookups run as RAW SQL on purpose: the request-scoped
 * ORM season_filter pins every ORM read to the active season, but the
 * provisioner operates on the SCHEDULE's OWN season (overlays bind to their
 * period's season, which may differ). Raw SQL dodges that filter; RLS still
 * scopes every statement by club, and INSERTs (persist) are never filtered.
 *
 * chosenScheduleId — le pointeur — est LE calendrier de la saison : choose() le
 * pose (valider), releaseSchedule() le retire (rouvrir). Rien ne le pose
 * automatiquement : seul le gestionnaire choisit.
 */
final class SchedulePlanProvisioner
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ScheduleConstraintBuilder $constraintBuilder,
    ) {}

    /** Inv. 9 : seuls closure/holiday portent un plan. Source unique du mapping. */
    private static function periodPlanType(?string $periodType): ?SchedulePlanType
    {
        return match ($periodType) {
            'closure' => SchedulePlanType::CLOSURE,
            'holiday' => SchedulePlanType::HOLIDAY,
            default => null,
        };
    }

    /**
     * The season's SEASON plan, created if absent. NOTE: an already-existing plan
     * is returned as a lazy ORM reference — read only its getId() unless the
     * request's active season IS this plan's season, else the proxy's filtered
     * lazy-load can throw EntityNotFoundException (season_filter). Lot A callers
     * either discard the result or use getId() only.
     */
    public function ensureSeasonPlan(Season $season): SchedulePlan
    {
        return $this->existingSeasonPlan($season->getId()) ?? $this->createSeasonPlan($season);
    }

    /**
     * ADR-0002 : la PÉRIODE du plan SEASON suit celle de la saison. Le NOM, lui,
     * appartient au plan (inv. 12) et n'est écrit que par son renommage — un
     * second écrivain le rendrait non durable.
     */
    public function syncSeasonPlan(Season $season): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE schedule_plan SET start_date = :start, end_date = :end, updated_at = now(), version = version + 1 '
            . 'WHERE season_id = :sid AND type = \'SEASON\'',
            [
                'start' => $season->getStartDate(),
                'end' => $season->getEndDate(),
                'sid' => $season->getId(),
            ],
            ['start' => Types::DATETIMETZ_IMMUTABLE, 'end' => Types::DATETIMETZ_IMMUTABLE],
        );
    }

    /**
     * Link a freshly-created schedule to its plan + version. A schedule already
     * linked is left untouched. No-op when no anchor can be resolved
     * (transition-safe: schedulePlanId stays null).
     *
     * Serialized per plan-scope (season or period) with a Postgres advisory lock
     * — same idiom as SeasonTransitionService — so concurrent/double-submitted
     * creations neither collide on the version number (uniq_schedule_plan_version)
     * nor duplicate a period's plan (check-then-insert). Nests safely inside a
     * caller's transaction (RegenerateController): the lock is held to the
     * outermost commit.
     */
    public function linkSchedule(Schedule $schedule): void
    {
        if (null !== $schedule->getSchedulePlanId()) {
            return;
        }

        $this->entityManager->wrapInTransaction(function () use ($schedule): void {
            $this->lockPlanScope($schedule->getCalendarEntryId() ?? ('season:' . $schedule->getSeasonId()));

            // Period plans are LOOKED UP, never created here: they are born with the
            // manager's gesture (provisionPeriodPlan). A null means the entry carries
            // no plan — either a non-generating type (inv. 9: cutoff/mutualisation) or
            // an entry created outside CalendarEntryStateProcessor (tests). Either way
            // the schedule stays unlinked rather than silently minting a second plan.
            $planId = null === $schedule->getCalendarEntryId()
                ? $this->ensureSeasonPlanId($schedule->getSeasonId())
                : $this->findPeriodPlanId($schedule->getCalendarEntryId());
            if (null === $planId) {
                return;
            }

            // The plan may have just been persisted and not yet exist in the DB —
            // flush first, else the counter UPDATE below matches zero rows.
            $this->entityManager->flush();

            $versionNumber = $this->nextVersionNumber($planId);
            if (null === $versionNumber) {
                return; // le plan a disparu (reset concurrent) — on laisse non lié
            }

            $schedule->setSchedulePlanId($planId);
            $schedule->setVersionNumber($versionNumber);
            $this->entityManager->flush();
        });
    }

    /**
     * ADR-0002 inv. 1 — VALIDER = POINTER: the plan names this version as its
     * chosen one. "Validé" is derived from this pointer; there is no status.
     * Raw SQL: filter-free (the plan's season may not be the active one) and the
     * plan is not loaded in the UnitOfWork on the validation path. RLS scopes club.
     */
    public function choose(Schedule $schedule): bool
    {
        $planId = $schedule->getSchedulePlanId();
        if (null === $planId) {
            // Self-heal rather than no-op: silently skipping would leave the plan
            // unpointed while the caller believes it validated (secondary plans
            // would stay locked with no visible cause). Every creation path links,
            // so this only catches rows that predate the link (or bypassed it).
            $this->linkSchedule($schedule);
            $planId = $schedule->getSchedulePlanId();
        }
        if (null === $planId) {
            // Aucun ancrage résoluble. Le rapporter plutôt que faire semblant :
            // l'appelant supprime les versions sœurs juste après, un no-op silencieux
            // les détruirait pour rien ET laisserait le plan non pointé en répondant 200.
            return false;
        }

        // Le nombre de lignes touchées est la VÉRITÉ : un schedulePlanId non-null peut
        // pendre dans le vide (plan supprimé sous ses pieds). Le self-heal ci-dessus ne
        // rattrape que le cas null, jamais celui-là. Rendre true sur zéro ligne ferait
        // croire à l'appelant que la validation a eu lieu — or il supprime les versions
        // sœurs juste après : on les détruirait pour un pointeur jamais posé.
        $updated = $this->entityManager->getConnection()->executeStatement(
            'UPDATE schedule_plan SET chosen_schedule_id = :sid, updated_at = now(), version = version + 1 WHERE id = :pid',
            ['sid' => $schedule->getId(), 'pid' => $planId],
        );

        return $updated > 0;
    }

    /**
     * ADR-0002 : le plan SEASON d'une saison — LE calendrier de base. Exposé par
     * /api/me. `chosenScheduleId` = la version choisie (« validée ») ;
     * `hasFinishedVersion` = le plan porte au moins une version terminée
     * (déblocage cockpit + mode guidé, inv. 8/16) ;
     * `currentStructureHash` = le payload solver de la structure actuelle, pour
     * griser « Régénérer » quand la version sélectionnée est déjà à l'identique.
     *
     * SQL brut, comme le reste des lectures de plan ici : filter-free (la saison
     * demandée n'est pas forcément l'active) et une seule requête. RLS scope par club.
     *
     * @return array{id: string, name: string, chosenScheduleId: string|null, hasFinishedVersion: bool, currentStructureHash: string|null}|null
     */
    public function seasonPlanPayload(string $seasonId): ?array
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT p.id, p.club_id, p.name, p.chosen_schedule_id, EXISTS ( '
            . 'SELECT 1 FROM schedule s WHERE s.schedule_plan_id = p.id '
            // Déverrouille le cockpit (inv. 8/16) : il faut une PREMIÈRE version
            // COMPLETED — décision fondateur. Un solve en échec ne donne aucun planning ;
            // envoyer le club au cockpit sur un FAILED l'y laisserait devant rien, alors
            // que sa place est dans le wizard, à corriger ses contraintes.
            // Indépendant du pointeur : avoir généré une fois suffit, choisir est un
            // autre geste — donc rouvrir ne re-verrouille jamais.
            . 'AND s.status = \'COMPLETED\') AS has_finished '
            . 'FROM schedule_plan p WHERE p.season_id = :sid AND p.type = \'SEASON\'',
            ['sid' => $seasonId],
        );

        if (false === $row) {
            return null; // saison sans plan SEASON (donnée antérieure au lot A)
        }

        return [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'chosenScheduleId' => null === $row['chosen_schedule_id'] ? null : (string) $row['chosen_schedule_id'],
            'hasFinishedVersion' => (bool) $row['has_finished'],
            'currentStructureHash' => $this->currentStructureHash((string) $row['club_id'], $seasonId),
        ];
    }

    /**
     * La version CHOISIE du plan SEASON d'une saison — LE calendrier de base
     * (ADR-0002). null = espace de travail : le gestionnaire n'a rien choisi.
     * C'est la seule vérité : « validé » se dérive de ce pointeur.
     */
    public function chosenOfSeasonPlan(?string $seasonId): ?string
    {
        if (null === $seasonId) {
            return null;
        }

        $chosen = $this->entityManager->getConnection()->fetchOne(
            'SELECT chosen_schedule_id FROM schedule_plan WHERE season_id = :sid AND type = \'SEASON\'',
            ['sid' => $seasonId],
        );

        return \is_string($chosen) ? $chosen : null;
    }

    /** Cette version est-elle celle que pointe son plan ? (= « validée ») */
    public function isChosen(string $scheduleId): bool
    {
        return (bool) $this->entityManager->getConnection()->fetchOne(
            'SELECT 1 FROM schedule_plan WHERE chosen_schedule_id = :sid',
            ['sid' => $scheduleId],
        );
    }

    /**
     * ADR-0002 inv. 10 : le plan d'une période meurt avec la période elle-même.
     * Appelé UNIQUEMENT depuis la suppression de la CalendarEntry — surtout pas
     * depuis deleteOverlayForEntry, que la purge des périodes échues appelle sur des
     * entries qui survivent (leur plan nommé, et son compteur, doivent survivre).
     */
    public function deletePeriodPlan(string $calendarEntryId): void
    {
        $this->entityManager->wrapInTransaction(function () use ($calendarEntryId): void {
            $this->lockPlanScope($calendarEntryId);
            $this->entityManager->getConnection()->executeStatement(
                'DELETE FROM schedule_plan WHERE calendar_entry_id = :eid',
                ['eid' => $calendarEntryId],
            );
        });
    }

    /**
     * Sérialise tout ce qui touche au plan d'un scope (une période, ou la saison).
     * Verrou de TRANSACTION : relâché au commit, ré-entrant (le reprendre plus bas
     * dans la même transaction est un no-op).
     *
     * À prendre AVANT toute lecture qui décide (balayer les versions, résoudre le
     * plan) : le prendre après ne sérialise rien — la lecture a déjà eu lieu.
     */
    public function lockPlanScope(string $scope): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'SELECT pg_advisory_xact_lock(hashtext(:scope))',
            ['scope' => 'schedule-plan-link:' . $scope],
        );
    }

    /**
     * A version is being removed OR reopened: if it is its plan's chosen version,
     * the plan loses its pointer and returns to "espace de travail" (inv. 2) — a
     * pointer must never name a deleted version. Raw SQL: filter-free, and the
     * plan is usually not in the UnitOfWork on these paths.
     */
    /**
     * Dépointe la version : le plan qui la nommait redevient un espace de travail.
     *
     * @return bool false si AUCUN plan ne la pointait — l'appelant ne doit alors pas
     *              annoncer une réouverture qui n'a pas eu lieu (une validation
     *              concurrente a pu déplacer le pointeur entre-temps)
     */
    public function releaseSchedule(string $scheduleId): bool
    {
        return $this->entityManager->getConnection()->executeStatement(
            'UPDATE schedule_plan SET chosen_schedule_id = NULL, updated_at = now(), version = version + 1 '
            . 'WHERE chosen_schedule_id = :sid',
            ['sid' => $scheduleId],
        ) > 0;
    }

    /**
     * ADR-0002 inv. 5 + décision fondateur 2026-07-17 — LE PLAN NAÎT DU GESTE.
     *
     * Naissance SEULE : créer le plan de la période s'il n'existe pas, sinon rendre le
     * sien. Ni synchronisation, ni suppression — l'identité d'une période qui porte un
     * plan est GELÉE en amont (422, CalendarEntryStateProcessor), donc il n'y a jamais
     * rien à re-synchroniser ni à détruire ici. Supprimer la période reste le seul
     * chemin destructeur (deleteEntryAndCascade, derrière confirmation).
     *
     * Rend null quand l'entrée ne porte pas de plan par conception — inv. 9 :
     * cutoff/mutualisation restent des rappels calendrier.
     *
     * Sérialisé par période avec le même verrou consultatif que linkSchedule : un POST
     * double-soumis ne peut pas minter deux plans (check-then-insert). L'appelant doit
     * avoir FLUSHÉ l'entrée — la ligne est relue en SQL brut. S'imbrique sans risque dans
     * la transaction de l'appelant (le verrou tient jusqu'au commit le plus externe), ce
     * dont dépend l'atomicité entrée + plan.
     */
    public function provisionPeriodPlan(string $calendarEntryId): ?string
    {
        return $this->entityManager->wrapInTransaction(function () use ($calendarEntryId): ?string {
            $this->lockPlanScope($calendarEntryId);

            return $this->ensurePeriodPlanId($calendarEntryId);
        });
    }

    /**
     * Marque le plan d'une période comme configuré, au 1er réglage d'équipes écrit — le
     * wizard s'en sert pour ne seeder son défaut « Fanion seul » qu'une fois, et jamais
     * après un retour « tout actif » (0 override épars). Idempotent (`= false` dans le
     * WHERE), et sans effet si la période ne porte pas de plan.
     *
     * SQL brut, comme toute résolution de plan ici : `season_filter` épingle les lectures
     * ORM à la saison ACTIVE de la requête, or l'appelant reçoit le calendarEntryId dans
     * un corps de requête, sans garantie qu'il appartienne à cette saison. RLS scope le club.
     */
    public function markPeriodTeamSelectionInitialized(string $calendarEntryId): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE schedule_plan SET team_selection_initialized = true, updated_at = now(), version = version + 1 '
            . 'WHERE calendar_entry_id = :eid AND team_selection_initialized = false',
            ['eid' => $calendarEntryId],
        );
    }

    /** Cette période porte-t-elle un plan ? Garde du 422 d'identité (voir plus haut). */
    public function periodPlanExists(string $calendarEntryId): bool
    {
        return null !== $this->findPeriodPlanId($calendarEntryId);
    }

    private function currentStructureHash(string $clubId, string $seasonId): ?string
    {
        try {
            $payload = $this->constraintBuilder->buildForClubSeason($clubId, $seasonId);

            return hash('sha256', json_encode($payload, \JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            return null;
        }
    }

    private function ensureSeasonPlanId(string $seasonId): ?string
    {
        $existing = $this->existingSeasonPlan($seasonId);
        if ($existing instanceof SchedulePlan) {
            return $existing->getId();
        }

        // Season owns no season_id column, so it is not season-filtered.
        $season = $this->entityManager->getRepository(Season::class)->find($seasonId);

        return $season instanceof Season ? $this->createSeasonPlan($season)->getId() : null;
    }

    /**
     * The season's SEASON plan if it already exists — persisted (raw SQL,
     * filter-free) OR still pending in the current unit of work (the raw-SQL
     * check cannot see an un-flushed INSERT, so a second provision in one UoW
     * would otherwise duplicate it and violate uniq_schedule_plan_season_base).
     */
    private function existingSeasonPlan(string $seasonId): ?SchedulePlan
    {
        $existingId = $this->findSeasonPlanId($seasonId);
        if (null !== $existingId) {
            // getReference, not find(): a season-filtered find() would return null
            // when the plan's season isn't the request's active one.
            $ref = $this->entityManager->getReference(SchedulePlan::class, $existingId);
            if ($ref instanceof SchedulePlan) {
                return $ref;
            }
        }

        foreach ($this->entityManager->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof SchedulePlan
                && SchedulePlanType::SEASON === $entity->getType()
                && $entity->getSeasonId() === $seasonId) {
                return $entity;
            }
        }

        return null;
    }

    private function createSeasonPlan(Season $season): SchedulePlan
    {
        $plan = (new SchedulePlan)
            ->setClubId($season->getClubId())
            ->setSeasonId($season->getId())
            ->setType(SchedulePlanType::SEASON)
            ->setName($this->seasonPlanName($season))
            ->setStartDate($season->getStartDate())
            ->setEndDate($season->getEndDate());
        $this->entityManager->persist($plan);

        return $plan;
    }

    private function ensurePeriodPlanId(string $calendarEntryId): ?string
    {
        $existingId = $this->findPeriodPlanId($calendarEntryId);
        if (null !== $existingId) {
            return $existingId;
        }

        // Read the entry filter-free: season_filter would hide a period whose
        // season is not the request's active one.
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT club_id, season_id, title, period_type, start_date, end_date FROM calendar_entry WHERE id = :id',
            ['id' => $calendarEntryId],
        );
        if (false === $row) {
            return null;
        }

        $type = self::periodPlanType(\is_string($row['period_type']) ? $row['period_type'] : null);
        if (null === $type) {
            return null;
        }

        $plan = (new SchedulePlan)
            ->setClubId((string) $row['club_id'])
            ->setSeasonId((string) $row['season_id'])
            ->setType($type)
            ->setName((string) $row['title'])
            ->setStartDate(new DateTimeImmutable((string) $row['start_date']))
            ->setEndDate(new DateTimeImmutable((string) $row['end_date']))
            ->setCalendarEntryId($calendarEntryId);
        $this->entityManager->persist($plan);

        return $plan->getId();
    }

    private function findSeasonPlanId(string $seasonId): ?string
    {
        $id = $this->entityManager->getConnection()->fetchOne(
            'SELECT id FROM schedule_plan WHERE season_id = :sid AND type = \'SEASON\'',
            ['sid' => $seasonId],
        );

        return false === $id ? null : (string) $id;
    }

    private function findPeriodPlanId(string $calendarEntryId): ?string
    {
        $id = $this->entityManager->getConnection()->fetchOne(
            'SELECT id FROM schedule_plan WHERE calendar_entry_id = :eid',
            ['eid' => $calendarEntryId],
        );

        return false === $id ? null : (string) $id;
    }

    /**
     * ADR-0002 lot B1: the plan owns a MONOTONIC counter. MAX(version_number)+1
     * would REUSE a number once validation deletes the non-chosen versions (a
     * deleted V3, regenerated, would be V3 again). The UPDATE ... RETURNING is
     * atomic on its own; raw SQL also dodges the ORM season_filter (the plan's
     * season may not be the request's active one). RLS still scopes by club.
     */
    /**
     * ADR-0002 : compteur MONOTONE porté par le plan. Un `MAX+1` réattribuerait le
     * numéro d'une version supprimée (une V3 supprimée puis régénérée redeviendrait
     * V3). `GREATEST(compteur, MAX(version_number))` garde la monotonie ET
     * s'auto-répare : si le compteur dérivait sous le MAX réel (dump antérieur au
     * seed, plan recréé à la main), un compteur nu rendrait un numéro déjà pris et
     * chaque génération de ce plan échouerait à jamais sur uniq_schedule_plan_version.
     * SQL brut : atomique, et il esquive le season_filter (la saison du plan n'est
     * pas forcément l'active). RLS scope toujours par club.
     *
     * @return int|null null si le plan n'existe pas/plus (course avec un reset de
     *                  saison) — le schedule reste alors simplement non lié
     */
    private function nextVersionNumber(string $schedulePlanId): ?int
    {
        $next = $this->entityManager->getConnection()->fetchOne(
            'UPDATE schedule_plan p SET last_version_number = GREATEST( '
            . 'p.last_version_number, '
            . 'COALESCE((SELECT MAX(s.version_number) FROM schedule s WHERE s.schedule_plan_id = p.id), 0) '
            . ') + 1 WHERE p.id = :pid RETURNING p.last_version_number',
            ['pid' => $schedulePlanId],
        );

        // Zéro ligne : le plan a disparu entre la résolution et ici. Ne pas lever —
        // ça fermerait l'EntityManager et transformerait une création qui marchait en
        // 500. Le lien reste null (nullable pendant la transition).
        return false === $next ? null : (int) $next;
    }

    private function seasonPlanName(Season $season): string
    {
        return 'Planning de la saison ' . $season->getName();
    }
}
