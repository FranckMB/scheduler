<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\{VenueClosure};
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VenueClosure>
 */
final class VenueClosureRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VenueClosure::class);
    }
}