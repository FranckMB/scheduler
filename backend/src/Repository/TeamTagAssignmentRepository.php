<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TeamTagAssignment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TeamTagAssignment>
 */
final class TeamTagAssignmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TeamTagAssignment::class);
    }
}
