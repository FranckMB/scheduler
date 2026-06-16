<?php
declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\VenueAvailabilityResource;
use App\Dto\VenueAvailabilityInput;
use App\Entity\VenueAvailability;
use DateTimeImmutable;

/**
 * @extends AbstractStateProcessor<VenueAvailability, VenueAvailabilityInput, VenueAvailabilityResource>
 */
class VenueAvailabilityStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return VenueAvailability::class;
    }

    protected function createEntityFromInput(object $input): VenueAvailability
    {
        $entity = new VenueAvailability;
        if (null !== $input->venueId) {
            $entity->setVenueId($input->venueId);
        }
        if (null !== $input->dayOfWeek) {
            $entity->setDayOfWeek($input->dayOfWeek);
        }
        if (null !== $input->startTime) {
            $entity->setStartTime(new DateTimeImmutable($input->startTime));
        }
        if (null !== $input->endTime) {
            $entity->setEndTime(new DateTimeImmutable($input->endTime));
        }

        return $entity;
    }

    protected function updateEntityFromInput(object $entity, object $input): void
    {
        if (null !== $input->venueId) {
            $entity->setVenueId($input->venueId);
        }
        if (null !== $input->dayOfWeek) {
            $entity->setDayOfWeek($input->dayOfWeek);
        }
        if (null !== $input->startTime) {
            $entity->setStartTime(new DateTimeImmutable($input->startTime));
        }
        if (null !== $input->endTime) {
            $entity->setEndTime(new DateTimeImmutable($input->endTime));
        }
    }

    protected function mapEntityToOutput(object $entity): VenueAvailabilityResource
    {
        return VenueAvailabilityResource::fromEntity($entity);
    }
}
