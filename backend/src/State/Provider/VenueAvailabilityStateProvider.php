<?php
declare(strict_types=1);
namespace App\State\Provider;

use App\ApiResource\VenueAvailabilityResource;
use App\Entity\VenueAvailability;

/**
 * @extends AbstractStateProvider<VenueAvailability, VenueAvailabilityResource>
 */
class VenueAvailabilityStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return VenueAvailability::class;
    }

    protected function mapEntityToOutput(object $entity): VenueAvailabilityResource
    {
        return VenueAvailabilityResource::fromEntity($entity);
    }
}
