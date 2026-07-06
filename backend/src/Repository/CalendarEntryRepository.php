<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CalendarEntry;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CalendarEntry>
 */
final class CalendarEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CalendarEntry::class);
    }

    /**
     * @return list<CalendarEntry>
     */
    public function findByClubSeason(string $clubId, string $seasonId): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.clubId = :clubId')
            ->andWhere('e.seasonId = :seasonId')
            ->setParameter('clubId', $clubId)
            ->setParameter('seasonId', $seasonId)
            ->getQuery()
            ->getResult();
    }

    /**
     * Period entries of the season that carry a generated overlay (palier B) —
     * the ones a baseline reopen would destroy.
     *
     * @return list<CalendarEntry>
     */
    public function findWithOverlayByClubSeason(string $clubId, string $seasonId): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.clubId = :clubId')
            ->andWhere('e.seasonId = :seasonId')
            ->andWhere('e.overlayScheduleId IS NOT NULL')
            ->setParameter('clubId', $clubId)
            ->setParameter('seasonId', $seasonId)
            ->getQuery()
            ->getResult();
    }

    public function findOneByOverlayScheduleId(string $scheduleId): ?CalendarEntry
    {
        return $this->findOneBy(['overlayScheduleId' => $scheduleId]);
    }

    /**
     * Active period entries of the current tenant scope, ordered deterministically
     * (startDate, then id) so a date covered by two periods always resolves to the
     * same one. Relies on the ambient club+season Doctrine filters for scoping.
     * A period "captures" the dates it covers: within it the base plan does not
     * apply — its overlay if any, else no training plan at all (a closure means
     * "no training", cf. findUpcomingPeriodsWithoutOverlay).
     *
     * @return list<CalendarEntry>
     */
    public function findActivePeriodsOrdered(): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.kind = :kind')
            ->andWhere('e.status = :status')
            ->setParameter('kind', \App\Enum\CalendarEntryKind::PERIOD)
            ->setParameter('status', \App\Enum\CalendarEntryStatus::ACTIVE)
            ->orderBy('e.startDate', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Active period entries of the season that still have NO overlay plan and
     * start within [$today, $today + $horizonDays] — the reminder cron's horizon.
     * A window (not an exact date) so a missed daily run still catches the period
     * on the next run; the cron then picks the milestone bucket + dedups per
     * (entry, threshold). Only kind=PERIOD, status=ACTIVE carry an overlay.
     *
     * @return list<CalendarEntry>
     */
    public function findUpcomingPeriodsWithoutOverlay(string $clubId, string $seasonId, DateTimeImmutable $today, int $horizonDays): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.clubId = :clubId')
            ->andWhere('e.seasonId = :seasonId')
            ->andWhere('e.kind = :kind')
            ->andWhere('e.status = :status')
            ->andWhere('e.overlayScheduleId IS NULL')
            // Only overlay-capable period types: reminding about a cutoff/custom/
            // mutualisation period would CTA into a 422 (overlay creation refuses
            // them) — a cutoff means "no training", there is no plan to prepare.
            ->andWhere('e.periodType IN (:generatingTypes)')
            ->andWhere('e.startDate >= :from')
            ->andWhere('e.startDate <= :to')
            ->setParameter('clubId', $clubId)
            ->setParameter('seasonId', $seasonId)
            ->setParameter('kind', \App\Enum\CalendarEntryKind::PERIOD)
            ->setParameter('status', \App\Enum\CalendarEntryStatus::ACTIVE)
            ->setParameter('generatingTypes', [\App\Enum\CalendarEntryPeriodType::CLOSURE, \App\Enum\CalendarEntryPeriodType::HOLIDAY])
            ->setParameter('from', $today->format('Y-m-d'))
            ->setParameter('to', $today->modify(\sprintf('+%d days', $horizonDays))->format('Y-m-d'))
            ->orderBy('e.startDate', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
