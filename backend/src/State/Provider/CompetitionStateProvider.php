<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\CompetitionResource;
use App\Entity\Competition;
use Doctrine\ORM\QueryBuilder;

/**
 * @extends AbstractStateProvider<Competition, CompetitionResource>
 */
class CompetitionStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return Competition::class;
    }

    /** Custom provider bypasses the Doctrine SearchFilter → apply by hand. */
    protected function applyRequestFilters(QueryBuilder $qb): bool
    {
        $request = $this->requestStack->getCurrentRequest();
        foreach (['seasonId', 'teamId'] as $field) {
            $value = $request?->query->get($field);
            if (\is_string($value) && '' !== $value) {
                $qb->andWhere(\sprintf('e.%s = :%s', $field, $field))->setParameter($field, $value);
            }
        }

        return false;
    }

    /**
     * @param Competition $entity
     */
    protected function mapEntityToOutput(object $entity): CompetitionResource
    {
        return CompetitionResource::fromEntity($entity);
    }
}
