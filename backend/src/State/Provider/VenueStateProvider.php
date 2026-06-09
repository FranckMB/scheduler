<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\VenueResource;
use App\Entity\Venue;

/**
 * @extends AbstractStateProvider<Venue, VenueResource>
 */
class VenueStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return Venue::class;
    }

    /**
     * @param Venue $entity
     */
    protected function mapEntityToOutput(object $entity): VenueResource
    {
        return VenueResource::fromEntity($entity);
    }
}
