<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CoachUnavailability;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CoachUnavailability>
 */
final class CoachUnavailabilityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CoachUnavailability::class);
    }
}
