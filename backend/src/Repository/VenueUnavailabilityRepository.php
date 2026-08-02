<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\VenueUnavailability;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VenueUnavailability>
 */
final class VenueUnavailabilityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VenueUnavailability::class);
    }
}
