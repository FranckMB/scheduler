<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use App\ApiResource\TeamLinkResource;
use App\Entity\TeamLink;
use Symfony\Component\HttpFoundation\Request;

/**
 * @extends AbstractStateProvider<TeamLink, TeamLinkResource>
 */
class TeamLinkStateProvider extends AbstractStateProvider
{
    use ReadsUuidQueryParamTrait;

    protected function getEntityClass(): string
    {
        return TeamLink::class;
    }

    /**
     * @param TeamLink $entity
     */
    protected function mapEntityToOutput(object $entity): TeamLinkResource
    {
        return TeamLinkResource::fromEntity($entity);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<int, TeamLinkResource>
     */
    protected function provideCollection(Operation $operation, array $context, ?string $clubId): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(TeamLink::class, 'e');

        if (null !== $clubId) {
            $qb->andWhere('e.clubId = :clubId')->setParameter('clubId', $clubId);
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request instanceof Request) {
            $seasonId = $this->uuidQueryParam($request, 'seasonId');
            if (null !== $seasonId) {
                $qb->andWhere('e.seasonId = :seasonId')->setParameter('seasonId', $seasonId);
            }
            // A link is symmetric: ?teamId= matches EITHER side.
            $teamId = $this->uuidQueryParam($request, 'teamId');
            if (null !== $teamId) {
                $qb->andWhere('e.teamAId = :teamId OR e.teamBId = :teamId')->setParameter('teamId', $teamId);
            }
        }

        // UUID PK tiebreaker for stable offset pagination (VenueTrainingSlot idiom).
        $qb->orderBy('e.createdAt', 'ASC')->addOrderBy('e.id', 'ASC');

        if ($this->pagination->isEnabled($operation, $context)) {
            $offset = $this->pagination->getOffset($operation, $context);
            $limit = $this->pagination->getLimit($operation, $context);
            $qb->setFirstResult($offset)->setMaxResults($limit);
        }

        return array_map([$this, 'mapEntityToOutput'], $qb->getQuery()->getResult());
    }
}
