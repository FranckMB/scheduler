<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\TeamConstraintResource;
use App\Dto\TeamConstraintInput;
use App\Entity\TeamConstraint;

/**
 * @extends AbstractStateProcessor<TeamConstraint, TeamConstraintInput, TeamConstraintResource>
 */
class TeamConstraintStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return TeamConstraint::class;
    }

    /**
     * @param TeamConstraintInput $input
     */
    protected function createEntityFromInput(object $input): TeamConstraint
    {
        $entity = new TeamConstraint();
        if (null !== $input->teamId) {
            $entity->setTeamId($input->teamId);
        }
        if (null !== $input->type) {
            $entity->setType($input->type);
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
        if (null !== $input->venueId) {
            $entity->setVenueId($input->venueId);
        }
        if (null !== $input->reason) {
            $entity->setReason($input->reason);
        }
        if (null !== $input->createdBy) {
            $entity->setCreatedBy($input->createdBy);
        }
        if (null !== $input->sourceOccurrenceId) {
            $entity->setSourceOccurrenceId($input->sourceOccurrenceId);
        }
        if (null !== $input->severity) {
            $entity->setSeverity($input->severity);
        }

        return $entity;
    }

    /**
     * @param TeamConstraint      $entity
     * @param TeamConstraintInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        if (null !== $input->teamId) {
            $entity->setTeamId($input->teamId);
        }
        if (null !== $input->type) {
            $entity->setType($input->type);
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
        if (null !== $input->venueId) {
            $entity->setVenueId($input->venueId);
        }
        if (null !== $input->reason) {
            $entity->setReason($input->reason);
        }
        if (null !== $input->createdBy) {
            $entity->setCreatedBy($input->createdBy);
        }
        if (null !== $input->sourceOccurrenceId) {
            $entity->setSourceOccurrenceId($input->sourceOccurrenceId);
        }
        if (null !== $input->severity) {
            $entity->setSeverity($input->severity);
        }
    }

    /**
     * @param TeamConstraint $entity
     */
    protected function mapEntityToOutput(object $entity): TeamConstraintResource
    {
        return TeamConstraintResource::fromEntity($entity);
    }
}
