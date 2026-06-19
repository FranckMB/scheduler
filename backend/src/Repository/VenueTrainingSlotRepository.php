<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\VenueTrainingSlot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VenueTrainingSlot>
 */
final class VenueTrainingSlotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VenueTrainingSlot::class);
    }
}
