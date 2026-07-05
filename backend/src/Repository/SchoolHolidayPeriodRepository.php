<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SchoolHolidayPeriod;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SchoolHolidayPeriod>
 */
final class SchoolHolidayPeriodRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SchoolHolidayPeriod::class);
    }

    public function findOneByNaturalKey(string $zone, string $holidayType, string $schoolYear): ?SchoolHolidayPeriod
    {
        return $this->findOneBy([
            'zone' => $zone,
            'holidayType' => $holidayType,
            'schoolYear' => $schoolYear,
        ]);
    }

    /**
     * Holidays of a zone overlapping the [from, to] window, chronological.
     *
     * @return list<SchoolHolidayPeriod>
     */
    public function findByZoneAndWindow(string $zone, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('h')
            ->andWhere('h.zone = :zone')
            ->andWhere('h.endDate >= :from')
            ->andWhere('h.startDate <= :to')
            ->setParameter('zone', $zone)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->orderBy('h.startDate', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
