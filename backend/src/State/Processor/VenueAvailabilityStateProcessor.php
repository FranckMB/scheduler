<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\VenueAvailabilityResource;
use App\Dto\VenueAvailabilityInput;
use App\Entity\VenueAvailability;

/**
 * @extends AbstractStateProcessor<VenueAvailability, VenueAvailabilityInput, VenueAvailabilityResource>
 */
class VenueAvailabilityStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return VenueAvailability::class;
    }

    /**
     * @param VenueAvailabilityInput $input
     */
    protected function createEntityFromInput(object $input): VenueAvailability
    {
        $entity = new VenueAvailability();
        if (null !== $input->venueId) {
            $entity->setVenueId($input->venueId);
        }
        if (null !== $input->dayOfWeek) {
            $entity->setDayOfWeek($input->dayOfWeek);
        }
        $entity->setStartTime($input->startTime);
        $entity->setEndTime($input->endTime);

        return $entity;
    }

    /**
     * @param VenueAvailability      $entity
     * @param VenueAvailabilityInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        if (null !== $input->venueId) {
            $entity->setVenueId($input->venueId);
        }
        if (null !== $input->dayOfWeek) {
            $entity->setDayOfWeek($input->dayOfWeek);
        }
        $entity->setStartTime($input->startTime);
        $entity->setEndTime($input->endTime);
    }

    /**
     * @param VenueAvailability $entity
     */
    protected function mapEntityToOutput(object $entity): VenueAvailabilityResource
    {
        return VenueAvailabilityResource::fromEntity($entity);
    }
}
