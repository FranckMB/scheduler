<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PublicHoliday;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PublicHoliday>
 */
final class PublicHolidayRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PublicHoliday::class);
    }

    public function findOneByNaturalKey(string $zone, DateTimeImmutable $date): ?PublicHoliday
    {
        return $this->findOneBy(['zone' => $zone, 'date' => $date]);
    }

    /**
     * National holidays UNION the given zone's territory-specific ones, within
     * the [from, to] window, chronological. A null zone still returns NATIONAL
     * (fériés nationaux apply to every club).
     *
     * @return list<PublicHoliday>
     */
    public function findNationalAndZoneInWindow(?string $zone, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $qb = $this->createQueryBuilder('h')
            ->andWhere('h.date >= :from')
            ->andWhere('h.date <= :to')
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->orderBy('h.date', 'ASC');

        if (null === $zone || '' === $zone || PublicHoliday::NATIONAL === $zone) {
            $qb->andWhere('h.zone = :national')->setParameter('national', PublicHoliday::NATIONAL);
        } else {
            $qb->andWhere('h.zone IN (:zones)')->setParameter('zones', [PublicHoliday::NATIONAL, $zone]);
        }

        return $qb->getQuery()->getResult();
    }
}
