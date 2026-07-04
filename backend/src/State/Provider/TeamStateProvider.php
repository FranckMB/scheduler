<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\TeamResource;
use App\Entity\Team;
use Doctrine\ORM\QueryBuilder;

/**
 * @extends AbstractStateProvider<Team, TeamResource>
 */
class TeamStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return Team::class;
    }

    /**
     * Honors the ?seasonId= and ?isActive= query params documented by the
     * #[ApiFilter] attributes on TeamResource (the custom provider bypasses API
     * Platform's built-in Doctrine filters, so they are applied here — BCK-05).
     * These narrow the set but do not bound it to a single parent → return false
     * so the default pagination still applies.
     */
    protected function applyRequestFilters(QueryBuilder $qb): bool
    {
        $request = $this->requestStack->getCurrentRequest();

        $seasonId = $request?->query->get('seasonId');
        if (\is_string($seasonId) && '' !== $seasonId) {
            $qb->andWhere('e.seasonId = :seasonId')->setParameter('seasonId', $seasonId);
        }

        $isActive = $request?->query->get('isActive');
        if (\is_string($isActive) && '' !== $isActive) {
            $qb->andWhere('e.isActive = :isActive')
               ->setParameter('isActive', filter_var($isActive, \FILTER_VALIDATE_BOOL));
        }

        return false;
    }

    /**
     * @param Team $entity
     */
    protected function mapEntityToOutput(object $entity): TeamResource
    {
        return TeamResource::fromEntity($entity);
    }
}
