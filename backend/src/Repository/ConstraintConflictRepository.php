<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ConstraintConflict;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ConstraintConflict>
 */
final class ConstraintConflictRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConstraintConflict::class);
    }

    /**
     * @return list<ConstraintConflict>
     */
    public function findBySchedule(string $scheduleId): array
    {
        return $this->createQueryBuilder('cc')
            ->andWhere('cc.scheduleId = :scheduleId')
            ->setParameter('scheduleId', $scheduleId)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<ConstraintConflict>
     */
    public function findUnresolvedBySchedule(string $scheduleId): array
    {
        return $this->createQueryBuilder('cc')
            ->andWhere('cc.scheduleId = :scheduleId')
            ->andWhere('cc.isResolved = false')
            ->setParameter('scheduleId', $scheduleId)
            ->getQuery()
            ->getResult();
    }
}
