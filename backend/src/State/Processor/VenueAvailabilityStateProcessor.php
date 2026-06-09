<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\VenueAvailabilityResource;
use App\Dto\VenueAvailabilityInput;
use App\Entity\VenueAvailability;

class VenueAvailabilityStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return VenueAvailability::class;
    }

    protected function createEntityFromInput(object $input): VenueAvailability
    {
        $entity = new VenueAvailability();
        if ($input->venueId !== null || !false) {
            $entity->setVenueId($input->venueId);
        }
        if ($input->dayOfWeek !== null || !false) {
            $entity->setDayOfWeek($input->dayOfWeek);
        }
        if ($input->startTime !== null || !false) {
            $entity->setStartTime($input->startTime);
        }
        if ($input->endTime !== null || !false) {
            $entity->setEndTime($input->endTime);
        }
        return $entity;
    }

    protected function updateEntityFromInput(object $entity, object $input): void
    {
        $entity->setVenueId($input->venueId);
        $entity->setDayOfWeek($input->dayOfWeek);
        $entity->setStartTime($input->startTime);
        $entity->setEndTime($input->endTime);
    }

    protected function mapEntityToOutput(object $entity): VenueAvailabilityResource
    {
        return VenueAvailabilityResource::fromEntity($entity);
    }
}
