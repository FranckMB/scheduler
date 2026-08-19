<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use App\ApiResource\SharedTrainingGroupResource;
use App\Entity\SharedTrainingGroup;
use App\Entity\SharedTrainingGroupTeam;
use Symfony\Component\HttpFoundation\Request;

/**
 * @extends AbstractStateProvider<SharedTrainingGroup, SharedTrainingGroupResource>
 */
class SharedTrainingGroupStateProvider extends AbstractStateProvider
{
    use ReadsUuidQueryParamTrait;

    protected function getEntityClass(): string
    {
        return SharedTrainingGroup::class;
    }

    /**
     * @param SharedTrainingGroup $entity
     */
    protected function mapEntityToOutput(object $entity): SharedTrainingGroupResource
    {
        return SharedTrainingGroupResource::fromEntity($entity, $this->teamIdsOf($entity->getId()));
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<int, SharedTrainingGroupResource>
     */
    protected function provideCollection(Operation $operation, array $context, ?string $clubId): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(SharedTrainingGroup::class, 'e');

        if (null !== $clubId) {
            $qb->andWhere('e.clubId = :clubId')->setParameter('clubId', $clubId);
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request instanceof Request) {
            // Un filtre plan cible une période ; absence = socle ET périodes (le front trie).
            $schedulePlanId = $this->uuidQueryParam($request, 'schedulePlanId');
            if (null !== $schedulePlanId) {
                $qb->andWhere('e.schedulePlanId = :schedulePlanId')->setParameter('schedulePlanId', $schedulePlanId);
            }
        }

        $qb->orderBy('e.id', 'ASC');

        /** @var list<SharedTrainingGroup> $groups */
        $groups = $qb->getQuery()->getResult();

        // AUD-BCK-16 — les membres des groupes en UNE requête, pas une par groupe. Le mapper
        // unitaire (item) garde son chemin direct ; ici la collection les charge en lot, sinon
        // le nombre de requêtes suit le nombre de groupes du club. Volume borné par le métier
        // aujourd'hui — mais un N+1 qu'on laisse est un N+1 qu'on découvre en prod le jour où
        // la mutualisation prend.
        $teamIdsByGroup = $this->teamIdsOfMany(array_map(static fn (SharedTrainingGroup $g): string => $g->getId(), $groups));

        return array_map(
            static fn (SharedTrainingGroup $group): SharedTrainingGroupResource => SharedTrainingGroupResource::fromEntity(
                $group,
                $teamIdsByGroup[$group->getId()] ?? [],
            ),
            $groups,
        );
    }

    /**
     * Les ids d'équipe de PLUSIEURS groupes, en une requête (AUD-BCK-16).
     *
     * Même tri que {@see teamIdsOf} — `teamId` croissant — pour que la collection et l'item
     * rendent EXACTEMENT la même liste : deux ordres différents pour un même groupe seraient
     * un écart invisible au test unitaire et visible à l'écran.
     *
     * @param list<string> $groupIds
     *
     * @return array<string, list<string>>
     */
    private function teamIdsOfMany(array $groupIds): array
    {
        if ([] === $groupIds) {
            return [];
        }

        /** @var list<array{groupId: string, teamId: string}> $rows */
        $rows = $this->entityManager->getRepository(SharedTrainingGroupTeam::class)
            ->createQueryBuilder('t')
            ->select('t.groupId', 't.teamId')
            ->where('t.groupId IN (:groupIds)')
            ->setParameter('groupIds', $groupIds)
            ->orderBy('t.teamId', 'ASC')
            ->getQuery()
            ->getScalarResult();

        $byGroup = [];
        foreach ($rows as $row) {
            $byGroup[$row['groupId']][] = $row['teamId'];
        }

        return $byGroup;
    }

    /**
     * Les ids d'équipe du groupe, triés (déterminisme : l'id d'une ligne membre est un UUID v4).
     *
     * @return list<string>
     */
    private function teamIdsOf(string $groupId): array
    {
        /** @var list<array{teamId: string}> $rows */
        $rows = $this->entityManager->getRepository(SharedTrainingGroupTeam::class)
            ->createQueryBuilder('t')
            ->select('t.teamId')
            ->where('t.groupId = :groupId')
            ->setParameter('groupId', $groupId)
            ->orderBy('t.teamId', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row): string => $row['teamId'], $rows);
    }
}
