<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use App\ApiResource\TeamPeriodOverrideResource;
use App\Entity\TeamPeriodOverride;
use Symfony\Component\HttpFoundation\Request;

/**
 * @extends AbstractStateProvider<TeamPeriodOverride, TeamPeriodOverrideResource>
 */
class TeamPeriodOverrideStateProvider extends AbstractStateProvider
{
    use ReadsUuidQueryParamTrait;

    protected function getEntityClass(): string
    {
        return TeamPeriodOverride::class;
    }

    /**
     * @param TeamPeriodOverride $entity
     */
    protected function mapEntityToOutput(object $entity): TeamPeriodOverrideResource
    {
        return TeamPeriodOverrideResource::fromEntity($entity);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<int, TeamPeriodOverrideResource>
     */
    protected function provideCollection(Operation $operation, array $context, ?string $clubId): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(TeamPeriodOverride::class, 'e');

        if (null !== $clubId) {
            $qb->andWhere('e.clubId = :clubId')->setParameter('clubId', $clubId);
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request instanceof Request) {
            // Overrides are always consulted per period; a teamId narrows further.
            $schedulePlanId = $this->uuidQueryParam($request, 'schedulePlanId');
            if (null !== $schedulePlanId) {
                $qb->andWhere('e.schedulePlanId = :schedulePlanId')->setParameter('schedulePlanId', $schedulePlanId);
            }

            $teamId = $this->uuidQueryParam($request, 'teamId');
            if (null !== $teamId) {
                $qb->andWhere('e.teamId = :teamId')->setParameter('teamId', $teamId);
            }
        }

        $qb->orderBy('e.id', 'ASC');

        return array_map([$this, 'mapEntityToOutput'], $qb->getQuery()->getResult());
    }
}
