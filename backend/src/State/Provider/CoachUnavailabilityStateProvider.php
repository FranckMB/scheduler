<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\CoachUnavailabilityResource;
use App\Entity\CoachUnavailability;

/**
 * @extends AbstractStateProvider<CoachUnavailability, CoachUnavailabilityResource>
 */
class CoachUnavailabilityStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return CoachUnavailability::class;
    }

    /**
     * @param CoachUnavailability $entity
     */
    protected function mapEntityToOutput(object $entity): CoachUnavailabilityResource
    {
        return CoachUnavailabilityResource::fromEntity($entity);
    }
}
