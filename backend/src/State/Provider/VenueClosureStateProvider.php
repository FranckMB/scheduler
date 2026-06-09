<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\VenueClosureResource;
use App\Entity\VenueClosure;

class VenueClosureStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return VenueClosure::class;
    }

    protected function mapEntityToOutput(object $entity): VenueClosureResource
    {
        return VenueClosureResource::fromEntity($entity);
    }
}
