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
     * Active period entries of the season that still have NO overlay plan and
     * start exactly on $startDate — the reminder cron's J-14/J-7/J-3 targets.
     * Only kind=PERIOD, status=ACTIVE carry an overlay; an event or a
     * proposed/ignored entry is never reminded.
     *
     * @return list<CalendarEntry>
     */
    public function findPeriodsWithoutOverlayStartingOn(string $clubId, string $seasonId, DateTimeImmutable $startDate): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.clubId = :clubId')
            ->andWhere('e.seasonId = :seasonId')
            ->andWhere('e.kind = :kind')
            ->andWhere('e.status = :status')
            ->andWhere('e.overlayScheduleId IS NULL')
            ->andWhere('e.startDate = :startDate')
            ->setParameter('clubId', $clubId)
            ->setParameter('seasonId', $seasonId)
            ->setParameter('kind', \App\Enum\CalendarEntryKind::PERIOD)
            ->setParameter('status', \App\Enum\CalendarEntryStatus::ACTIVE)
            ->setParameter('startDate', $startDate->format('Y-m-d'))
            ->orderBy('e.startDate', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
