<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\VenueConstraint;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VenueConstraint>
 */
final class VenueConstraintRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VenueConstraint::class);
    }
}
