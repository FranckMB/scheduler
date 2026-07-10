<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FfbbCommittee;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FfbbCommittee>
 */
final class FfbbCommitteeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FfbbCommittee::class);
    }

    public function findByCode(string $code): ?FfbbCommittee
    {
        return $this->findOneBy(['code' => $code]);
    }
}
