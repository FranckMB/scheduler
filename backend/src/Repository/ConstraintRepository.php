<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Constraint;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Constraint>
 */
final class ConstraintRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Constraint::class);
    }

    /**
     * @return list<Constraint>
     */
    public function findByClubSeason(string $clubId, string $seasonId): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.clubId = :clubId')
            ->andWhere('c.seasonId = :seasonId')
            ->setParameter('clubId', $clubId)
            ->setParameter('seasonId', $seasonId)
            ->getQuery()
            ->getResult();
    }

    /**
     * Permanent (non-dated) constraints only — the base plan. Dated constraints
     * (calendarEntryId set) belong to a CalendarEntry period and must NOT feed
     * base-plan generation. See accueil-cockpit-temporel.md §9ter.c.
     *
     * @return list<Constraint>
     */
    public function findPermanentByClubSeason(string $clubId, string $seasonId): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.clubId = :clubId')
            ->andWhere('c.seasonId = :seasonId')
            ->andWhere('c.calendarEntryId IS NULL')
            ->setParameter('clubId', $clubId)
            ->setParameter('seasonId', $seasonId)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Constraint>
     */
    public function findByScope(string $scope, string $targetId): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.scope = :scope')
            ->andWhere('c.scopeTargetId = :targetId')
            ->setParameter('scope', $scope)
            ->setParameter('targetId', $targetId)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Constraint>
     */
    public function findActiveByClub(string $clubId): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.clubId = :clubId')
            ->andWhere('c.isActive = true')
            ->setParameter('clubId', $clubId)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Constraint>
     */
    public function findByFamily(string $family): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.family = :family')
            ->setParameter('family', $family)
            ->getQuery()
            ->getResult();
    }
}
