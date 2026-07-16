<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SolverMetric;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SolverMetric> */
final class SolverMetricRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SolverMetric::class);
    }
}
