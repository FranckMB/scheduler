<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Schedule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Schedule>
 */
final class ScheduleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Schedule::class);
    }

    /**
     * Lesquels de ces plans portent AU MOINS UNE version — une seule requête pour
     * toute la collection (P4-23 : le client tirait tous les schedules du club pour
     * répondre à un booléen). Les filtres tenant/saison s'appliquent comme partout :
     * un plan d'un autre club n'a par construction aucune ligne visible ici.
     *
     * @param list<string> $planIds
     *
     * @return array<string, true> planId → true, pour les plans qui ONT une version
     */
    public function planIdsWithVersions(array $planIds): array
    {
        if ([] === $planIds) {
            return [];
        }

        /** @var list<array{schedulePlanId: string}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('DISTINCT s.schedulePlanId AS schedulePlanId')
            ->andWhere('s.schedulePlanId IN (:ids)')
            ->setParameter('ids', $planIds)
            ->getQuery()
            ->getArrayResult();

        $found = [];
        foreach ($rows as $row) {
            $found[$row['schedulePlanId']] = true;
        }

        return $found;
    }
}
