<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\CoachUnavailabilityResource;
use App\Entity\CoachUnavailability;

class CoachUnavailabilityStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return CoachUnavailability::class;
    }

    protected function mapEntityToOutput(object $entity): CoachUnavailabilityResource
    {
        return CoachUnavailabilityResource::fromEntity($entity);
    }
}
