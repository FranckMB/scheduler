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
            $scope = $schedule->getCalendarEntryId() ?? ('season:' . $schedule->getSeasonId());
            $this->entityManager->getConnection()->executeStatement(
                'SELECT pg_advisory_xact_lock(hashtext(:scope))',
                ['scope' => 'schedule-plan-link:' . $scope],
            );

            $planId = null === $schedule->getCalendarEntryId()
                ? $this->ensureSeasonPlanId($schedule->getSeasonId())
                : $this->ensurePeriodPlanId($schedule->getCalendarEntryId());
            if (null === $planId) {
                return;
            }

            $schedule->setSchedulePlanId($planId);
            $schedule->setVersionNumber($this->nextVersionNumber($planId));
            $this->entityManager->flush();
        });
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

    private function nextVersionNumber(string $schedulePlanId): int
    {
        $max = $this->entityManager->getConnection()->fetchOne(
            'SELECT MAX(version_number) FROM schedule WHERE schedule_plan_id = :pid',
            ['pid' => $schedulePlanId],
        );

        return (int) $max + 1;
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
