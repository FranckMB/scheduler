<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\CoachUnavailabilityResource;
use App\Dto\CoachUnavailabilityInput;
use App\Entity\CoachUnavailability;

/**
 * @extends AbstractStateProcessor<CoachUnavailability, CoachUnavailabilityInput, CoachUnavailabilityResource>
 */
class CoachUnavailabilityStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return CoachUnavailability::class;
    }

    /**
     * @param CoachUnavailabilityInput $input
     */
    protected function createEntityFromInput(object $input): CoachUnavailability
    {
        $entity = new CoachUnavailability();
        if (null !== $input->coachId) {
            $entity->setCoachId($input->coachId);
        }
        if (null !== $input->dayOfWeek) {
            $entity->setDayOfWeek($input->dayOfWeek);
        }
        if (null !== $input->startTime) {
            $entity->setStartTime($input->startTime);
        }
        if (null !== $input->endTime) {
            $entity->setEndTime($input->endTime);
        }

        return $entity;
    }

    /**
     * @param CoachUnavailability      $entity
     * @param CoachUnavailabilityInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        if (null !== $input->coachId) {
            $entity->setCoachId($input->coachId);
        }
        if (null !== $input->dayOfWeek) {
            $entity->setDayOfWeek($input->dayOfWeek);
        }
        if (null !== $input->startTime) {
            $entity->setStartTime($input->startTime);
        }
        if (null !== $input->endTime) {
            $entity->setEndTime($input->endTime);
        }
    }

    /**
     * @param CoachUnavailability $entity
     */
    protected function mapEntityToOutput(object $entity): CoachUnavailabilityResource
    {
        return CoachUnavailabilityResource::fromEntity($entity);
    }
}
