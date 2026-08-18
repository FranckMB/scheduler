<?php

declare(strict_types=1);

namespace App\Deletion;

use App\Entity\SharedTrainingGroup;
use App\Entity\SharedTrainingGroupTeam;
use Doctrine\ORM\EntityManagerInterface;

/**
 * P2-27 — la mutualisation : l'équipe quitte ses groupes, et un groupe qui tombe **sous deux
 * membres** n'a plus de sens (« N équipes s'entraînent ensemble » n'existe pas à un) : il part
 * avec ses lignes restantes.
 *
 * Le COMPTE annoncé est le nombre de groupes où l'équipe figure — la seule chose que le
 * gestionnaire a lui-même déclarée. Qu'un groupe survive ou tombe se décide au moment de
 * l'exécution ; l'annoncer par avance demanderait de rejouer la règle de survie dans le
 * compteur, donc de la tenir à deux endroits — exactement ce que P3-16 corrige.
 */
final readonly class SharedTrainingGroupPruneStep implements CascadeStep
{
    public function __construct(private ImpactLabel $label) {}

    public function label(): ImpactLabel
    {
        return $this->label;
    }

    public function count(EntityManagerInterface $entityManager, DeletionTarget $target): int
    {
        return (int) $entityManager->createQueryBuilder()
            ->select('COUNT(DISTINCT t.groupId)')
            ->from(SharedTrainingGroupTeam::class, 't')
            ->where('t.teamId = :teamId')
            ->andWhere('t.clubId = :clubId')
            ->andWhere('t.seasonId = :seasonId')
            ->setParameter('teamId', $target->id)
            ->setParameter('clubId', $target->clubId)
            ->setParameter('seasonId', $target->seasonId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function execute(EntityManagerInterface $entityManager, DeletionTarget $target): void
    {
        /** @var list<string> $affectedGroupIds */
        $affectedGroupIds = $entityManager->createQueryBuilder()
            ->select('DISTINCT t.groupId')
            ->from(SharedTrainingGroupTeam::class, 't')
            ->where('t.teamId = :teamId')
            ->andWhere('t.clubId = :clubId')
            ->andWhere('t.seasonId = :seasonId')
            ->setParameter('teamId', $target->id)
            ->setParameter('clubId', $target->clubId)
            ->setParameter('seasonId', $target->seasonId)
            ->getQuery()
            ->getSingleColumnResult();

        if ([] === $affectedGroupIds) {
            return;
        }

        new DeleteByFieldStep(SharedTrainingGroupTeam::class, 'teamId', null)->execute($entityManager, $target);

        foreach ($affectedGroupIds as $groupId) {
            $remaining = (int) $entityManager->createQueryBuilder()
                ->select('COUNT(t.id)')
                ->from(SharedTrainingGroupTeam::class, 't')
                ->where('t.groupId = :groupId')
                ->setParameter('groupId', $groupId)
                ->getQuery()
                ->getSingleScalarResult();
            if ($remaining >= 2) {
                continue;
            }
            $entityManager->createQueryBuilder()
                ->delete(SharedTrainingGroupTeam::class, 't')
                ->where('t.groupId = :groupId')
                ->setParameter('groupId', $groupId)
                ->getQuery()
                ->execute();
            $entityManager->createQueryBuilder()
                ->delete(SharedTrainingGroup::class, 'g')
                ->where('g.id = :groupId')
                ->setParameter('groupId', $groupId)
                ->getQuery()
                ->execute();
        }
    }
}
