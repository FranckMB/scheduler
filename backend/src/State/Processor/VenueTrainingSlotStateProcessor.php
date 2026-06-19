<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\VenueTrainingSlotResource;
use App\Dto\VenueTrainingSlotInput;
use App\Entity\VenueTrainingSlot;
use DateTimeImmutable;

/**
 * @extends AbstractStateProcessor<VenueTrainingSlot, VenueTrainingSlotInput, VenueTrainingSlotResource>
 */
class VenueTrainingSlotStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return VenueTrainingSlot::class;
    }

    /**
     * @param VenueTrainingSlotInput $input
     */
    protected function createEntityFromInput(object $input): VenueTrainingSlot
    {
        $entity = new VenueTrainingSlot;
        if (null !== $input->venueId) {
            $entity->setVenueId($input->venueId);
        }
        if (null !== $input->dayOfWeek) {
            $entity->setDayOfWeek($input->dayOfWeek);
        }
        if (null !== $input->startTime) {
            $entity->setStartTime(new DateTimeImmutable($input->startTime));
        }
        if (null !== $input->durationMinutes) {
            $entity->setDurationMinutes($input->durationMinutes);
        }
        if (null !== $input->capacity) {
            $entity->setCapacity($input->capacity);
        }

        return $entity;
    }

    /**
     * @param VenueTrainingSlot $entity
     * @param VenueTrainingSlotInput $input
     */
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
        if (null !== $input->durationMinutes) {
            $entity->setDurationMinutes($input->durationMinutes);
        }
        if (null !== $input->capacity) {
            $entity->setCapacity($input->capacity);
        }
    }

    /**
     * @param VenueTrainingSlot $entity
     */
    protected function mapEntityToOutput(object $entity): VenueTrainingSlotResource
    {
        return VenueTrainingSlotResource::fromEntity($entity);
    }
}
