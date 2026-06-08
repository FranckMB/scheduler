<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\{Coach};
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Coach>
 */
final class CoachRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Coach::class);
    }
}