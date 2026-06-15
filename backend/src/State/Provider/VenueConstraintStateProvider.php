<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\VenueConstraintResource;
use App\Entity\VenueConstraint;

/**
 * @extends AbstractStateProvider<VenueConstraint, VenueConstraintResource>
 */
class VenueConstraintStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return VenueConstraint::class;
    }

    /**
     * @param VenueConstraint $entity
     */
    protected function mapEntityToOutput(object $entity): VenueConstraintResource
    {
        return VenueConstraintResource::fromEntity($entity);
    }
}
