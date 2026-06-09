<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PriorityTier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PriorityTier>
 */
final class PriorityTierRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PriorityTier::class);
    }
}
