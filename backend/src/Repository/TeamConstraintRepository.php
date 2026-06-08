<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\{TeamConstraint};
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TeamConstraint>
 */
final class TeamConstraintRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TeamConstraint::class);
    }
}