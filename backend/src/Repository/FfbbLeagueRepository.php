<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FfbbLeague;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FfbbLeague>
 */
final class FfbbLeagueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FfbbLeague::class);
    }

    public function findByCode(string $code): ?FfbbLeague
    {
        return $this->findOneBy(['code' => $code]);
    }
}
