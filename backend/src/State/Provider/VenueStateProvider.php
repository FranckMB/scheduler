<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\VenueResource;
use App\Entity\Venue;

class VenueStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return Venue::class;
    }

    protected function mapEntityToOutput(object $entity): VenueResource
    {
        return VenueResource::fromEntity($entity);
    }
}
