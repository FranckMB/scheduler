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

        return array_map([$this, 'mapEntityToOutput'], $qb->getQuery()->getResult());
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
