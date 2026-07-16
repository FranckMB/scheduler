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

/**
 * ADR-0002 Lot A — the SINGLE point that creates SchedulePlan rows and links a
 * Schedule to its plan/version. Reused at every season- and schedule-creation
 * site so the new model stays in sync additively (nothing legacy is touched).
 *
 * - ensureSeasonPlan(): the SEASON plan exists as soon as the season does (an
 *   empty "espace de travail" with no version yet).
 * - linkSchedule(): stamps schedulePlanId + versionNumber on a freshly-created
 *   schedule, creating the CLOSURE/HOLIDAY plan lazily at the period's first
 *   version. Idempotent-safe: a schedule already linked keeps its version.
 *
 * Existence/version lookups run as RAW SQL on purpose: the request-scoped
 * ORM season_filter pins every ORM read to the active season, but the
 * provisioner operates on the SCHEDULE's OWN season (overlays bind to their
 * period's season, which may differ). Raw SQL dodges that filter; RLS still
 * scopes every statement by club, and INSERTs (persist) are never filtered.
 *
 * chosenScheduleId is NOT set here (Lot A) — it is backfilled at migration and
 * becomes live only when Lot B moves validation onto the plan pointer.
 */
final class SchedulePlanProvisioner
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

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
     * Keep the SEASON plan's derived fields (name, period) in sync when the
     * season is edited — during the additive phase the plan mirrors the season
     * (planningName + dates), so the now-exposed /api/schedule_plans must not go
     * stale. Raw SQL: filter-free (the edited season may not be the active one)
     * and it never touches a version-locked ORM-managed plan mid-request.
     */
    public function syncSeasonPlan(Season $season): void
    {
        $this->entityManager->getConnection()->executeStatement(
            // version = version + 1: this raw UPDATE mutates the row out of band,
            // so it must bump the optimistic-lock column like an ORM update would
            // — otherwise a stale ORM copy could later flush a lost update.
            'UPDATE schedule_plan SET name = :name, start_date = :start, end_date = :end, updated_at = now(), version = version + 1 '
            . 'WHERE season_id = :sid AND type = \'SEASON\'',
            [
                'name' => $this->seasonPlanName($season),
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

            $planId = null === $schedule->getCalendarEntryId()
                ? $this->ensureSeasonPlanId($schedule->getSeasonId())
                : $this->ensurePeriodPlanId($schedule->getCalendarEntryId());
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
    public function choose(Schedule $schedule): void
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
            return; // aucun ancrage résoluble — transition-safe (rien ne lit le pointeur)
        }

        $this->entityManager->getConnection()->executeStatement(
            'UPDATE schedule_plan SET chosen_schedule_id = :sid, updated_at = now(), version = version + 1 WHERE id = :pid',
            ['sid' => $schedule->getId(), 'pid' => $planId],
        );
    }

    /**
     * ADR-0002 : le plan SEASON d'une saison — LE calendrier de base. Exposé par
     * /api/me. `chosenScheduleId` = la version choisie (« validée ») ;
     * `hasFinishedVersion` = le plan porte au moins une version terminée
     * (déblocage cockpit + mode guidé, inv. 8/16).
     *
     * SQL brut, comme le reste des lectures de plan ici : filter-free (la saison
     * demandée n'est pas forcément l'active) et une seule requête. RLS scope par club.
     *
     * @return array{id: string, name: string, chosenScheduleId: string|null, hasFinishedVersion: bool}|null
     */
    public function seasonPlanPayload(string $seasonId): ?array
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT p.id, p.name, p.chosen_schedule_id, EXISTS ( '
            . 'SELECT 1 FROM schedule s WHERE s.schedule_plan_id = p.id '
            // Une version « terminée » = le solveur a rendu sa réponse. Les statuts
            // legacy VALIDATED/ARCHIVED en font partie tant que la bascule n'a pas eu
            // lieu : les omettre ferait repasser le flag à false pile à la validation
            // (la version choisie porte le miroir VALIDATED).
            . 'AND s.status IN (\'COMPLETED\', \'FAILED\', \'VALIDATED\', \'ARCHIVED\')) AS has_finished '
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
        ];
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
    public function releaseSchedule(string $scheduleId): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE schedule_plan SET chosen_schedule_id = NULL, updated_at = now(), version = version + 1 '
            . 'WHERE chosen_schedule_id = :sid',
            ['sid' => $scheduleId],
        );
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

        $type = match ($row['period_type']) {
            'closure' => SchedulePlanType::CLOSURE,
            'holiday' => SchedulePlanType::HOLIDAY,
            default => null,
        };
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
        $custom = $season->getPlanningName();
        if (null !== $custom && '' !== trim($custom)) {
            return $custom;
        }

        return 'Planning de la saison ' . $season->getName();
    }
}
