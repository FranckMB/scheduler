<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CalendarEntry;
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
}
