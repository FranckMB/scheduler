<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use App\ApiResource\TeamMatchHabitResource;
use App\Entity\TeamMatchHabit;
use Symfony\Component\HttpFoundation\Request;

/**
 * @extends AbstractStateProvider<TeamMatchHabit, TeamMatchHabitResource>
 */
class TeamMatchHabitStateProvider extends AbstractStateProvider
{
    use ReadsUuidQueryParamTrait;

    protected function getEntityClass(): string
    {
        return TeamMatchHabit::class;
    }

    /**
     * @param TeamMatchHabit $entity
     */
    protected function mapEntityToOutput(object $entity): TeamMatchHabitResource
    {
        return TeamMatchHabitResource::fromEntity($entity);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<int, TeamMatchHabitResource>
     */
    protected function provideCollection(Operation $operation, array $context, ?string $clubId): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(TeamMatchHabit::class, 'e');

        if (null !== $clubId) {
            $qb->andWhere('e.clubId = :clubId')->setParameter('clubId', $clubId);
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request instanceof Request) {
            $teamId = $this->uuidQueryParam($request, 'teamId');
            if (null !== $teamId) {
                $qb->andWhere('e.teamId = :teamId')->setParameter('teamId', $teamId);
            }

            $seasonId = $this->uuidQueryParam($request, 'seasonId');
            if (null !== $seasonId) {
                $qb->andWhere('e.seasonId = :seasonId')->setParameter('seasonId', $seasonId);
            }
        }

        // UUID PK tiebreaker for stable offset pagination (VenueTrainingSlot idiom).
        $qb->orderBy('e.dayOfWeek', 'ASC')->addOrderBy('e.kickoffTime', 'ASC')->addOrderBy('e.id', 'ASC');

        if ($this->pagination->isEnabled($operation, $context)) {
            $offset = $this->pagination->getOffset($operation, $context);
            $limit = $this->pagination->getLimit($operation, $context);
            $qb->setFirstResult($offset)->setMaxResults($limit);
        }

        return array_map([$this, 'mapEntityToOutput'], $qb->getQuery()->getResult());
    }
}
