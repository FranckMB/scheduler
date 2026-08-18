<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\VenueResource;
use App\Entity\Venue;
use Doctrine\ORM\QueryBuilder;

/**
 * @extends AbstractStateProvider<Venue, VenueResource>
 */
class VenueStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return Venue::class;
    }

    protected function applyRequestFilters(QueryBuilder $qb): bool
    {
        // Every screen inherits this order verbatim (wizard list, venue selects,
        // planning grid columns) — the collection is the one place it can live.
        // HIDDEN keeps the result shape (entities only); LOWER() folds case so
        // "alpha" does not land after "Zola" under Postgres' C-locale-ish sort.
        $qb->addSelect('LOWER(e.name) AS HIDDEN venue_sort_name')->orderBy('venue_sort_name', 'ASC');

        return false;
    }

    /**
     * @param Venue $entity
     */
    protected function mapEntityToOutput(object $entity): VenueResource
    {
        return VenueResource::fromEntity($entity);
    }
}
