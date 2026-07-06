<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\FixtureResource;
use App\Entity\Fixture;
use Doctrine\ORM\QueryBuilder;

/**
 * @extends AbstractStateProvider<Fixture, FixtureResource>
 */
class FixtureStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return Fixture::class;
    }

    /**
     * A custom provider bypasses API Platform's Doctrine SearchFilter, so the
     * declared filters are applied by hand (same as TeamStateProvider). Returns
     * false: partial filters, the result stays paginated.
     */
    protected function applyRequestFilters(QueryBuilder $qb): bool
    {
        $request = $this->requestStack->getCurrentRequest();
        foreach (['seasonId', 'teamId', 'competitionId', 'homeAway', 'status'] as $field) {
            $value = $request?->query->get($field);
            if (\is_string($value) && '' !== $value) {
                $qb->andWhere(\sprintf('e.%s = :%s', $field, $field))->setParameter($field, $value);
            }
        }

        return false;
    }

    /**
     * @param Fixture $entity
     */
    protected function mapEntityToOutput(object $entity): FixtureResource
    {
        return FixtureResource::fromEntity($entity);
    }
}
