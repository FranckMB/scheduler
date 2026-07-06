<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Season;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Season>
 */
final class SeasonRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Season::class);
    }

    /**
     * All seasons of a club, oldest first. Season resolution (which one is
     * current) lives in SeasonResolver — never key on `status` here.
     *
     * @return list<Season>
     */
    public function findAllByClubId(string $clubId): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.clubId = :clubId')
            ->setParameter('clubId', $clubId)
            ->orderBy('s.startDate', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
